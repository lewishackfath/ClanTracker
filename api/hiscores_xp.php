<?php
declare(strict_types=1);

/**
 * Hiscores XP snapshot fetch + parse utilities.
 *
 * Supports:
 *  - Hiscores index_lite.ws (existing behaviour)
 *  - RuneMetrics public profile endpoint (new)
 *
 * Returns per-skill {level, xp} objects so we can store both values in skills_json.
 */

/* ============================================================
   RuneMetrics (NEW)
   ============================================================ */

function capcheck_runemetrics_build_url(string $rsn): string
{
    // RuneMetrics profile URL expects underscores for spaces in "user=" commonly.
    // We'll normalise spaces to underscores; RuneMetrics handles both, but this matches your example.
    $user = str_replace(' ', '_', trim($rsn));
    return 'https://apps.runescape.com/runemetrics/profile?user=' . rawurlencode($user);
}

/**
 * Map RuneMetrics skill id -> skill name.
 * Based on RS3 order (matches your payload: 26 Invention, 27 Archaeology, 28 Necromancy).
 */
function capcheck_runemetrics_skill_id_map(): array
{
    return [
        0  => 'Attack',
        1  => 'Defence',
        2  => 'Strength',
        3  => 'Constitution',
        4  => 'Ranged',
        5  => 'Prayer',
        6  => 'Magic',
        7  => 'Cooking',
        8  => 'Woodcutting',
        9  => 'Fletching',
        10 => 'Fishing',
        11 => 'Firemaking',
        12 => 'Crafting',
        13 => 'Smithing',
        14 => 'Mining',
        15 => 'Herblore',
        16 => 'Agility',
        17 => 'Thieving',
        18 => 'Slayer',
        19 => 'Farming',
        20 => 'Runecrafting',
        21 => 'Hunter',
        22 => 'Construction',
        23 => 'Summoning',
        24 => 'Dungeoneering',
        25 => 'Divination',
        26 => 'Invention',
        27 => 'Archaeology',
        28 => 'Necromancy',
    ];
}

/**
 * RuneMetrics returns XP scaled by 10 in the public profile endpoint (as shown in your examples).
 * Convert to "real" XP stored in our DB snapshots by floor(xp/10).
 */
function capcheck_runemetrics_xp_to_real(int $xp): int
{
    if ($xp <= 0) return 0;
    return intdiv($xp, 10);
}

/**
 * Fetch RuneMetrics profile JSON.
 * Returns [ok(bool), body(string), http_code(int), error(string|null)]
 */
function capcheck_runemetrics_fetch_profile(string $rsn, array $opts = []): array
{
    $url = capcheck_runemetrics_build_url($rsn);
    $timeout = (int)($opts['timeout'] ?? 10);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => '24KRS CapCheck (+https://api.24krs.com.au/capCheck)',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json,text/plain,*/*',
        ],
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) return [false, '', $code, $err ?: 'cURL error'];
    if ($code !== 200)    return [false, (string)$body, $code, 'HTTP ' . $code];

    return [true, (string)$body, $code, null];
}

/**
 * Parse RuneMetrics JSON into the same snapshot structure as hiscores.
 *
 * Returns:
 * [
 *   'total_xp' => int,
 *   'skills' => array<string, array{level:int, xp:int}>,
 *   'overall' => array{rank:int, level:int, xp:int},
 *   'raw_lines' => int,
 * ]
 */
function capcheck_runemetrics_parse_profile(string $json): array
{
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return [
            'total_xp' => 0,
            'skills' => [],
            'overall' => ['rank' => 0, 'level' => 0, 'xp' => 0],
            'raw_lines' => 0,
        ];
    }

    $map = capcheck_runemetrics_skill_id_map();

    $skills = [];
    $totalXp = 0;

    // totalxp in the profile payload is also scaled by 10 (per your example vs DB snapshots)
    if (isset($data['totalxp']) && is_numeric($data['totalxp'])) {
        $totalXp = capcheck_runemetrics_xp_to_real((int)$data['totalxp']);
    }

    $skillvalues = $data['skillvalues'] ?? null;
    if (is_array($skillvalues)) {
        foreach ($skillvalues as $row) {
            if (!is_array($row)) continue;
            $id = isset($row['id']) ? (int)$row['id'] : -1;
            if (!isset($map[$id])) continue;

            $lvl = isset($row['level']) && is_numeric($row['level']) ? (int)$row['level'] : 0;
            $xp10 = isset($row['xp']) && is_numeric($row['xp']) ? (int)$row['xp'] : 0;

            $skills[$map[$id]] = [
                'level' => max(0, $lvl),
                'xp'    => capcheck_runemetrics_xp_to_real(max(0, $xp10)),
            ];
        }
    }

    // If totalxp missing, compute sum from skills
    if ($totalXp <= 0 && !empty($skills)) {
        $sum = 0;
        foreach ($skills as $v) $sum += (int)$v['xp'];
        $totalXp = $sum;
    }

    // total skill level is available as totalskill
    $totalLevel = 0;
    if (isset($data['totalskill']) && is_numeric($data['totalskill'])) {
        $totalLevel = (int)$data['totalskill'];
    }

    // rank is present as string with commas sometimes
    $rank = 0;
    if (isset($data['rank'])) {
        $rankStr = preg_replace('/[^0-9]/', '', (string)$data['rank']) ?? '';
        if ($rankStr !== '') $rank = (int)$rankStr;
    }

    return [
        'total_xp' => $totalXp,
        'skills' => $skills,
        'overall' => [
            'rank'  => max(0, $rank),
            'level' => max(0, $totalLevel),
            'xp'    => max(0, $totalXp),
        ],
        // raw_lines analogue: count skills returned
        'raw_lines' => is_array($skillvalues) ? count($skillvalues) : 0,
    ];
}

