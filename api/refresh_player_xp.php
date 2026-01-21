<?php
declare(strict_types=1);

require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/hiscores_xp.php';

try {
    $rsnInput = trim((string)($_GET['player'] ?? ''));
    if ($rsnInput === '') {
        tracker_json(['ok' => false, 'error' => 'Missing player'], 400);
    }

    $pdo = tracker_pdo();

    // Build lookup variants from the INPUT only (for matching members table)
    $inputNorm = tracker_normalise($rsnInput);
    $inputNormNoSpaces = str_replace(' ', '', $inputNorm);

    $rawSpaces = $rsnInput;
    $rawUnderscore = str_replace(' ', '_', $rawSpaces);
    $rawNoSpaces = str_replace(' ', '', $rawSpaces);

    // Resolve member and canonical identifiers
    $stmt = $pdo->prepare("
        SELECT clan_key, rsn, rsn_normalised
        FROM members
        WHERE
            rsn_normalised = :rn
            OR REPLACE(rsn_normalised, ' ', '') = :rnns
            OR rsn = :raw
            OR REPLACE(rsn, ' ', '') = :rawns
            OR rsn = :rawu
        ORDER BY is_active DESC, updated_at DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':rn'    => $inputNorm,
        ':rnns'  => $inputNormNoSpaces,
        ':raw'   => $rawSpaces,
        ':rawns' => $rawNoSpaces,
        ':rawu'  => $rawUnderscore,
    ]);

    $member = $stmt->fetch();
    if (!$member) {
        tracker_json([
            'ok' => false,
            'error' => 'Player not found in any clan',
            'hint' => 'Cannot refresh XP for a player that is not in the members table',
        ], 404);
    }

    $clanKey      = (string)$member['clan_key'];
    $canonicalRsn = (string)$member['rsn'];
    $rsnNorm      = (string)$member['rsn_normalised'];

    // Try RuneMetrics first (matches your “latest pull” source)
    $snap = capcheck_runemetrics_get_xp_snapshot($canonicalRsn, ['timeout' => 12]);

    $source = 'runemetrics';
    if (!$snap['ok']) {
        // Fallback to hiscores
        $snap = capcheck_hiscores_get_xp_snapshot($canonicalRsn, ['timeout' => 12]);
        $source = 'hiscores';
    }

    if (!$snap['ok'] || empty($snap['skills'])) {
        tracker_json([
            'ok' => false,
            'error' => 'Failed to fetch XP snapshot',
            'hint' => $snap['error'] ?? 'Unknown error',
        ], 500);
    }

    $skillsJson = json_encode($snap['skills'], JSON_THROW_ON_ERROR);
    $totalXp    = (int)$snap['total_xp'];

    $snapshotHash = hash('sha256', $rsnNorm . '|' . $totalXp . '|' . $skillsJson);

    // Insert snapshot; if duplicate, treat as success
    try {
        $stmt = $pdo->prepare("
            INSERT INTO player_xp_snapshots (
                clan_key,
                rsn,
                rsn_normalised,
                total_xp,
                skills_json,
                snapshot_hash,
                captured_at_utc
            ) VALUES (
                :clan,
                :rsn,
                :rn,
                :total_xp,
                :skills_json,
                :snapshot_hash,
                UTC_TIMESTAMP()
            )
        ");
        $stmt->execute([
            ':clan'          => $clanKey,
            ':rsn'           => $canonicalRsn,
            ':rn'            => $rsnNorm,
            ':total_xp'      => $totalXp,
            ':skills_json'   => $skillsJson,
            ':snapshot_hash' => $snapshotHash,
        ]);

        tracker_json([
            'ok' => true,
            'refreshed' => true,
            'inserted' => true,
            'source' => $source,
            'player' => $canonicalRsn,
            'clan_key' => $clanKey,
            'total_xp' => $totalXp,
            'raw_lines' => $snap['raw_lines'] ?? null,
        ]);
    } catch (PDOException $e) {
        $dupCode = (string)($e->errorInfo[1] ?? '');
        if ($dupCode === '1062') {
            tracker_json([
                'ok' => true,
                'refreshed' => true,
                'inserted' => false,
                'duplicate' => true,
                'source' => $source,
                'player' => $canonicalRsn,
                'clan_key' => $clanKey,
                'total_xp' => $totalXp,
                'snapshot_hash' => $snapshotHash,
                'note' => 'Snapshot already exists (no new data to insert).',
            ]);
        }
        throw $e;
    }

} catch (Throwable $e) {
    tracker_json([
        'ok' => false,
        'error' => 'Refresh endpoint failed',
        'hint' => $e->getMessage(),
    ], 500);
}