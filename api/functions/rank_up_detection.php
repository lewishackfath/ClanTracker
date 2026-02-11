<?php
declare(strict_types=1);

/**
 * /functions/rank_up_detection.php
 *
 * Detects rank-up eligibility after a member caps.
 *
 * NEW (anti-duplicate using DB-driven rank sync):
 * - Member ranks are synced to the DB by cron_sync_members.php.
 * - Before sending a ping, we check members.last_promotion_at_utc within the current cap-week.
 *   - If a promotion already happened this cap-week (even before cap was detected), we DO NOT ping.
 *   - Otherwise, we insert the synthetic marker + send the ping (deduped per cap-week + transition).
 */

require_once __DIR__ . '/discord.php';

/* -------------------- helpers -------------------- */

function ru_normalise_rsn(string $rsn): string {
    // Match the normalisation used in cron_sync_members.php
    $rsn = preg_replace('/^\xEF\xBB\xBF/', '', $rsn) ?? $rsn;
    $rsn = trim($rsn);
    if ($rsn === '') return '';

    // normalise weird spaces
    $rsn = str_replace("\xA0", ' ', $rsn); // NBSP -> space

    // remove zero-width / BOM / soft hyphen
    $tmp = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}\x{00AD}]/u', '', $rsn);
    if ($tmp !== null) $rsn = $tmp;

    // collapse whitespace
    $tmp = preg_replace('/\s+/u', ' ', $rsn);
    if ($tmp !== null) $rsn = $tmp;

    // keep RSN-safe-ish characters
    $tmp = preg_replace('/[^0-9A-Za-z _-]+/u', ' ', $rsn);
    if ($tmp !== null) $rsn = $tmp;
    $rsn = trim($rsn);

    // Unicode normalisation
    if (class_exists('Normalizer')) {
        $rsn = Normalizer::normalize($rsn, Normalizer::FORM_KC) ?: $rsn;
    }

    $rsn = mb_strtolower($rsn, 'UTF-8');
    $rsn = str_replace(' ', '_', $rsn);

    // strip control chars
    $tmp = preg_replace('/[\p{Cc}\p{Cf}]/u', '', $rsn);
    if ($tmp !== null) $rsn = $tmp;

    // collapse underscores + trim
    $tmp = preg_replace('/_+/u', '_', $rsn);
    if ($tmp !== null) $rsn = $tmp;
    $rsn = trim($rsn, '_');

    // enforce varchar(12)
    if (mb_strlen($rsn, 'UTF-8') > 12) {
        $rsn = mb_substr($rsn, 0, 12, 'UTF-8');
    }

    return $rsn;
}

function ru_http_get(string $url, int $timeoutSec = 25): string {
    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('curl_init failed');

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => $timeoutSec,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_USERAGENT => 'RS24K-Tracker/1.0 (rank-up)',
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) throw new RuntimeException("HTTP error: {$err}");
    if ($code < 200 || $code >= 300) throw new RuntimeException("HTTP status {$code}");

    return (string)$body;
}

/**
 * Fetch clan roster ranks from RuneScape members_lite.
 * Returns: [ normalised_rsn => rank_name ]
 *
 * Cached per-request per clan name to avoid repeated HTTP calls.
 */
function ru_fetch_clan_ranks(string $clanName): array {
    static $cache = []; // [lower_clan_name => ['at'=>time(), 'map'=>array]]

    $key = strtolower(trim($clanName));
    if ($key === '') return [];

    // 2-minute cache per request-run (covers multiple members in one cron pass).
    if (isset($cache[$key]) && is_array($cache[$key]) && (time() - (int)$cache[$key]['at'] < 120)) {
        return (array)$cache[$key]['map'];
    }

    $url = 'https://secure.runescape.com/m=clan-hiscores/members_lite.ws?clanName=' . rawurlencode($clanName);
    $csv = ru_http_get($url, 25);

    $lines = preg_split("/\r\n|\n|\r/", $csv) ?: [];
    $map = [];

    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') continue;

        // strip UTF-8 BOM
        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;

        $row = str_getcsv($line, ',', '"', '\\');
        if (!is_array($row) || count($row) < 2) continue;

        // Skip header rows
        $c0 = strtolower(trim((string)($row[0] ?? '')));
        $c1 = strtolower(trim((string)($row[1] ?? '')));
        if ($c0 === 'clanmate' || $c0 === 'name' || $c0 === 'rsn' || $c1 === 'clan rank' || $c1 === 'rank') {
            continue;
        }

        $rawRsn = (string)($row[0] ?? '');
        $rawRank = (string)($row[1] ?? '');

        $rsnNorm = ru_normalise_rsn($rawRsn);
        if ($rsnNorm === '') continue;

        $rankName = trim($rawRank);
        $map[$rsnNorm] = $rankName;
    }

    $cache[$key] = ['at' => time(), 'map' => $map];
    return $map;
}



/**
 * Get clan name from DB by clan_id (cached).
 */
