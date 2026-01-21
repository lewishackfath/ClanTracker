<?php
declare(strict_types=1);

require_once __DIR__ . '/../_db.php';

$q = tracker_get_q(1, 64);
if ($q === '') {
    tracker_json([]);
}

$pdo = tracker_pdo();

$qRaw = trim($q);

// normalised: lower + collapse spaces (our helper)
$qNorm = tracker_normalise($qRaw);

// also try no-spaces variant (common RSN normalisation)
$qNormNoSpaces = str_replace(' ', '', $qNorm);
$qRawNoSpaces  = str_replace(' ', '', $qRaw);

$likeRaw = '%' . $qRaw . '%';
$likeNorm = '%' . $qNorm . '%';
$likeRawNoSpaces = '%' . $qRawNoSpaces . '%';
$likeNormNoSpaces = '%' . $qNormNoSpaces . '%';

$sql = "
SELECT
  m.rsn AS rsn,
  m.clan_key AS clan,
  CASE WHEN m.is_active = 1 THEN 'Active' ELSE 'Inactive' END AS status
FROM members m
LEFT JOIN clans c ON c.clan_key = m.clan_key
WHERE
  (c.is_enabled = 1 OR c.is_enabled IS NULL)
  AND (
    -- raw matches
    m.rsn LIKE :likeRaw
    OR m.rsn_normalised LIKE :likeNorm

    -- space-insensitive matches
    OR REPLACE(m.rsn, ' ', '') LIKE :likeRawNoSpaces
    OR REPLACE(m.rsn_normalised, ' ', '') LIKE :likeNormNoSpaces

    -- optional: allow searching by clan fields too
    OR m.clan_key LIKE :likeRaw
    OR c.clan_name LIKE :likeRaw
  )
ORDER BY
  m.is_active DESC,
  m.rsn ASC
LIMIT 20
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':likeRaw' => $likeRaw,
        ':likeNorm' => $likeNorm,
        ':likeRawNoSpaces' => $likeRawNoSpaces,
        ':likeNormNoSpaces' => $likeNormNoSpaces,
    ]);

    $rows = $stmt->fetchAll();
    tracker_json($rows);
} catch (Throwable $e) {
    tracker_json([
        'ok' => false,
        'error' => 'Query failed',
    ], 500);
}