/**
 * Convenience wrapper: fetch + parse RuneMetrics in one call.
 *
 * Returns the same shape as capcheck_hiscores_get_xp_snapshot().
 */
function capcheck_runemetrics_get_xp_snapshot(string $rsn, array $opts = []): array
{
    [$ok, $body, $code, $err] = capcheck_runemetrics_fetch_profile($rsn, $opts);

    if (!$ok) {
        return [
            'ok' => false,
            'rsn' => $rsn,
            'http_code' => $code,
            'error' => $err,
            'total_xp' => null,
            'skills' => null,
            'overall' => null,
            'raw_lines' => null,
        ];
    }

    $parsed = capcheck_runemetrics_parse_profile($body);

    if (empty($parsed['skills'])) {
        return [
            'ok' => false,
            'rsn' => $rsn,
            'http_code' => $code,
            'error' => 'RuneMetrics returned no skillvalues (profile may be private or unavailable).',
            'total_xp' => null,
            'skills' => null,
            'overall' => null,
            'raw_lines' => null,
        ];
    }

    return [
        'ok' => true,
        'rsn' => $rsn,
        'http_code' => $code,
        'error' => null,
        'total_xp' => (int)$parsed['total_xp'],
        'skills' => $parsed['skills'],
        'overall' => $parsed['overall'],
        'raw_lines' => (int)$parsed['raw_lines'],
    ];
}

/* ============================================================
   Hiscores (EXISTING)
   ============================================================ */

function capcheck_hiscores_build_lite_url(string $rsn, bool $ironman = false, bool $hardcore = false, bool $ultimate = false): string
{
    $mode = 'hiscore';
    if ($ultimate) $mode = 'hiscore_ultimate';
    else if ($hardcore) $mode = 'hiscore_hardcore_ironman';
    else if ($ironman) $mode = 'hiscore_ironman';

    return 'https://secure.runescape.com/m=' . $mode . '/index_lite.ws?player=' . rawurlencode($rsn);
}

/**
 * Fetch raw "index_lite.ws" content.
 * Returns [ok(bool), body(string), http_code(int), error(string|null)]
 */
function capcheck_hiscores_fetch_lite(string $rsn, array $opts = []): array
{
    $url = capcheck_hiscores_build_lite_url(
        $rsn,
        (bool)($opts['ironman'] ?? false),
        (bool)($opts['hardcore'] ?? false),
        (bool)($opts['ultimate'] ?? false)
    );

    $timeout = (int)($opts['timeout'] ?? 10);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => '24KRS CapCheck (+https://api.24krs.com.au/capCheck)',
        CURLOPT_HTTPHEADER => [
            'Accept: text/plain',
        ],
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        return [false, '', $code, $err ?: 'cURL error'];
    }

    if ($code !== 200) {
        return [false, (string)$body, $code, 'HTTP ' . $code];
    }

    return [true, (string)$body, $code, null];
}

