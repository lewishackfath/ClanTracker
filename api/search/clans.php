<?php
declare(strict_types=1);

require_once __DIR__ . '/../_db.php';

$q = tracker_get_q(1, 64);
if ($q === '') {
    tracker_json([]); // empty results for empty query
}

$pdo = tracker_pdo();
$like = '%' . $q . '%';

$sql = "
SELECT
  c.id   AS `key`,
  c.name AS `name`,
  (
    SELECT COUNT(*)
    FROM members m
    WHERE m.clan_id = c.id
      AND m.is_active = 1
  ) AS members
FROM clans c
WHERE c.is_enabled = 1
  AND c.inactive_at IS NULL
  AND (
    CAST(c.id AS CHAR) LIKE :like
    OR c.name LIKE :like
  )
ORDER BY c.name ASC
LIMIT 20
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':like' => $like]);
    $rows = $stmt->fetchAll();
    tracker_json($rows);
} catch (Throwable $e) {
    tracker_json([
        'ok' => false,
        'error' => 'Clan search failed',
        'hint' => $e->getMessage(),
    ], 500);
}
