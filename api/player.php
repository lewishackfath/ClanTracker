<?php
declare(strict_types=1);

require_once __DIR__ . '/_db.php';

/**
 * api/player.php (NEW SCHEMA)
 *
 * Uses tables:
 * - clans: id, name, timezone, reset_weekday, reset_time, is_enabled, inactive_at
 * - members: id, clan_id, rsn, rsn_normalised, rank_name, is_active, updated_at
 * - member_caps: clan_id, member_id, cap_week_start_utc, capped_at_utc, ...
 * - member_citadel_visits: clan_id, member_id, cap_week_start_utc, visited_at_utc, ...
 * - member_activities: activity_text/activity_details/... (this is the activity log)
 * - member_xp_snapshots: member_id, total_xp, skills_json, snapshot_hash, captured_at_utc
 * - member_poll_state: last_poll_at_utc
 */

function tracker_get_param(string $key, int $maxLen = 64): string {
    $v = (string)($_GET[$key] ?? '');
    $v = trim($v);
    if ($v === '') return '';
    if (mb_strlen($v) > $maxLen) $v = mb_substr($v, 0, $maxLen);
    return $v;
}

function tracker_week_window(array $clan): array {
    $tzName = (string)($clan['timezone'] ?? 'UTC');
    try { $tz = new DateTimeZone($tzName); }
    catch (Throwable $e) { $tzName = 'UTC'; $tz = new DateTimeZone('UTC'); }

    $now = new DateTime('now', $tz);

    $resetWeekday = (int)($clan['reset_weekday'] ?? 1); // your DB appears to use 0-6 (Sun-Sat)
    $resetTimeRaw = (string)($clan['reset_time'] ?? '00:00:00');

    $parts = array_map('intval', explode(':', $resetTimeRaw));
    $h = $parts[0] ?? 0; $m = $parts[1] ?? 0; $s = $parts[2] ?? 0;

    $startLocal = null;

    for ($i = 0; $i <= 7; $i++) {
        $cand = clone $now;
        $cand->setTime($h, $m, $s);
        if ($i > 0) $cand->modify("-{$i} days");

        $match = ((int)$cand->format('w') === $resetWeekday);
        if ($match && $cand <= $now) { $startLocal = $cand; break; }
    }

    if (!$startLocal) {
        $startLocal = clone $now;
        $startLocal->setTime($h, $m, $s);
    }

    $endLocal = clone $startLocal;
    $endLocal->modify('+7 days');

    $startUtc = clone $startLocal; $startUtc->setTimezone(new DateTimeZone('UTC'));
    $endUtc   = clone $endLocal;   $endUtc->setTimezone(new DateTimeZone('UTC'));

    return [
        'timezone' => $tzName,
        'week_start_local' => $startLocal->format('Y-m-d H:i:s'),
        'week_end_local'   => $endLocal->format('Y-m-d H:i:s'),
        'week_start_utc'   => $startUtc->format('Y-m-d H:i:s'),
        'week_end_utc'     => $endUtc->format('Y-m-d H:i:s'),
    ];
}

