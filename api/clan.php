<?php
declare(strict_types=1);

require_once __DIR__ . '/_db.php';

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

    $resetWeekday = (int)($clan['reset_weekday'] ?? 1);
    $resetTimeRaw = (string)($clan['reset_time'] ?? '00:00:00');

    $parts = array_map('intval', explode(':', $resetTimeRaw));
    $h = $parts[0] ?? 0; $m = $parts[1] ?? 0; $s = $parts[2] ?? 0;

    $startLocal = null;

    for ($i = 0; $i <= 7; $i++) {
        $cand = clone $now;
        $cand->setTime($h, $m, $s);
        if ($i > 0) $cand->modify("-{$i} days");

        $match = false;
        if ($resetWeekday >= 0 && $resetWeekday <= 6) {
            $match = ((int)$cand->format('w') === $resetWeekday); // Sun=0..Sat=6
        } else {
            $match = ((int)$cand->format('N') === $resetWeekday); // Mon=1..Sun=7
        }

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
    $nowUtc = new DateTime('now', new DateTimeZone('UTC'));
    $period = strtolower(trim($period));
    if ($period === '') $period = '7d';

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

function tracker_extract_skill_xp(array $skills): array {
    $out = [];
    foreach ($skills as $skill => $row) {
        if (!is_array($row)) continue;
        $xp = $row['xp'] ?? null;
        if (is_numeric($xp)) $out[(string)$skill] = (int)$xp;
    }
    return $out;
}

function tracker_skill_order(): array {
    return [
        'Attack','Defence','Strength','Constitution','Ranged','Prayer','Magic',
        'Cooking','Woodcutting','Fletching','Fishing','Firemaking','Crafting','Smithing','Mining',
        'Herblore','Agility','Thieving','Slayer','Farming','Runecrafting','Hunter','Construction',
        'Summoning','Dungeoneering','Divination','Invention','Archaeology','Necromancy',
    ];
}

function tracker_skill_key(string $name): string {
    $s = mb_strtolower(trim($name));
    $s = preg_replace('/[^a-z0-9]+/u', '', $s) ?? $s;
    return $s;
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

$clanKey = tracker_get_param('clan', 32);
if ($clanKey === '') tracker_json(['ok' => false, 'error' => 'Missing clan'], 400);

$period = tracker_get_param('period', 16);

$pdo = tracker_pdo();

$stmt = $pdo->prepare("
SELECT clan_key, clan_name, timezone, reset_weekday, reset_time, is_enabled
FROM clans
WHERE clan_key = :ck AND is_enabled = 1
LIMIT 1
");
$stmt->execute([':ck' => $clanKey]);
$clan = $stmt->fetch();
if (!$clan) tracker_json(['ok' => false, 'error' => 'Clan not found'], 404);

$week = tracker_week_window($clan);
$ws = $week['week_start_utc'];

$xpWindow = tracker_period_window($period, $week);

// Members list (active only for overview)
$stmt = $pdo->prepare("
SELECT
  m.rsn,
  m.rank_name,
  (mc.id IS NOT NULL) AS capped
FROM members m
LEFT JOIN member_caps mc
  ON mc.clan_key = m.clan_key
 AND mc.rsn_normalised = m.rsn_normalised
 AND mc.cap_week_start_utc = :ws
WHERE m.clan_key = :ck
  AND m.is_active = 1
ORDER BY m.rsn ASC
");
$stmt->execute([':ck' => $clanKey, ':ws' => $ws]);
$members = $stmt->fetchAll();

$active = count($members);
$capped = 0;
foreach ($members as $m) {
    if ((int)$m['capped'] === 1) $capped++;
}
$uncapped = $active - $capped;
$percent = $active > 0 ? (int)round(($capped / $active) * 100) : 0;

// Last data pull indicators
$stmt = $pdo->prepare("SELECT MAX(last_polled_at_utc) AS v FROM member_poll_state WHERE clan_key = :ck");
$stmt->execute([':ck' => $clanKey]);
$lastPolled = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT MAX(captured_at_utc) AS v FROM player_xp_snapshots WHERE clan_key = :ck");
$stmt->execute([':ck' => $clanKey]);
$lastSnapshot = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT MAX(last_member_sync_at) AS v FROM members WHERE clan_key = :ck");
$stmt->execute([':ck' => $clanKey]);
$lastSync = $stmt->fetchColumn();

$lastPullUtc = tracker_dtmax([$lastPolled, $lastSnapshot, $lastSync]);
$lastPullLocal = tracker_to_local($lastPullUtc, (string)$week['timezone']);

// --- Top clan XP earner per skill (within selected period) ---
$leaders = [];
foreach (tracker_skill_order() as $skill) {
    $leaders[$skill] = [
        'skill' => $skill,
        'skill_key' => tracker_skill_key($skill),
        'rsn' => null,
        'gained_xp' => 0,
        'has_data' => false,
    ];
}

// Grab one start and end snapshot per member for the selected period.
// Snapshots can be infrequent, so we prefer:
//   - start snapshot: latest snapshot at/before start (fallback to earliest after start)
//   - end snapshot:   latest snapshot at/before end
//
// We compute start_at/end_at per member via scalar subqueries (OK for ~hundreds of members)
// and then join back to snapshots to fetch skills_json.
$stmt = $pdo->prepare("
    SELECT
      m.rsn,
      m.rsn_normalised,
      s_start.skills_json AS start_skills_json,
      s_end.skills_json   AS end_skills_json
    FROM members m
    LEFT JOIN player_xp_snapshots s_start
      ON s_start.clan_key = :ck
     AND s_start.rsn_normalised = m.rsn_normalised
     AND s_start.captured_at_utc = COALESCE(
        (
          SELECT MAX(ps1.captured_at_utc)
          FROM player_xp_snapshots ps1
          WHERE ps1.clan_key = :ck
            AND ps1.rsn_normalised = m.rsn_normalised
            AND ps1.captured_at_utc <= :startUtc
        ),
        (
          SELECT MIN(ps2.captured_at_utc)
          FROM player_xp_snapshots ps2
          WHERE ps2.clan_key = :ck
            AND ps2.rsn_normalised = m.rsn_normalised
            AND ps2.captured_at_utc >= :startUtc
        )
     )
    LEFT JOIN player_xp_snapshots s_end
      ON s_end.clan_key = :ck
     AND s_end.rsn_normalised = m.rsn_normalised
     AND s_end.captured_at_utc = (
        SELECT MAX(ps3.captured_at_utc)
        FROM player_xp_snapshots ps3
        WHERE ps3.clan_key = :ck
          AND ps3.rsn_normalised = m.rsn_normalised
          AND ps3.captured_at_utc <= :endUtc
     )
    WHERE m.clan_key = :ck
      AND m.is_active = 1
  ");

try {
    $stmt->execute([
        ':ck' => $clanKey,
        ':startUtc' => $xpWindow['start_utc'],
        ':endUtc' => $xpWindow['end_utc'],
    ]);
    $rows = $stmt->fetchAll();

    foreach ($rows as $r) {
        $rsn = (string)$r['rsn'];
        $startSkills = tracker_extract_skill_xp(tracker_parse_skills_json($r['start_skills_json']));
        $endSkills   = tracker_extract_skill_xp(tracker_parse_skills_json($r['end_skills_json']));

        foreach ($leaders as $skillName => $info) {
            $sx = $startSkills[$skillName] ?? null;
            $ex = $endSkills[$skillName] ?? null;
            if ($sx === null || $ex === null) continue;
            $gain = (int)$ex - (int)$sx;
            if ($gain <= 0) continue;
            if ($gain > (int)$leaders[$skillName]['gained_xp']) {
                $leaders[$skillName]['gained_xp'] = $gain;
                $leaders[$skillName]['rsn'] = $rsn;
                $leaders[$skillName]['has_data'] = true;
            } elseif ($gain === (int)$leaders[$skillName]['gained_xp'] && $gain > 0) {
                // tie-break: alphabetical
                $cur = (string)($leaders[$skillName]['rsn'] ?? '');
                if ($cur === '' || strcasecmp($rsn, $cur) < 0) {
                    $leaders[$skillName]['rsn'] = $rsn;
                    $leaders[$skillName]['has_data'] = true;
                }
            }
        }
    }
} catch (Throwable $e) {
    // Non-fatal: if snapshots aren't available yet, just return empty leaders.
}

$leadersList = array_values($leaders);

tracker_json([
    'ok' => true,
    'clan' => [
        'key' => $clan['clan_key'],
        'name' => $clan['clan_name'],
        'timezone' => $week['timezone'],
        'reset_weekday' => (int)$clan['reset_weekday'],
        'reset_time' => (string)$clan['reset_time'],
    ],
    'week' => $week,
    'stats' => [
        'active_members' => $active,
        'capped' => $capped,
        'uncapped' => $uncapped,
        'percent_capped' => $percent,
    ],
    'members' => array_map(static function(array $m) {
        return [
            'rsn' => $m['rsn'],
            'rank_name' => $m['rank_name'],
            'capped' => ((int)$m['capped'] === 1),
        ];
    }, $members),
    'xp' => [
        'period' => $xpWindow['period'],
        'start_utc' => $xpWindow['start_utc'],
        'end_utc' => $xpWindow['end_utc'],
        'leaders_by_skill' => $leadersList,
    ],
    'xp_periods' => [
        ['value' => '24h', 'label' => 'Last 24 hours'],
        ['value' => '7d', 'label' => 'Last 7 days'],
        ['value' => '30d', 'label' => 'Last 30 days'],
        ['value' => '90d', 'label' => 'Last 90 days'],
        ['value' => 'thisweek', 'label' => 'This clan week'],
        ['value' => 'lastweek', 'label' => 'Last clan week'],
    ],
    'last_pull' => [
        'utc' => $lastPullUtc,
        'local' => $lastPullLocal,
        'timezone' => $week['timezone'],
        'sources' => [
            'last_polled_at_utc' => $lastPolled ?: null,
            'last_xp_snapshot_utc' => $lastSnapshot ?: null,
            'last_member_sync_at' => $lastSync ?: null,
        ],
    ],
]);