/**
 * Parse hiscores lite response.
 *
 * Returns:
 * [
 *   'total_xp' => int,
 *   'skills' => array<string, array{level:int, xp:int}>, // skill => {level, xp}
 *   'overall' => array{rank:int, level:int, xp:int},
 *   'raw_lines' => int,
 * ]
 */
function capcheck_hiscores_parse_lite(string $liteBody): array
{
    $lines = preg_split("/\r\n|\n|\r/", trim($liteBody));
    if (!$lines || count($lines) < 2) {
        return [
            'total_xp' => 0,
            'skills' => [],
            'overall' => ['rank' => 0, 'level' => 0, 'xp' => 0],
            'raw_lines' => 0,
        ];
    }

    // RS3 hiscores order: Overall + skills.
    // If Jagex adds a new skill later, append it here.
    $skillNames = [
        'Overall',
        'Attack','Defence','Strength','Constitution','Ranged','Prayer','Magic',
        'Cooking','Woodcutting','Fletching','Fishing','Firemaking','Crafting','Smithing','Mining',
        'Herblore','Agility','Thieving','Slayer','Farming','Runecrafting','Hunter','Construction',
        'Summoning','Dungeoneering','Divination','Invention','Archaeology','Necromancy',
    ];

    $skills = [];
    $overall = ['rank' => 0, 'level' => 0, 'xp' => 0];
    $totalXp = 0;

    $max = min(count($lines), count($skillNames));

    for ($i = 0; $i < $max; $i++) {
        $parts = explode(',', trim((string)$lines[$i]));
        // Format: rank,level,xp (sometimes -1 for unranked)
        $rank  = isset($parts[0]) ? (int)$parts[0] : 0;
        $level = isset($parts[1]) ? (int)$parts[1] : 0;
        $xp    = isset($parts[2]) ? (int)$parts[2] : 0;

        $entry = [
            'level' => max(0, $level),
            'xp'    => max(0, $xp),
        ];

        $name = $skillNames[$i];
        if ($name === 'Overall') {
            $overall = [
                'rank'  => max(0, $rank),
                'level' => $entry['level'], // total level
                'xp'    => $entry['xp'],    // total xp
            ];
            $totalXp = $overall['xp'];
        } else {
            $skills[$name] = $entry;
        }
    }

    // Fallback: compute total XP from skills if overall is missing/zero.
    if ($totalXp <= 0 && !empty($skills)) {
        $sum = 0;
        foreach ($skills as $v) $sum += (int)$v['xp'];
        $totalXp = $sum;
        $overall['xp'] = $sum;
    }

    return [
        'total_xp' => $totalXp,
        'skills' => $skills,
        'overall' => $overall,
        'raw_lines' => count($lines),
    ];
}

/**
 * Convenience wrapper: fetch + parse in one call.
 *
 * Returns:
 * [
 *   'ok' => bool,
 *   'rsn' => string,
 *   'http_code' => int,
 *   'error' => string|null,
 *   'total_xp' => int|null,
 *   'skills' => array<string,array{level:int,xp:int}>|null,
 *   'overall' => array{rank:int,level:int,xp:int}|null,
 *   'raw_lines' => int|null,
 * ]
 */
function capcheck_hiscores_get_xp_snapshot(string $rsn, array $opts = []): array
{
    [$ok, $body, $code, $err] = capcheck_hiscores_fetch_lite($rsn, $opts);

    if (!$ok) {
        return [
            'ok' => false,
            'rsn' => $rsn,
            'http_code' => $code,
            'error' => $err,
            'total_xp' => null,
            'skills' => null,
            'overall' => null,
            'raw_lines' => null,
        ];
    }

    $parsed = capcheck_hiscores_parse_lite($body);

    return [
        'ok' => true,
        'rsn' => $rsn,
        'http_code' => $code,
        'error' => null,
        'total_xp' => (int)$parsed['total_xp'],
        'skills' => $parsed['skills'],
        'overall' => $parsed['overall'],
        'raw_lines' => (int)$parsed['raw_lines'],
    ];
}