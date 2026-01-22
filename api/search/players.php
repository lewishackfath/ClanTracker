<?php
declare(strict_types=1);

require_once __DIR__ . '/../_db.php';

$q = tracker_get_q(1, 64);
if ($q === '') {
    tracker_json([]);
}

$pdo = tracker_pdo();

$qRaw = trim($q);
$qNorm = tracker_normalise($qRaw);

$qNormNoSpaces = str_replace(' ', '', $qNorm);
$qRawNoSpaces  = str_replace(' ', '', $qRaw);

$likeRaw = '%' . $qRaw . '%';
$likeNorm = '%' . $qNorm . '%';
$likeRawNoSpaces = '%' . $qRawNoSpaces . '%';
$likeNormNoSpaces = '%' . $qNormNoSpaces . '%';

$sql = "
SELECT
  m.rsn AS rsn,
  c.name AS clan,
  c.id AS clan_id,
  CASE WHEN m.is_active = 1 THEN 'Active' ELSE 'Inactive' END AS status
FROM members m
JOIN clans c ON c.id = m.clan_id
WHERE
  c.is_enabled = 1
  AND c.inactive_at IS NULL
  AND (
    m.rsn LIKE :likeRaw
    OR m.rsn_normalised LIKE :likeNorm
    OR REPLACE(m.rsn, ' ', '') LIKE :likeRawNoSpaces
    OR REPLACE(m.rsn_normalised, ' ', '') LIKE :likeNormNoSpaces
    OR CAST(c.id AS CHAR) LIKE :likeRaw
    OR c.name LIKE :likeRaw
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
        'error' => 'Player search failed',
        'hint' => $e->getMessage(),
    ], 500);
}
