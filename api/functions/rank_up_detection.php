<?php
declare(strict_types=1);

/**
 * /functions/rank_up_detection.php
 *
 * Detects rank-up eligibility after a member caps (or is already capped for the current week).
 * Inserts a synthetic marker activity (dedupe by hash) and, if Discord is configured, pings admins.
 */

require_once __DIR__ . '/discord.php';

/**
 * Normalise rank strings for loose matching (case-insensitive, collapses whitespace,
 * strips most punctuation/emoji).
 */
function rm_rank_norm(string $s): string {
    $s = trim($s);
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    // Replace any non letter/number/space with space (removes emoji/punctuation)
    $s = preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $s) ?? $s;
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    $s = trim($s);
    return mb_strtolower($s, 'UTF-8');
}


function detect_and_notify_rank_up(
    PDO $pdo,
    array $clan,
    array $member,
    string $capWeekStartUtc,
    array $rankOrder,
    string $botToken
): void {

    // Resolve the member's current rank from common column names.
    $currentRank = (string)($member['rank_name'] ?? ($member['rank'] ?? ($member['clan_rank'] ?? '')));
    $currentRank = trim($currentRank);
    if ($currentRank === '') {
        return;
    }

    // Normalise rank order (trim, keep original)
    $cleanRankOrder = [];
    foreach ($rankOrder as $r) {
        $r = trim((string)$r);
        if ($r !== '') $cleanRankOrder[] = $r;
    }
    if (!$cleanRankOrder) {
        return;
    }

    // Case-insensitive rank index map
    $rankIndex = [];
    foreach ($cleanRankOrder as $i => $r) {
        $rankIndex[rm_rank_norm($r)] = $i;
    }

    $currentKey = rm_rank_norm($currentRank);
    if (!isset($rankIndex[$currentKey])) {
        return;
    }
    $currentIdx = (int)$rankIndex[$currentKey];

    // Max rank field can vary across deployments; support common names.
    $maxRank = (string)(
        $clan['max_rank_by_capping'] ??
        $clan['max_rank_for_capping'] ??
        $clan['max_rank'] ??
        ''
    );
    $maxRank = trim($maxRank);

    // If max rank isn't configured (or doesn't match), assume the last rank in the order.
    $maxIdx = count($cleanRankOrder) - 1;
    if ($maxRank !== '') {
        $maxKey = rm_rank_norm($maxRank);
        if (isset($rankIndex[$maxKey])) {
            $maxIdx = (int)$rankIndex[$maxKey];
        }
    }

    // Already at or above max rank
    if ($currentIdx >= $maxIdx) {
        return;
    }

    // Next rank is the immediate promotion
    $newIdx = $currentIdx + 1;
    if (!isset($cleanRankOrder[$newIdx])) {
        return;
    }
    $newRank = $cleanRankOrder[$newIdx];

    // Prevent duplicate notifications per cap week
    $hash = hash('sha256', (string)$member['id'] . '|' . $capWeekStartUtc . '|' . $currentRank . '>' . $newRank);

    $stCheck = $pdo->prepare("
        SELECT id FROM member_activities
        WHERE member_id = :member_id
          AND activity_hash = :hash
        LIMIT 1
    ");
    $stCheck->execute([
        ':member_id' => (int)$member['id'],
        ':hash' => $hash,
    ]);
    if ($stCheck->fetch()) {
        return;
    }

    // Insert synthetic activity marker (always, even if Discord isn't configured)
    $stInsert = $pdo->prepare("
        INSERT INTO member_activities
          (member_id, member_clan_id, activity_hash, activity_date_utc, activity_text, activity_details, is_announced, created_at)
        VALUES
          (:member_id, :clan_id, :hash, UTC_TIMESTAMP(3), :text, :details, 1, UTC_TIMESTAMP(3))
    ");

    $text = 'Rank-up required';
    $details = sprintf(
        '%s capped and qualifies for promotion: %s → %s',
        (string)($member['rsn'] ?? 'Unknown'),
        $currentRank,
        $newRank
    );

    $stInsert->execute([
        ':member_id' => (int)$member['id'],
        ':clan_id' => (int)($clan['id'] ?? 0),
        ':hash' => $hash,
        ':text' => $text,
        ':details' => $details,
    ]);

    // Normalise bot token (some configs include the "Bot " prefix).
    $botToken = trim($botToken);
    if (stripos($botToken, 'Bot ') === 0) {
        $botToken = trim(substr($botToken, 4));
    }

    // If Discord isn't configured, stop here (marker is still inserted).
    $channelId = trim((string)($clan['discord_ping_channel_id'] ?? ''));
    if ($channelId === '' || trim($botToken) === '') {
        return;
    }

    // Build admin mention string (supports JSON array or comma-separated fallback)
    $roleIds = [];
    if (!empty($clan['discord_ping_role_ids_json'])) {
        $decoded = json_decode((string)$clan['discord_ping_role_ids_json'], true);
        if (is_array($decoded)) {
            $roleIds = array_values(array_filter(array_map('strval', $decoded)));
        }
    } elseif (!empty($clan['discord_ping_role_ids'])) {
        $roleIds = array_values(array_filter(array_map('trim', explode(',', (string)$clan['discord_ping_role_ids']))));
    }

    $mentions = '';
    if ($roleIds) {
        $mentions = implode(' ', array_map(fn($r) => '<@&' . $r . '>', $roleIds)) . "\n";
    }

    $message =
        $mentions .
        "🚨 Rank-up required\n\n" .
        "{$member['rsn']} has capped this week and qualifies for a promotion.\n\n" .
        "Current rank: {$currentRank}\n" .
        "New rank: {$newRank}\n\n" .
        "Please update their rank when ready.";

    $sendRes = discord_send_message($botToken, $channelId, $message);

    if (empty($sendRes['ok'])) {
        $err = (string)($sendRes['error'] ?? 'Unknown Discord send failure');
        $status = isset($sendRes['status']) && $sendRes['status'] !== null ? (string)$sendRes['status'] : '';
        error_log('[rank_up_detection] Discord send failed' . ($status !== '' ? " (HTTP {$status})" : '') . ": {$err}");

        // Append the error to the marker activity so it’s visible in your UI/logs.
        try {
            $stUpd = $pdo->prepare("
                UPDATE member_activities
                SET activity_details = CONCAT(activity_details, ' | Discord send failed', :extra)
                WHERE member_id = :member_id
                  AND activity_hash = :hash
                LIMIT 1
            ");
            $extra = '';
            if ($status !== '') $extra .= " (HTTP {$status})";
            if ($err !== '') $extra .= ": " . $err;
            $stUpd->execute([
                ':extra' => $extra,
                ':member_id' => (int)$member['id'],
                ':hash' => $hash,
            ]);
        } catch (Throwable $e) {
            // ignore
        }
    }
}
