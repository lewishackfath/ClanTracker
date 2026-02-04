<?php
declare(strict_types=1);

/**
 * /functions/rank_up_detection.php
 *
 * Detects rank-up eligibility after a member caps.
 *
 * Enhancements merged from API version:
 * - Cap-week guard: only process rank-up logic once per member per cap week
 *   (uses 'Rank-up required' OR 'Rank-up processed' markers).
 * - RuneScape roster reconciliation (members_lite):
 *   - If the player is already promoted in-game, update members.rank_name and do NOT ping.
 *   - Otherwise insert 'Rank-up required' + ping (deduped).
 * - Inserts a weekly 'Rank-up processed' marker to prevent repeat work.
 */

require_once __DIR__ . '/discord.php';

/* -------------------- helpers -------------------- */

/**
 * Parse a UTC datetime string that may be in 'Y-m-d H:i:s.v' or 'Y-m-d H:i:s' format.
 */
function rm_parse_dt_utc(string $dt): DateTimeImmutable
{
    $dt = trim($dt);
    $tz = new DateTimeZone('UTC');
    if ($dt === '') return new DateTimeImmutable('now', $tz);

    $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.v', $dt, $tz);
    if ($d instanceof DateTimeImmutable) return $d;

    $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dt, $tz);
    if ($d instanceof DateTimeImmutable) return $d;

    return new DateTimeImmutable($dt, $tz);
}

function ru_normalise_rsn(string $rsn): string
{
    $rsn = trim($rsn);
    if ($rsn === '') return '';
    // RuneScape members_lite often uses underscores.
    $rsn = str_replace(' ', '_', $rsn);
    $rsn = preg_replace('/[\p{Cc}\p{Cf}]/u', '', $rsn) ?? $rsn;
    return strtolower($rsn);
}

