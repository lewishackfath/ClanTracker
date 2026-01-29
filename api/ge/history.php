<?php
// api/ge/history.php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

$pdo = ge_pdo();

$itemId = (int)($_GET['item_id'] ?? 0);
if ($itemId <= 0) ge_json_exit(['ok' => false, 'error' => 'invalid item_id'], 400);

// Cache TTL (seconds) – reduce upstream calls but keep chart useful
$ttlSeconds = 12 * 60 * 60; // 12 hours

// Check if we have recent cached points
$lastFetch = null;
try {
  $st = $pdo->prepare("SELECT MAX(fetched_at_utc) AS last_fetch FROM ge_price_points WHERE item_id = :id");
  $st->execute([':id' => $itemId]);
  $row = $st->fetch();
  if ($row && !empty($row['last_fetch'])) $lastFetch = (string)$row['last_fetch'];
} catch (Throwable $e) {
  // continue; we'll attempt upstream
}

$hasRecentCache = false;
if ($lastFetch) {
  $ts = strtotime($lastFetch);
  if ($ts !== false && (time() - $ts) <= $ttlSeconds) $hasRecentCache = true;
}

function ge_read_cached_graph(PDO $pdo, int $itemId): array {
  $st = $pdo->prepare("
    SELECT ts_ms, daily_price, avg_price
    FROM ge_price_points
    WHERE item_id = :id
    ORDER BY ts_ms ASC
  ");
  $st->execute([':id' => $itemId]);

  $daily = [];
  $avg = [];
  foreach ($st->fetchAll() as $r) {
    $k = (string)$r['ts_ms'];
    if ($r['daily_price'] !== null) $daily[$k] = (int)$r['daily_price'];
    if ($r['avg_price'] !== null) $avg[$k] = (int)$r['avg_price'];
  }
  return ['daily' => $daily, 'average' => $avg];
}

// If cache is recent and non-empty, return it
if ($hasRecentCache) {
  $graph = ge_read_cached_graph($pdo, $itemId);
  if (!empty($graph['daily']) || !empty($graph['average'])) {
    ge_json_exit(['ok' => true, 'cached' => true, 'item_id' => $itemId, 'graph' => $graph]);
  }
}

// Fetch from official ItemDB
$url = GE_BASE . '/api/graph/' . $itemId . '.json';
$up = ge_http_get_json($url);

if (!($up['__ok'] ?? false)) {
  // Fallback to whatever cache exists (even if stale)
  $graph = ge_read_cached_graph($pdo, $itemId);
  if (!empty($graph['daily']) || !empty($graph['average'])) {
    ge_json_exit([
      'ok' => true,
      'cached' => true,
      'stale' => true,
      'item_id' => $itemId,
      'graph' => $graph,
      'warning' => 'Upstream unavailable; served cached data',
      'upstream' => ['error' => $up['__error'] ?? 'unknown', 'code' => $up['__code'] ?? 0],
    ]);
  }

  ge_json_exit([
    'ok' => false,
    'error' => 'upstream_unavailable',
    'upstream' => ['error' => $up['__error'] ?? 'unknown', 'code' => $up['__code'] ?? 0],
  ], 502);
}

unset($up['__ok'], $up['__code'], $up['__content_type'], $up['__json_error'], $up['__body'], $up['__errno'], $up['__error']);

$daily = $up['daily'] ?? null;
$avg   = $up['average'] ?? null;

if (!is_array($daily) || !is_array($avg)) {
  ge_json_exit(['ok' => false, 'error' => 'unexpected_upstream_shape'], 502);
}

// Upsert points
$pdo->beginTransaction();
try {
  $now = ge_now_utc();
  $st = $pdo->prepare("
    INSERT INTO ge_price_points (item_id, ts_ms, daily_price, avg_price, fetched_at_utc)
    VALUES (:id, :ts, :d, :a, :t)
    ON DUPLICATE KEY UPDATE
      daily_price = VALUES(daily_price),
      avg_price = VALUES(avg_price),
      fetched_at_utc = VALUES(fetched_at_utc)
  ");

  // union keys
  $keys = array_unique(array_merge(array_keys($daily), array_keys($avg)));
  sort($keys, SORT_STRING);

  foreach ($keys as $k) {
    $ts = (int)$k;
    $d = array_key_exists($k, $daily) ? (int)$daily[$k] : null;
    $a = array_key_exists($k, $avg) ? (int)$avg[$k] : null;
    $st->execute([':id' => $itemId, ':ts' => $ts, ':d' => $d, ':a' => $a, ':t' => $now]);
  }

  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  ge_json_exit(['ok' => false, 'error' => 'db_write_failed', 'detail' => $e->getMessage()], 500);
}

ge_json_exit(['ok' => true, 'cached' => false, 'item_id' => $itemId, 'graph' => ['daily' => $daily, 'average' => $avg]]);