function tracker_period_window(string $period, array $weekWindow): array {
    $period = strtolower(trim($period));
    if ($period === '') $period = '7d';

    // Clan timezone (falls back to UTC)
    $tzName = (string)($weekWindow['timezone'] ?? 'UTC');
    try { $clanTz = new DateTimeZone($tzName); }
    catch (Throwable $e) { $tzName = 'UTC'; $clanTz = new DateTimeZone('UTC'); }

    $nowUtc = new DateTime('now', new DateTimeZone('UTC'));
    $nowLocal = new DateTime('now', $clanTz);

    if ($period === 'thisweek') {
        return [
            'period' => 'thisweek',
            'start_utc' => $weekWindow['week_start_utc'],
            'end_utc' => $weekWindow['week_end_utc'],
        ];
    }

    if ($period === 'lastweek') {
        $start = new DateTime($weekWindow['week_start_utc'], new DateTimeZone('UTC'));
        $start->modify('-7 days');
        $end = new DateTime($weekWindow['week_end_utc'], new DateTimeZone('UTC'));
        $end->modify('-7 days');
        return [
            'period' => 'lastweek',
            'start_utc' => $start->format('Y-m-d H:i:s'),
            'end_utc' => $end->format('Y-m-d H:i:s'),
        ];
    }

    // Month windows are based on the clan's local timezone
    if ($period === 'thismonth') {
        $startLocal = new DateTime($nowLocal->format('Y-m-01 00:00:00'), $clanTz);
        $startUtc = clone $startLocal; $startUtc->setTimezone(new DateTimeZone('UTC'));
        return [
            'period' => 'thismonth',
            'start_utc' => $startUtc->format('Y-m-d H:i:s'),
            'end_utc' => $nowUtc->format('Y-m-d H:i:s'),
        ];
    }

    if ($period === 'lastmonth') {
        $startThisMonthLocal = new DateTime($nowLocal->format('Y-m-01 00:00:00'), $clanTz);
        $startLastMonthLocal = clone $startThisMonthLocal;
        $startLastMonthLocal->modify('-1 month');

        $startUtc = clone $startLastMonthLocal; $startUtc->setTimezone(new DateTimeZone('UTC'));
        $endUtc = clone $startThisMonthLocal; $endUtc->setTimezone(new DateTimeZone('UTC'));
        // make end inclusive for queries using <= :endUtc
        $endUtc->modify('-1 second');

        return [
            'period' => 'lastmonth',
            'start_utc' => $startUtc->format('Y-m-d H:i:s'),
            'end_utc' => $endUtc->format('Y-m-d H:i:s'),
        ];
    }

    // All-time: let the queries find the earliest snapshot automatically
    if ($period === 'alltime') {
        return [
            'period' => 'alltime',
            'start_utc' => '1970-01-01 00:00:00',
            'end_utc' => $nowUtc->format('Y-m-d H:i:s'),
        ];
    }

    $dur = null;
    if ($period === '24h') $dur = 'PT24H';
    elseif ($period === '7d') $dur = 'P7D';
    elseif ($period === '30d') $dur = 'P30D';
    elseif ($period === '90d') $dur = 'P90D';

    if ($dur) {
        $start = clone $nowUtc;
        $start->sub(new DateInterval($dur));
        return [
            'period' => $period,
            'start_utc' => $start->format('Y-m-d H:i:s'),
            'end_utc' => $nowUtc->format('Y-m-d H:i:s'),
        ];
    }

    $start = clone $nowUtc;
    $start->sub(new DateInterval('P7D'));
    return [
        'period' => '7d',
        'start_utc' => $start->format('Y-m-d H:i:s'),
        'end_utc' => $nowUtc->format('Y-m-d H:i:s'),
    ];
}

function tracker_parse_skills_json($skillsJson): array {
    if ($skillsJson === null) return [];
    if (is_string($skillsJson)) {
        $decoded = json_decode($skillsJson, true);
        return is_array($decoded) ? $decoded : [];
    }
    return is_array($skillsJson) ? $skillsJson : [];
}

function tracker_extract_skill_stats(array $skills): array {
    $out = [];
    foreach ($skills as $skill => $row) {
        if (!is_array($row)) continue;
        $lvl = $row['level'] ?? null;
        $xp  = $row['xp'] ?? null;

        $out[(string)$skill] = [
            'level' => is_numeric($lvl) ? (int)$lvl : null,
            'xp'    => is_numeric($xp) ? (int)$xp : null,
        ];
    }
    return $out;
}

function tracker_skill_key(string $name): string {
    $s = mb_strtolower(trim($name));
    $s = preg_replace('/[^a-z0-9]+/u', '', $s) ?? $s;
    return $s;
}

function tracker_skill_order(): array {
    return [
        'Attack','Defence','Strength','Constitution','Ranged','Prayer','Magic',
        'Cooking','Woodcutting','Fletching','Fishing','Firemaking','Crafting','Smithing','Mining',
        'Herblore','Agility','Thieving','Slayer','Farming','Runecrafting','Hunter','Construction',
        'Summoning','Dungeoneering','Divination','Invention','Archaeology','Necromancy',
    ];
}

function tracker_dtmax(array $vals): ?string {
    $best = null;
    $bestTs = null;
    foreach ($vals as $v) {
        if (!$v) continue;
        $ts = strtotime((string)$v);
        if ($ts === false) continue;
        if ($bestTs === null || $ts > $bestTs) {
            $bestTs = $ts;
            $best = (string)$v;
        }
    }
    return $best;
}

