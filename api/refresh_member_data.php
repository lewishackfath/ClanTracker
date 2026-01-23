<?php
declare(strict_types=1);

require_once __DIR__ . '/_db.php';
require_once __DIR__ . '/functions/runemetrics.php';
require_once __DIR__ . '/functions/process_activities.php';
require_once __DIR__ . '/functions/activity_helpers.php';

header('Content-Type: application/json');

$REFRESH_INTERVAL_SECONDS = 15 * 60; // 15 minutes
$pdo = tracker_pdo();

// Inputs
$memberId = isset($_GET['member_id']) ? (int)$_GET['member_id'] : 0;
$rsnRaw   = isset($_GET['rsn']) ? trim((string)$_GET['rsn']) : '';

if ($memberId <= 0 && $rsnRaw === '') {
  tracker_json(['ok' => false, 'error' => 'member_id or rsn is required'], 400);
}

// Resolve member (need member_id + clan_id + rsn)
$member = null;

if ($memberId > 0) {
  $st = $pdo->prepare("SELECT id, clan_id, rsn FROM members WHERE id = :id LIMIT 1");
  $st->execute([':id' => $memberId]);
  $member = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} else {
  $rsnNorm = tracker_normalise($rsnRaw);
  $st = $pdo->prepare("
    SELECT id, clan_id, rsn
    FROM members
    WHERE rsn_normalised = :n OR rsn = :r
    LIMIT 1
  ");
  $st->execute([':n' => $rsnNorm, ':r' => $rsnRaw]);
  $member = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$member) {
  tracker_json(['ok' => false, 'error' => 'Member not found'], 404);
}

$memberId = (int)$member['id'];
$clanId   = (int)$member['clan_id'];
$rsn      = (string)$member['rsn'];

try {
  // -------------------------------------------------------------------
  // Freshness check: XP snapshots ONLY, using captured_at_utc
  // (manual pull must NOT touch member_poll_state)
  // -------------------------------------------------------------------
  $stXp = $pdo->prepare("
    SELECT MAX(captured_at_utc) AS last_xp_at
    FROM member_xp_snapshots
    WHERE member_id = :mid
  ");
  $stXp->execute([':mid' => $memberId]);
  $lastCapturedUtc = (string)($stXp->fetchColumn() ?: '');

  $lastTs = $lastCapturedUtc ? strtotime($lastCapturedUtc . ' UTC') : 0;
  $nowTs  = time();

  if ($lastTs > 0 && ($nowTs - $lastTs) < $REFRESH_INTERVAL_SECONDS) {
    tracker_json([
      'ok' => true,
      'refreshed' => false,
      'reason' => 'fresh',
      'member_id' => $memberId,
      'clan_id' => $clanId,
      'rsn' => $rsn,
      'freshness_column' => 'member_xp_snapshots.captured_at_utc',
      'last_xp_captured_at_utc' => $lastCapturedUtc,
    ]);
  }

  // -------------------------------------------------------------------
  // Refresh: RuneMetrics sync (creates new XP snapshot + stores raw activities)
  // -------------------------------------------------------------------
  $sync = runemetrics_sync_member($pdo, $memberId, 20);

  // Optional but recommended: classify/attach rules for any new activities
  $proc = ['ok' => true, 'skipped' => true];
  if ($clanId > 0) {
    $proc = process_activities_for_clan($pdo, $clanId, 2000);
  }

  // Re-check last captured time after refresh
  $stXp->execute([':mid' => $memberId]);
  $newLastCapturedUtc = (string)($stXp->fetchColumn() ?: '');

  tracker_json([
    'ok' => true,
    'refreshed' => true,
    'member_id' => $memberId,
    'clan_id' => $clanId,
    'rsn' => $rsn,
    'freshness_column' => 'member_xp_snapshots.captured_at_utc',
    'last_xp_captured_at_utc' => $newLastCapturedUtc ?: null,
    'sync' => $sync,
    'activities_processed' => $proc,
  ]);

} catch (Throwable $e) {
  tracker_json([
    'ok' => false,
    'error' => 'Refresh failed',
    'details' => $e->getMessage(),
  ], 500);
}