function ru_http_get(string $url, int $timeoutSec = 25): string
{
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
 * Cached in-process (2 minutes) to avoid repeated HTTP calls.
 */
function ru_fetch_clan_ranks(string $clanName): array
{
    static $cache = []; // [lower_clan_name => ['at'=>time(), 'map'=>array]]

    $key = strtolower(trim($clanName));
    if ($key === '') return [];

    if (isset($cache[$key]) && is_array($cache[$key]) && (time() - (int)($cache[$key]['at'] ?? 0) < 120)) {
        return (array)($cache[$key]['map'] ?? []);
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

        $rawRsn  = (string)($row[0] ?? '');
        $rawRank = (string)($row[1] ?? '');

        $rsnNorm = ru_normalise_rsn($rawRsn);
        if ($rsnNorm === '') continue;

        $map[$rsnNorm] = trim($rawRank);
    }

    $cache[$key] = ['at' => time(), 'map' => $map];
    return $map;
}

/**
 * Get clan name from DB by clan_id (cached).
 */
function ru_get_clan_name_from_db(PDO $pdo, int $clanId): string
{
    static $cache = [];
    if ($clanId <= 0) return '';
    if (isset($cache[$clanId])) return (string)$cache[$clanId];

    try {
        $st = $pdo->prepare('SELECT name FROM clans WHERE id = :id LIMIT 1');
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
function ru_normalise_rank_label(string $rank): string
{
    $rank = trim($rank);
    if ($rank === '') return '';
    // Remove emoji/symbols; keep letters, numbers, spaces, apostrophes.
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

    if ($memberId <= 0 || $clanId <= 0 || trim($rsn) === '') return;

    // Determine clan name (from $clan or DB)
    $clanName = (string)($clan['name'] ?? '');
    if (trim($clanName) === '') {
        $clanName = ru_get_clan_name_from_db($pdo, $clanId);
    }

    /* ---------- cap-week window normalisation (seconds precision) ---------- */
    $weekStart = substr(trim($capWeekStartUtc), 0, 19); // "Y-m-d H:i:s"
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $weekStart)) {
        // Fallback to parsing known formats
        $weekStart = rm_parse_dt_utc($capWeekStartUtc)->format('Y-m-d H:i:s');
    }
    $weekEnd = gmdate('Y-m-d H:i:s', strtotime($weekStart . ' +7 days'));

    /* ---------- weekly guard ---------- */
    // If we've already processed rank-up logic for this member in this cap week,
    // do not run again (prevents multi-pings/multi-promotions this week).
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
    if ($stGuard->fetch()) return;

    /* ---------- rank order mapping ---------- */
    $cleanRankOrder = [];
    foreach ($rankOrder as $r) {
        $r = trim((string)$r);
        if ($r !== '') $cleanRankOrder[] = $r;
    }
    if (!$cleanRankOrder) return;

    $rankIndex = [];
    foreach ($cleanRankOrder as $i => $r) {
        $rankIndex[ru_normalise_rank_label($r)] = (int)$i;
    }
    if (!$rankIndex) return;

    $currentRankRaw = (string)($member['rank_name'] ?? ($member['rank'] ?? ($member['clan_rank'] ?? '')));
    $currentRankRaw = trim($currentRankRaw);
    $currentRankKey = ru_normalise_rank_label($currentRankRaw);
    if ($currentRankKey === '' || !isset($rankIndex[$currentRankKey])) return;

    $currentIdx = (int)$rankIndex[$currentRankKey];

    // Max rank field can vary across deployments; support common names.
    $maxRankRaw = (string)(
        $clan['max_rank_by_capping'] ??
        $clan['max_rank_for_capping'] ??
        $clan['max_rank'] ??
        ''
    );
    $maxRankRaw = trim($maxRankRaw);

    // If max rank isn't configured (or doesn't match), assume the last rank in the order.
    $maxIdx = count($cleanRankOrder) - 1;
    if ($maxRankRaw !== '') {
        $maxKey = ru_normalise_rank_label($maxRankRaw);
        if ($maxKey !== '' && isset($rankIndex[$maxKey])) {
            $maxIdx = (int)$rankIndex[$maxKey];
        }
    }

    if ($currentIdx >= $maxIdx) return;

    $newIdx = $currentIdx + 1;
    if (!isset($cleanRankOrder[$newIdx])) return;

    $newRank    = (string)$cleanRankOrder[$newIdx];
    $newRankKey = ru_normalise_rank_label($newRank);
    if ($newRankKey === '' || !isset($rankIndex[$newRankKey])) return;

    /* ---------- roster reconcile (if already promoted, update + mark processed, no ping) ---------- */
    if (trim($clanName) !== '') {
        try {
            $roster = ru_fetch_clan_ranks($clanName);
            $rsnNorm = ru_normalise_rsn($rsn);

            if ($rsnNorm !== '' && isset($roster[$rsnNorm])) {
                $rsRankRaw = (string)$roster[$rsnNorm];
                $rsRankKey = ru_normalise_rank_label($rsRankRaw);

                if ($rsRankKey !== '' && isset($rankIndex[$rsRankKey])) {
                    $rsIdx = (int)$rankIndex[$rsRankKey];

                    if ($rsIdx >= $newIdx) {
                        // Update DB rank_name if different
                        if (ru_normalise_rank_label($currentRankRaw) !== $rsRankKey) {
                            $st = $pdo->prepare('UPDATE members SET rank_name = :rank_name WHERE id = :id LIMIT 1');
                            $st->execute([
                                ':rank_name' => $rsRankRaw,
                                ':id' => $memberId,
                            ]);
                        }

                        // Mark processed for this cap week
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
                            ':details' => "{$rsn} rank-up check complete for this cap week (already promoted in-game).",
                        ]);

                        return;
                    }
                }
            }
        } catch (Throwable $e) {
            // Roster fetch failing should not block rank-up alerts.
        }
    }

    /* ---------- dedupe (cap-week window + hash) ---------- */

    // 1) Window-based check (robust even if hash format ever changes)
    $stWin = $pdo->prepare("
        SELECT id FROM member_activities
        WHERE member_id = :member_id
          AND activity_text = 'Rank-up required'
          AND activity_date_utc >= :ws
          AND activity_date_utc < :we
          AND activity_details LIKE :needle
        ORDER BY id DESC
        LIMIT 1
    ");
    $needle = '%' . $currentRankRaw . ' → ' . $newRank . '%';
    $stWin->execute([
        ':member_id' => $memberId,
        ':ws' => $weekStart,
        ':we' => $weekEnd,
        ':needle' => $needle,
    ]);
    if ($stWin->fetch()) return;

    // 2) Hash-based check
    $hash = hash('sha256', $memberId . '|' . $weekStart . '|' . $currentRankKey . '>' . $newRankKey);

    $stCheck = $pdo->prepare("
        SELECT id FROM member_activities
        WHERE member_id = :member_id
          AND activity_hash = :hash
        LIMIT 1
    ");
    $stCheck->execute([
        ':member_id' => $memberId,
        ':hash' => $hash,
    ]);
    if ($stCheck->fetch()) return;

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

    // Also insert a weekly "processed" marker so we don't run again this cap week.
    $processedHash = hash('sha256', $memberId . '|' . $weekStart . '|processed');
    try {
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
    } catch (Throwable $e) {
        // ignore
    }

    /* ---------- Discord ping ---------- */

    // Normalise bot token (some configs include the "Bot " prefix).
    $botToken = trim($botToken);
    if (stripos($botToken, 'Bot ') === 0) {
        $botToken = trim(substr($botToken, 4));
    }

    $channelId = trim((string)($clan['discord_ping_channel_id'] ?? ''));
    if ($channelId === '' || $botToken === '') {
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
        "{$rsn} has capped this week and qualifies for a promotion.\n\n" .
        "Current rank: {$currentRankRaw}\n" .
        "New rank: {$newRank}\n\n" .
        "If they've already been promoted in-game, please ignore (their rank will sync on the next roster sync).";

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
            if ($err !== '') $extra .= ': ' . $err;
            $stUpd->execute([
                ':extra' => $extra,
                ':member_id' => $memberId,
                ':hash' => $hash,
            ]);
        } catch (Throwable $e) {
            // ignore
        }
    }
}
