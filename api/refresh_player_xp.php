<?php
declare(strict_types=1);

require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/hiscores_xp.php';

try {
    $rsn = trim((string)($_GET['player'] ?? ''));
    if ($rsn === '') {
        tracker_json(['ok' => false, 'error' => 'Missing player'], 400);
    }

    $pdo = tracker_pdo();

    $rsnNormalised = tracker_normalise($rsn);

    /**
     * 1️⃣ Resolve member + clan
     * We must supply clan_key to satisfy schema constraints
     */
    $stmt = $pdo->prepare("
        SELECT clan_key
        FROM members
        WHERE rsn_normalised = :rn
        LIMIT 1
    ");
    $stmt->execute([':rn' => $rsnNormalised]);
    $clanKey = $stmt->fetchColumn();

    if (!$clanKey) {
        tracker_json([
            'ok' => false,
            'error' => 'Player not found in any clan',
            'hint' => 'Cannot refresh XP for a player that is not in the members table',
        ], 404);
    }

    /**
     * 2️⃣ Fetch snapshot from RuneScape hiscores
     */
    $snapshot = capcheck_hiscores_get_xp_snapshot($rsn);

    if (!$snapshot['ok'] || empty($snapshot['skills'])) {
        tracker_json([
            'ok' => false,
            'error' => 'Failed to fetch hiscores',
            'hint' => $snapshot['error'] ?? 'Unknown error',
        ], 500);
    }

    $skillsJson = json_encode($snapshot['skills'], JSON_THROW_ON_ERROR);
    $totalXp    = (int)$snapshot['total_xp'];

    /**
     * 3️⃣ Compute snapshot hash (required by schema)
     */
    $snapshotHash = hash(
        'sha256',
        $rsnNormalised . '|' . $totalXp . '|' . $skillsJson
    );

    /**
     * 4️⃣ Insert XP snapshot (schema-compatible)
     */
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
        ':clan'         => $clanKey,
        ':rsn'          => $rsn,
        ':rn'           => $rsnNormalised,
        ':total_xp'     => $totalXp,
        ':skills_json'  => $skillsJson,
        ':snapshot_hash'=> $snapshotHash,
    ]);

    tracker_json([
        'ok' => true,
        'refreshed' => true,
        'player' => $rsn,
        'clan_key' => $clanKey,
        'total_xp' => $totalXp,
        'raw_lines' => $snapshot['raw_lines'] ?? null,
    ]);

} catch (Throwable $e) {
    tracker_json([
        'ok' => false,
        'error' => 'Refresh endpoint failed',
        'hint' => $e->getMessage(),
    ], 500);
}