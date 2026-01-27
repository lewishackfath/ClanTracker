<?php
require __DIR__ . '/_bootstrap.php';

$pdo = ge_pdo();
$itemId = (int)($_GET['item_id'] ?? 0);
if ($itemId <= 0) ge_json_exit(['ok'=>false,'error'=>'invalid item_id'],400);

$cacheKey = 'detail:'.$itemId;
$stmt = $pdo->prepare("SELECT meta_value FROM ge_meta WHERE meta_key=:k");
$stmt->execute([':k'=>$cacheKey]);
if ($r=$stmt->fetch()) {
  ge_json_exit(['ok'=>true,'cached'=>true,'detail'=>json_decode($r['meta_value'],true)]);
}

$json = ge_http_get_json(GE_BASE.'/api/catalogue/detail.json?item='.$itemId);
if (!($json['__ok']??false)) ge_json_exit(['ok'=>false,'error'=>'upstream'],502);
unset($json['__ok'],$json['__code']);

$pdo->prepare("INSERT INTO ge_meta(meta_key,meta_value,updated_at_utc)
 VALUES(:k,:v,:t)
 ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value),updated_at_utc=VALUES(updated_at_utc)")
->execute([':k'=>$cacheKey,':v'=>json_encode($json),':t'=>ge_now_utc()]);

ge_json_exit(['ok'=>true,'cached'=>false,'detail'=>$json]);
