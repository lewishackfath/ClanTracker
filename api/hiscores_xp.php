<?php
declare(strict_types=1);

/**
 * Hiscores XP snapshot fetch + parse utilities.
 *
 * Now returns per-skill {level, xp} objects so we can store both values in skills_json.
 */

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