function ru_get_clan_name_from_db(PDO $pdo, int $clanId): string {
    static $cache = [];
    if ($clanId <= 0) return '';
    if (isset($cache[$clanId])) return $cache[$clanId];
    try {
        $st = $pdo->prepare("SELECT name FROM clans WHERE id = :id LIMIT 1");
        $st->execute([':id' => $clanId]);
        $name = (string)($st->fetchColumn() ?: '');
        $cache[$clanId] = $name;
        return $name;
    } catch (Throwable $e) {
        $cache[$clanId] = '';
        return '';
    }
}

/**
 * Very light normalisation so "Recruit ⭐" matches "Recruit".
 */
function ru_normalise_rank_label(string $rank): string {
    $rank = trim($rank);
    if ($rank === '') return '';
    // Remove emoji / symbols; keep letters, numbers, spaces, apostrophes.
    $rank = preg_replace('/[^\p{L}\p{N}\'\s]/u', '', $rank) ?? $rank;
    $rank = preg_replace('/\s+/u', ' ', $rank) ?? $rank;
    return trim($rank);
}

/* -------------------- main -------------------- */

function detect_and_notify_rank_up(
    PDO $pdo,
    array $clan,
    array $member,
    string $capWeekStartUtc,
    array $rankOrder,
    string $botToken
): void {

    $memberId = (int)($member['id'] ?? 0);
    $clanId   = (int)($clan['id'] ?? 0);
    $rsn      = (string)($member['rsn'] ?? '');

    if ($memberId <= 0 || $clanId <= 0 || $rsn === '') return;

    /* ---------- cap-week window normalisation ---------- */
    // Normalise week start to second precision (stable string)
    $weekStart = substr(trim($capWeekStartUtc), 0, 19); // "Y-m-d H:i:s"
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $weekStart)) {
        $weekStart = gmdate('Y-m-d H:i:s');
    }
    $weekEnd = gmdate('Y-m-d H:i:s', strtotime($weekStart . ' +7 days'));

    /* ---------- weekly guard (prevents multi-pings / multi-promotions) ---------- */
    // If we've already processed rank-up logic for this member in this cap week (either required or processed),
    // do not run again.
    $stGuard = $pdo->prepare("
        SELECT id FROM member_activities
        WHERE member_id = :member_id
          AND activity_text IN ('Rank-up required', 'Rank-up processed')
          AND activity_date_utc >= :ws
          AND activity_date_utc < :we
        LIMIT 1
    ");
    $stGuard->execute([
        ':member_id' => $memberId,
        ':ws' => $weekStart,
        ':we' => $weekEnd,
    ]);
    if ($stGuard->fetch()) {
        return;
    }



    // Normalise rank order map (allow minor decorations in stored rank)
    $rankIndex = [];
    foreach ($rankOrder as $i => $r) {
        $rankIndex[ru_normalise_rank_label((string)$r)] = (int)$i;
    }
    if (!$rankIndex) return;

    $currentRankRaw = (string)($member['rank_name'] ?? '');
    $currentRankKey = ru_normalise_rank_label($currentRankRaw);
    if ($currentRankKey === '' || !isset($rankIndex[$currentRankKey])) return;

    $currentIdx = $rankIndex[$currentRankKey];

    $maxRankRaw = (string)($clan['max_rank_by_capping'] ?? '');
    $maxRankKey = ru_normalise_rank_label($maxRankRaw);
    if ($maxRankKey === '' || !isset($rankIndex[$maxRankKey])) return;

    $maxIdx = $rankIndex[$maxRankKey];
    if ($currentIdx >= $maxIdx) return;

    $newIdx = $currentIdx + 1;

    // Get canonical new rank label from rankOrder
    $newRank = (string)($rankOrder[$newIdx] ?? '');
    $newRankKey = ru_normalise_rank_label($newRank);
    if ($newRankKey === '' || !isset($rankIndex[$newRankKey])) return;
/* ---------- promotion guard (DB-driven) ---------- */
// If a promotion has already occurred within this cap-week window, we must not ping again,
// even if the cap is detected later (admin may have promoted early).
$lastPromoRaw = $member['last_promotion_at_utc'] ?? null;
if ($lastPromoRaw === null) {
    $stLP = $pdo->prepare("SELECT last_promotion_at_utc FROM members WHERE id = :id LIMIT 1");
    $stLP->execute([':id' => $memberId]);
    $rowLP = $stLP->fetch(PDO::FETCH_ASSOC);
    $lastPromoRaw = $rowLP['last_promotion_at_utc'] ?? null;
}
$lastPromo = $lastPromoRaw ? substr((string)$lastPromoRaw, 0, 19) : '';
if ($lastPromo !== '' && $lastPromo >= $weekStart && $lastPromo < $weekEnd) {
    // Mark as processed for this cap week (prevents any further rank-up checks this week).
    $processedHash = hash('sha256', $memberId . '|' . $weekStart . '|processed');
    $stProc = $pdo->prepare("
        INSERT IGNORE INTO member_activities
          (member_id, member_clan_id, activity_hash, activity_date_utc, activity_text, activity_details, is_announced, created_at)
        VALUES
          (:member_id, :clan_id, :hash, UTC_TIMESTAMP(3), 'Rank-up processed', :details, 1, UTC_TIMESTAMP(3))
    ");
    $stProc->execute([
        ':member_id' => $memberId,
        ':clan_id' => $clanId,
        ':hash' => $processedHash,
        ':details' => "{$rsn} rank-up not required this cap week (promotion already detected at {$lastPromoRaw}).",
    ]);
    return;
}

/* ---------- dedupe (cap-week window + hash) ---------- */

$detailsNeedle = ru_normalise_rank_label($currentRankRaw) . ' → ' . ru_normalise_rank_label($newRank);

    // 1) window-based dedupe
    $stCheckWindow = $pdo->prepare("
        SELECT id FROM member_activities
        WHERE member_id = :member_id
          AND activity_text = 'Rank-up required'
          AND activity_date_utc >= :ws
          AND activity_date_utc < :we
          AND (activity_details LIKE :like1 OR activity_details LIKE :like2)
        LIMIT 1
    ");
    $stCheckWindow->execute([
        ':member_id' => $memberId,
        ':ws' => $weekStart,
        ':we' => $weekEnd,
        ':like1' => '%' . $currentRankRaw . '%',
        ':like2' => '%' . $newRank . '%',
    ]);
    if ($stCheckWindow->fetch()) return;

    // 2) hash-based dedupe (unique key)
    $hash = hash('sha256', $memberId . '|' . $weekStart . '|' . $currentRankKey . '>' . $newRankKey);

    $stCheckHash = $pdo->prepare("
        SELECT id FROM member_activities
        WHERE member_id = :member_id
          AND activity_hash = :hash
        LIMIT 1
    ");
    $stCheckHash->execute([
        ':member_id' => $memberId,
        ':hash' => $hash,
    ]);
    if ($stCheckHash->fetch()) return;

    // Insert synthetic activity marker
    $stInsert = $pdo->prepare("
        INSERT INTO member_activities
          (member_id, member_clan_id, activity_hash, activity_date_utc, activity_text, activity_details, is_announced, created_at)
        VALUES
          (:member_id, :clan_id, :hash, UTC_TIMESTAMP(3), :text, :details, 1, UTC_TIMESTAMP(3))
    ");

    $text = 'Rank-up required';
    $details = sprintf(
        '%s capped and qualifies for promotion: %s → %s',
        $rsn,
        $currentRankRaw,
        $newRank
    );

    $stInsert->execute([
        ':member_id' => $memberId,
        ':clan_id' => $clanId,
        ':hash' => $hash,
        ':text' => $text,
        ':details' => $details,
    ]);

    
    // Also insert a weekly "processed" marker so we don't ping again this cap week.
    $processedHash = hash('sha256', $memberId . '|' . $weekStart . '|processed');
    $stProc = $pdo->prepare("
        INSERT IGNORE INTO member_activities
          (member_id, member_clan_id, activity_hash, activity_date_utc, activity_text, activity_details, is_announced, created_at)
        VALUES
          (:member_id, :clan_id, :hash, UTC_TIMESTAMP(3), 'Rank-up processed', :details, 1, UTC_TIMESTAMP(3))
    ");
    $stProc->execute([
        ':member_id' => $memberId,
        ':clan_id' => $clanId,
        ':hash' => $processedHash,
        ':details' => "{$rsn} rank-up check complete for this cap week (ping sent or pending).",
    ]);

// Build admin mention string
    $roleIds = [];
    if (!empty($clan['discord_ping_role_ids_json'])) {
        $decoded = json_decode((string)$clan['discord_ping_role_ids_json'], true);
        if (is_array($decoded)) {
            $roleIds = $decoded;
        }
    }

    $mentions = '';
    if ($roleIds) {
        $mentions = implode(' ', array_map(fn($r) => '<@&' . $r . '>', $roleIds)) . "\n";
    }

    $message =
        $mentions .
        "🚨 Rank-up required\n\n" .
        "{$rsn} has capped this week and qualifies for a promotion.\n\n" .
        "Current rank: {$currentRankRaw}\n" .
        "New rank: {$newRank}\n\n" .
        "If they've already been promoted in-game, please ignore (the tracker will sync the new rank on the next roster sync).";

    $channelId = (string)($clan['discord_ping_channel_id'] ?? '');
    if ($channelId === '' || trim($botToken) === '') {
        return;
    }

    $sendRes = discord_send_message($botToken, $channelId, $message);
    if (!is_array($sendRes) || !($sendRes['ok'] ?? false)) {
        $code = is_array($sendRes) ? (int)($sendRes['status'] ?? 0) : 0;
        $err  = is_array($sendRes) ? (string)($sendRes['error'] ?? '') : 'unknown';
        error_log("Rank-up Discord send failed (member_id={$memberId}, clan_id={$clanId}): HTTP {$code} {$err}");
    }
}

