<?php
declare(strict_types=1);

/**
 * refresh_player_xp.php (refactored)
 *
 * Purpose:
 * - Keep this endpoint as the "entry point" called by the main poller
 * - Replace legacy hiscores/player_xp_snapshots logic with the new RuneMetrics ingestion:
 *     - Fetch RuneMetrics profile (activities + skillvalues)
 *     - Insert activities into member_activities
 *     - Apply rule matching + cap/visit detection via process_activities.php
 *     - Insert XP snapshot into member_xp_snapshots (XP divided by 10, skills_json)
 *
 * Query:
 *   ?player=<RSN>
 *
 * Response:
 *   { ok, player, member_id, clan_id, private_profile, activities_inserted, activities_processed,
 *     snapshot_inserted, snapshot_duplicate, source }
 */

require_once __DIR__ . '/_db.php';

// New logic files
require_once __DIR__ . '/../functions/runemetrics.php';
require_once __DIR__ . '/../functions/process_activities.php';

try {
    $rsnInput = trim((string)($_GET['player'] ?? ''));
    if ($rsnInput === '') {
        tracker_json(['ok' => false, 'error' => 'Missing player'], 400);
    }

    $pdo = tracker_pdo();

    // Resolve member by RSN (new schema)
    $inputNorm = tracker_normalise($rsnInput);
    $inputNormNoSpaces = str_replace(' ', '', $inputNorm);

    $rawSpaces     = $rsnInput;
    $rawUnderscore = str_replace(' ', '_', $rawSpaces);
    $rawNoSpaces   = str_replace(' ', '', $rawSpaces);

    $stmt = $pdo->prepare("
        SELECT
            m.id AS member_id,
            m.clan_id,
            m.rsn,
            m.rsn_normalised,
            c.name AS clan_name
        FROM members m
        JOIN clans c ON c.id = m.clan_id
        WHERE
            (
                   m.rsn_normalised = :rn
                OR REPLACE(m.rsn_normalised, ' ', '') = :rnns
                OR m.rsn = :raw
                OR REPLACE(m.rsn, ' ', '') = :rawns
                OR m.rsn = :rawu
            )
            AND c.is_enabled = 1
            AND c.inactive_at IS NULL
        ORDER BY m.is_active DESC, m.updated_at DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':rn'    => $inputNorm,
        ':rnns'  => $inputNormNoSpaces,
        ':raw'   => $rawSpaces,
        ':rawns' => $rawNoSpaces,
        ':rawu'  => $rawUnderscore,
    ]);

    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$member) {
        tracker_json([
            'ok' => false,
            'error' => 'Player not found in any enabled clan',
            'hint' => 'Cannot refresh RuneMetrics data for a player that is not in members table',
        ], 404);
    }

    $memberId     = (int)$member['member_id'];
    $clanId       = (int)$member['clan_id'];
    $canonicalRsn = (string)$member['rsn'];

    // Fetch + store profile (activities + XP snapshot)
    // NOTE: RuneMetrics activity dates are already UTC; we store them as UTC.
    $res = rs24k_runemetrics_ingest_member($pdo, $memberId, $clanId, $canonicalRsn, 20);

    // If private profile, return a sentinel for the poller to slow down checks
    if (!empty($res['private_profile'])) {
        tracker_json([
            'ok' => true,
            'source' => 'runemetrics',
            'private_profile' => true,
            'player' => $canonicalRsn,
            'member_id' => $memberId,
            'clan_id' => $clanId,
            'activities_inserted' => (int)($res['activities_inserted'] ?? 0),
            'activities_processed' => (int)($res['activities_processed'] ?? 0),
            'snapshot_inserted' => (bool)($res['snapshot_inserted'] ?? false),
            'snapshot_duplicate' => (bool)($res['snapshot_duplicate'] ?? false),
        ]);
    }

    tracker_json([
        'ok' => true,
        'source' => 'runemetrics',
        'private_profile' => false,
        'player' => $canonicalRsn,
        'member_id' => $memberId,
        'clan_id' => $clanId,
        'activities_inserted' => (int)($res['activities_inserted'] ?? 0),
        'activities_processed' => (int)($res['activities_processed'] ?? 0),
        'snapshot_inserted' => (bool)($res['snapshot_inserted'] ?? false),
        'snapshot_duplicate' => (bool)($res['snapshot_duplicate'] ?? false),
        'errors' => $res['errors'] ?? [],
    ]);

} catch (Throwable $e) {
    tracker_json([
        'ok' => false,
        'error' => 'Refresh endpoint failed',
        'hint' => $e->getMessage(),
    ], 500);
}
