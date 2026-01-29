<?php
require __DIR__ . '/_bootstrap.php';

$pdo = ge_pdo();

$q = trim($_GET['q'] ?? '');
$limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));

if ($q === '') {
  ge_json_exit(['ok' => true, 'items' => []]);
}

/**
 * Escape LIKE wildcards so user input can’t hijack the pattern.
 */
$like = str_replace(
  ['\\', '%', '_'],
  ['\\\\', '\\%', '\\_'],
  $q
);

$stmt = $pdo->prepare("
  SELECT item_id, name, category, icon_url, icon_large_url
  FROM ge_items
  WHERE name LIKE :q ESCAPE '\\\\'
  ORDER BY name
  LIMIT :l
");

$stmt->bindValue(':q', '%' . $like . '%', PDO::PARAM_STR); // <-- contains anywhere
$stmt->bindValue(':l', $limit, PDO::PARAM_INT);
$stmt->execute();

ge_json_exit(['ok' => true, 'items' => $stmt->fetchAll()]);
