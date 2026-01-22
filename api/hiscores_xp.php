<?php
declare(strict_types=1);

/**
 * hiscores_xp.php (compat shim)
 *
 * This file used to fetch and parse Hiscores / RuneMetrics snapshots for the old
 * player_xp_snapshots table.
 *
 * The refactored stack now uses:
 *  - /functions/runemetrics.php for fetching + ingestion
 *  - member_xp_snapshots table (by member_id)
 *
 * We keep a minimal set of helpers for any legacy callers that still include this file,
 * but default to RuneMetrics. Hiscores fallback is intentionally removed here to keep
 * behaviour consistent with the new ingestion pipeline.
 */

require_once __DIR__ . '/../functions/runemetrics.php';

/**
 * Legacy-compatible signature: fetch an XP snapshot from RuneMetrics.
 * Returns: [ok=>bool, total_xp=>int, skills=>array, error=>string]
 */
function capcheck_runemetrics_get_xp_snapshot(string $rsn, array $opts = []): array
{
    $profile = rs24k_runemetrics_fetch_profile($rsn, (int)($opts['activities'] ?? 0), $opts);
    if (!$profile['ok']) {
        return [
            'ok' => false,
            'error' => $profile['error'] ?? 'Failed to fetch RuneMetrics profile',
        ];
    }
    if (!empty($profile['private_profile'])) {
        return [
            'ok' => false,
            'error' => 'private_profile',
            'private_profile' => true,
        ];
    }

    $xp = rs24k_runemetrics_extract_xp_snapshot_div10($profile);
    if (!$xp['ok']) {
        return [
            'ok' => false,
            'error' => $xp['error'] ?? 'Failed to parse RuneMetrics XP snapshot',
        ];
    }

    return $xp;
}