function tracker_to_local(?string $utcDt, string $tzName): ?string {
    if (!$utcDt) return null;
    try {
        $dt = new DateTime($utcDt, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone($tzName));
        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

try {
    $player = tracker_get_param('player', 64);
    if ($player === '') tracker_json(['ok' => false, 'error' => 'Missing player'], 400);

    $period = tracker_get_param('period', 16);

    $pdo = tracker_pdo();

    $playerNorm = tracker_normalise($player);
    $playerNormNoSpaces = str_replace(' ', '', $playerNorm);
    $playerRawNoSpaces = str_replace(' ', '', $player);

    // Member lookup (NEW schema: members.clan_id -> clans.id)
    $stmt = $pdo->prepare("
        SELECT
          m.id,
          m.clan_id,
          m.rsn,
          m.rsn_normalised,
          m.rank_name,
          m.is_active,
          m.is_private,
          m.private_since_utc,
          m.updated_at,
          c.name AS clan_name,
          c.timezone,
          c.reset_weekday,
          c.reset_time
        FROM members m
        JOIN clans c ON c.id = m.clan_id
        WHERE
          c.is_enabled = 1
          AND c.inactive_at IS NULL
          AND (
            m.rsn_normalised = :rn
            OR REPLACE(m.rsn_normalised, ' ', '') = :rnns
            OR m.rsn = :raw
            OR REPLACE(m.rsn, ' ', '') = :rawns
          )
        ORDER BY m.is_active DESC, m.updated_at DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':rn' => $playerNorm,
        ':rnns' => $playerNormNoSpaces,
        ':raw' => $player,
        ':rawns' => $playerRawNoSpaces,
    ]);
    $member = $stmt->fetch();
    if (!$member) tracker_json(['ok' => false, 'error' => 'Player not found'], 404);

    $week = tracker_week_window($member);
    $xpWindow = tracker_period_window($period, $week);

    $clanId = (int)$member['clan_id'];
    $memberId = (int)$member['id'];

    // Week cap (NEW schema: member_caps has NO activity_text/activity_details)
    $stmt = $pdo->prepare("
        SELECT capped_at_utc, rule_id
        FROM member_caps
        WHERE clan_id = :clan AND member_id = :mid AND cap_week_start_utc = :ws
        LIMIT 1
    ");
    $stmt->execute([':clan' => $clanId, ':mid' => $memberId, ':ws' => $week['week_start_utc']]);
    $capRow = $stmt->fetch() ?: null;

    // Week visit (NEW schema: member_citadel_visits has NO activity_text/activity_details)
    $stmt = $pdo->prepare("
        SELECT visited_at_utc, rule_id
        FROM member_citadel_visits
        WHERE clan_id = :clan AND member_id = :mid AND cap_week_start_utc = :ws
        LIMIT 1
    ");
    $stmt->execute([':clan' => $clanId, ':mid' => $memberId, ':ws' => $week['week_start_utc']]);
    $visitRow = $stmt->fetch() ?: null;

    // Recent activity (NEW schema: member_activities DOES have activity_text)
    $stmt = $pdo->prepare("
        SELECT activity_date_utc, activity_text, activity_details, announced_at
        FROM member_activities
        WHERE member_id = :mid
          AND activity_text <> 'Rank-up required'
        ORDER BY COALESCE(activity_date_utc, announced_at) DESC
        LIMIT 20;
    ");
    $stmt->execute([':mid' => $memberId]);
    $activityRows = $stmt->fetchAll() ?: [];

    $latestActivityUtc = null;
    if (!empty($activityRows)) {
        $latestActivityUtc = $activityRows[0]['activity_date_utc'] ?: ($activityRows[0]['announced_at'] ?? null);
    }

    // Latest XP snapshot (NEW schema: member_xp_snapshots by member_id)
    $stmt = $pdo->prepare("
        SELECT captured_at_utc, total_xp, skills_json
        FROM member_xp_snapshots
        WHERE member_id = :mid
        ORDER BY captured_at_utc DESC
        LIMIT 1
    ");
    $stmt->execute([':mid' => $memberId]);
    $latestSnap = $stmt->fetch() ?: null;
    $latestSnapUtc = $latestSnap['captured_at_utc'] ?? null;

    // Poll state (NEW schema: last_poll_at_utc)
    $stmt = $pdo->prepare("
        SELECT last_poll_at_utc
        FROM member_poll_state
        WHERE member_id = :mid
        LIMIT 1
    ");
    $stmt->execute([':mid' => $memberId]);
    $lastPolledUtc = $stmt->fetchColumn();
    if ($lastPolledUtc === false) $lastPolledUtc = null;

    // Period snapshots for XP gained (member_xp_snapshots)
    $stmt = $pdo->prepare("
        SELECT captured_at_utc, total_xp, skills_json
        FROM member_xp_snapshots
        WHERE member_id = :mid AND captured_at_utc <= :endUtc
        ORDER BY captured_at_utc DESC
        LIMIT 1
    ");
    $stmt->execute([':mid' => $memberId, ':endUtc' => $xpWindow['end_utc']]);
    $endSnap = $stmt->fetch() ?: null;

    $stmt = $pdo->prepare("
        SELECT captured_at_utc, total_xp, skills_json
        FROM member_xp_snapshots
        WHERE member_id = :mid AND captured_at_utc >= :startUtc
        ORDER BY captured_at_utc ASC
        LIMIT 1
    ");
    $stmt->execute([':mid' => $memberId, ':startUtc' => $xpWindow['start_utc']]);
    $startSnap = $stmt->fetch() ?: null;

    $xp = [
        'period' => $xpWindow['period'],
        'start_utc' => $xpWindow['start_utc'],
        'end_utc' => $xpWindow['end_utc'],
        'has_data' => false,
        'gained_total_xp' => null,
        'start_total_xp' => null,
        'end_total_xp' => null,
        'top_skills' => [],
    ];

    if ($startSnap && $endSnap) {
        $startTotal = (int)$startSnap['total_xp'];
        $endTotal = (int)$endSnap['total_xp'];
        $gainTotal = $endTotal - $startTotal;
        if ($gainTotal < 0) $gainTotal = 0;

        $startSkills = tracker_extract_skill_stats(tracker_parse_skills_json($startSnap['skills_json']));
        $endSkills   = tracker_extract_skill_stats(tracker_parse_skills_json($endSnap['skills_json']));

        $diffs = [];
        foreach ($endSkills as $skill => $stRow) {
            $endXp = $stRow['xp'];
            $startXp = $startSkills[$skill]['xp'] ?? null;
            if ($endXp === null || $startXp === null) continue;
            $d = (int)$endXp - (int)$startXp;
            if ($d > 0) $diffs[$skill] = $d;
        }

        arsort($diffs);
        $top = [];
        foreach ($diffs as $skill => $d) {
            $top[] = [
                'skill' => $skill,
                'skill_key' => tracker_skill_key($skill),
                'gained_xp' => $d,
            ];
            if (count($top) >= 8) break;
        }

        $xp = [
            'period' => $xpWindow['period'],
            'start_utc' => $xpWindow['start_utc'],
            'end_utc' => $xpWindow['end_utc'],
            'has_data' => true,
            'gained_total_xp' => $gainTotal,
            'start_total_xp' => $startTotal,
            'end_total_xp' => $endTotal,
            'top_skills' => $top,
        ];
    }

    // Current skills list from latest snapshot
    $currentSkills = [
        'has_data' => false,
        'captured_at_utc' => null,
        'skills' => [],
    ];

    if ($latestSnap) {
        $rawSkills = tracker_parse_skills_json($latestSnap['skills_json']);
        $stats = tracker_extract_skill_stats($rawSkills);

        // Total (prefer skills_json.total, else compute)
        $totalLevel = null;
        $totalXp = isset($latestSnap['total_xp']) && is_numeric($latestSnap['total_xp']) ? (int)$latestSnap['total_xp'] : null;

        if (isset($rawSkills['total']) && is_array($rawSkills['total'])) {
            $tl = $rawSkills['total']['level'] ?? null;
            $tx = $rawSkills['total']['xp'] ?? null;
            if (is_numeric($tl)) $totalLevel = (int)$tl;
            if ($totalXp === null && is_numeric($tx)) $totalXp = (int)$tx;
        }

        if ($totalLevel === null) {
            $sum = 0;
            foreach (tracker_skill_order() as $sn) {
                $lvl = $stats[$sn]['level'] ?? null;
                if (is_numeric($lvl)) $sum += (int)$lvl;
            }
            $totalLevel = $sum;
        }

        $order = tracker_skill_order();
        $ordered = [];

        foreach ($order as $skillName) {
            $stRow = $stats[$skillName] ?? ['level' => null, 'xp' => null];
            $ordered[] = [
                'skill' => $skillName,
                'skill_key' => tracker_skill_key($skillName),
                'level' => $stRow['level'],
                'xp' => $stRow['xp'],
            ];
        }

        $currentSkills = [
            'has_data' => true,
            'captured_at_utc' => $latestSnap['captured_at_utc'],
            'skills' => $ordered,
            'total' => [
                'level' => $totalLevel,
                'xp' => $totalXp,
            ],
        ];
    }

    // Last pull (max of poll/snapshot/activity)
    $lastPullUtc = tracker_dtmax([$lastPolledUtc, $latestSnapUtc, $latestActivityUtc]);
    $lastPullLocal = tracker_to_local($lastPullUtc, (string)$week['timezone']);

    // Private profile flags
    $isPrivate = ((int)($member['is_private'] ?? 0) === 1);
    $privateSinceUtc = $member['private_since_utc'] ?? null;
    $privateSinceLocal = $privateSinceUtc ? tracker_to_local((string)$privateSinceUtc, (string)$week['timezone']) : null;

    tracker_json([
        'ok' => true,
        'member' => [
            'id' => $memberId,
            'rsn' => $member['rsn'],
            'rsn_normalised' => $member['rsn_normalised'],
            'rank_name' => $member['rank_name'],
            'is_active' => (int)$member['is_active'] === 1,
            'is_private' => $isPrivate,
            'private_since_utc' => $privateSinceUtc,
            'private_since_local' => $privateSinceLocal,
        ],
        'clan' => [
            'id' => $clanId,
            'name' => $member['clan_name'],
            'timezone' => $week['timezone'],
            'reset_weekday' => (int)$member['reset_weekday'],
            'reset_time' => $member['reset_time'],
        ],
        'week' => $week,
        'cap' => [
            'capped' => $capRow ? true : false,
            'capped_at_utc' => $capRow['capped_at_utc'] ?? null,
            'capped_at_local' => tracker_to_local($capRow['capped_at_utc'] ?? null, (string)$week['timezone']),
            'rule_id' => $capRow['rule_id'] ?? null,
        ],
        'visit' => [
            'visited' => $visitRow ? true : false,
            'visited_at_utc' => $visitRow['visited_at_utc'] ?? null,
            'visited_at_local' => tracker_to_local($visitRow['visited_at_utc'] ?? null, (string)$week['timezone']),
            'rule_id' => $visitRow['rule_id'] ?? null,
        ],
        'recent_activity' => array_map(static function(array $r) use ($week) {
            $actUtc = $r['activity_date_utc'] ?? null;
            $annUtc = $r['announced_at'] ?? null;

            return [
                'activity_date_utc' => $actUtc,
                'activity_date_local' => tracker_to_local($actUtc, (string)$week['timezone']),
                'announced_at_utc' => $annUtc,
                'announced_at_local' => tracker_to_local($annUtc, (string)$week['timezone']),
                'timezone' => $week['timezone'],
                'text' => $r['activity_text'] ?? null,
                'details' => $r['activity_details'] ?? null,
            ];
        }, $activityRows),
        'xp' => $xp,
        'xp_periods' => [
        ['value' => '24h', 'label' => 'Last 24 hours'],
        ['value' => '7d', 'label' => 'Last 7 days'],
        ['value' => '30d', 'label' => 'Last 30 days'],
        ['value' => '90d', 'label' => 'Last 90 days'],
        ['value' => 'thismonth', 'label' => 'This month'],
        ['value' => 'lastmonth', 'label' => 'Last month'],
        ['value' => 'alltime', 'label' => 'All time'],
        ['value' => 'thisweek', 'label' => 'This clan week'],
        ['value' => 'lastweek', 'label' => 'Last clan week'],
    ],
        'current_skills' => $currentSkills,
        'last_pull' => [
            'utc' => $lastPullUtc,
            'local' => $lastPullLocal,
            'timezone' => $week['timezone'],
            'sources' => [
                'last_poll_at_utc' => $lastPolledUtc ?: null,
                'last_xp_snapshot_utc' => $latestSnapUtc ?: null,
                'last_activity_utc' => $latestActivityUtc ?: null,
            ],
        ],
    ]);

} catch (Throwable $e) {
    tracker_json([
        'ok' => false,
        'error' => 'Player endpoint failed',
        'hint' => $e->getMessage(),
    ], 500);
}
