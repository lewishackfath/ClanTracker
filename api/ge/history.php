<?php
require __DIR__ . '/_bootstrap.php';

$pdo = ge_pdo();
$itemId = (int)($_GET['item_id'] ?? 0);
if ($itemId <= 0) ge_json_exit(['ok'=>false,'error'=>'invalid item_id'],400);

$stmt = $pdo->prepare("
  SELECT ts_ms,daily_price,avg_price
  FROM ge_price_points
  WHERE item_id=:id
  ORDER BY ts_ms
");
$stmt->execute([':id'=>$itemId]);

$daily=[]; $avg=[];
foreach ($stmt as $r) {
  if ($r['daily_price']!==null) $daily[(string)$r['ts_ms']] = (int)$r['daily_price'];
  if ($r['avg_price']!==null) $avg[(string)$r['ts_ms']] = (int)$r['avg_price'];
}

ge_json_exit(['ok'=>true,'cached'=>true,'graph'=>['daily'=>$daily,'average'=>$avg]]);
