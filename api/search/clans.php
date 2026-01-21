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
  c.clan_key AS `key`,
  c.clan_name AS `name`,
  (
    SELECT COUNT(*)
    FROM members m
    WHERE m.clan_key = c.clan_key
      AND m.is_active = 1
  ) AS members
FROM clans c
WHERE c.is_enabled = 1
  AND (
    c.clan_key LIKE :like
    OR c.clan_name LIKE :like
  )
ORDER BY c.clan_name ASC
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
        'error' => 'Query failed',
    ], 500);
}
