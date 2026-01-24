<?php
declare(strict_types=1);

/**
 * /functions/runemetrics.php
 *
 * Responsibilities:
 * 1) Fetch RuneMetrics profile for a member (activities=N)
 * 2) Insert activities into member_activities
 *    - Match activity_announcement_rules at insert time (first match wins)
 *    - If purpose is cap_detection or visit_detection, upsert into member_caps / member_citadel_visits
 * 3) Insert XP snapshot into member_xp_snapshots
 *    - Divide XP values by 10
 *    - Store as JSON (SkillName => {level, xp})
 *
 * Notes:
 * - Does NOT send announcements (separate function later).
 * - Activities are deduped by (member_id, activity_hash) unique key.
 * - XP snapshots are deduped by (member_id, snapshot_hash) unique key.
 */

date_default_timezone_set('UTC');

require_once __DIR__ . '/activity_helpers.php';

/* ============================================================
   Public entry points
   ============================================================ */

/**
 * Sync a member by member_id (preferred).
 *
 * @return array{
 *   ok:bool,
 *   member_id:int,
 *   clan_id:int,
 *   rsn:string,
 *   fetched_activities:int,
 *   inserted_activities:int,
 *   xp_snapshot_inserted:bool,
 *   error:?string
 * }
 */
function runemetrics_sync_member(PDO $pdo, int $memberId, int $activities = 20): array
{
    $result = [
        'ok' => false,
        'member_id' => $memberId,
        'clan_id' => 0,
        'rsn' => '',
        'fetched_activities' => 0,
        'inserted_activities' => 0,
        'xp_snapshot_inserted' => false,
        'cap_detected' => false,
        'status' => null,
        'error' => null,
    ];

    $capDetected = false;

    try {
        $member = rm_db_get_member($pdo, $memberId);
        if (!$member) {
            $result['error'] = "Member not found: {$memberId}";
            return $result;
        }

        $result['clan_id'] = (int)$member['clan_id'];
        $result['rsn'] = (string)$member['rsn'];

        $profile = rm_fetch_profile((string)$member['rsn'], $activities);

        // Handle private RuneMetrics profiles (poll less frequently in scheduler)
if (($profile['status'] ?? null) === 'private_profile') {
    rm_db_mark_member_private($pdo, (int)$member['id']);

    $result['ok'] = true;
    $result['fetched_activities'] = 0;
    $result['inserted_activities'] = 0;
    $result['xp_snapshot_inserted'] = false;
    $result['status'] = 'private_profile';
    return $result;
}

// If they were previously private and are now public again, clear the flags.
rm_db_clear_member_private($pdo, (int)$member['id']);



        // Activities
        $acts = is_array($profile['activities'] ?? null) ? $profile['activities'] : [];
        $result['fetched_activities'] = count($acts);

        if (!empty($acts)) {
            $result['inserted_activities'] = rm_db_insert_activities_with_processing(
                $pdo,
                (int)$member['id'],
                (int)$member['clan_id'],
                $acts,
                $capDetected
            );
        }

        // XP snapshot (divide XP by 10)
        $snapshot = rm_parse_xp_snapshot_div10($profile);
        if ($snapshot !== null) {
            $result['xp_snapshot_inserted'] = rm_db_insert_xp_snapshot(
                $pdo,
                (int)$member['id'],
                $snapshot['total_xp'],
                $snapshot['skills_json'],
                $snapshot['snapshot_hash']
            );
        }

        $result['cap_detected'] = $capDetected;
        $result['ok'] = true;
        return $result;

    } catch (Throwable $e) {
        $result['error'] = $e->getMessage();
        return $result;
    }
}

/**
 * Sync by RSN + clan_id (handy for testing).
 */
function runemetrics_sync_rsn(PDO $pdo, int $clanId, string $rsn, int $activities = 20): array
{
    $member = rm_db_find_member_by_rsn($pdo, $clanId, $rsn);
    if (!$member) {
        return [
            'ok' => false,
            'member_id' => 0,
            'clan_id' => $clanId,
            'rsn' => $rsn,
            'fetched_activities' => 0,
            'inserted_activities' => 0,
            'xp_snapshot_inserted' => false,
            'status' => null,
            'error' => "Member not found for clan_id={$clanId}, rsn={$rsn}",
        ];
    }
    return runemetrics_sync_member($pdo, (int)$member['id'], $activities);
}

/* ============================================================
   RuneMetrics HTTP fetch
   ============================================================ */

function rm_build_url(string $rsn, int $activities): string
{
    $user = str_replace(' ', '_', trim($rsn));
    return 'https://apps.runescape.com/runemetrics/profile?user=' . rawurlencode($user) . '&activities=' . (int)$activities;
}

/** @return array decoded JSON */
function rm_fetch_profile(string $rsn, int $activities = 20): array
{
    $url = rm_build_url($rsn, $activities);

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_USERAGENT => 'RS24K-Tracker/1.0 (RuneMetrics ingest)',
        CURLOPT_HTTPHEADER => ['Accept: application/json,text/plain,*/*'],
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException("RuneMetrics HTTP error: " . ($err ?: 'unknown'));
    }
    if ($code !== 200) {
        throw new RuntimeException("RuneMetrics HTTP status {$code}");
    }

    $data = json_decode((string)$body, true);
    if (!is_array($data)) {
        throw new RuntimeException('RuneMetrics returned invalid JSON');
    }

    if (isset($data['error'])) {
        // Private profiles return a valid JSON payload but contain no activities/XP.
        if ((string)$data['error'] === 'PROFILE_PRIVATE') {
            return ['status' => 'private_profile'];
        }
        throw new RuntimeException("RuneMetrics error: " . (string)$data['error']);
    }

    return $data;
}

/* ============================================================
   XP snapshot parsing (divide by 10)
   ============================================================ */

function rm_skill_id_map(): array
{
    return [
        0  => 'Attack',
        1  => 'Defence',
        2  => 'Strength',
        3  => 'Constitution',
        4  => 'Ranged',
        5  => 'Prayer',
        6  => 'Magic',
        7  => 'Cooking',
        8  => 'Woodcutting',
        9  => 'Fletching',
        10 => 'Fishing',
        11 => 'Firemaking',
        12 => 'Crafting',
        13 => 'Smithing',
        14 => 'Mining',
        15 => 'Herblore',
        16 => 'Agility',
        17 => 'Thieving',
        18 => 'Slayer',
        19 => 'Farming',
        20 => 'Runecrafting',
        21 => 'Hunter',
        22 => 'Construction',
        23 => 'Summoning',
        24 => 'Dungeoneering',
        25 => 'Divination',
        26 => 'Invention',
        27 => 'Archaeology',
        28 => 'Necromancy',
    ];
}

function rm_xp_div10(int $xp): int
{
    if ($xp <= 0) return 0;
    return intdiv($xp, 10);
}

/**
 * @return array{total_xp:int, skills_json:string, snapshot_hash:string}|null
 */
function rm_parse_xp_snapshot_div10(array $profile): ?array
{
    $skillvalues = $profile['skillvalues'] ?? null;
    if (!is_array($skillvalues)) {
        return null;
    }

    $map = rm_skill_id_map();
    $skills = [];
    $totalLevel = 0;

    foreach ($skillvalues as $row) {
        if (!is_array($row)) continue;
        $id = isset($row['id']) ? (int)$row['id'] : -1;
        if (!isset($map[$id])) continue;

        $level = isset($row['level']) && is_numeric($row['level']) ? (int)$row['level'] : 0;
        $xpRaw = isset($row['xp']) && is_numeric($row['xp']) ? (int)$row['xp'] : 0;

        $skills[$map[$id]] = [
            'level' => max(0, $level),
            'xp'    => rm_xp_div10(max(0, $xpRaw)),
        ];

        // total level across all skills (excludes "Overall" because we don't map it)
        $totalLevel += max(0, $level);
    }

    if (empty($skills)) {
        return null;
    }

    $totalXp = 0;
    if (isset($profile['totalxp']) && is_numeric($profile['totalxp'])) {
        $totalXp = $profile['totalxp'];
    } else {
        foreach ($skills as $s) $totalXp += (int)$s['xp'];
    }

    // If RuneMetrics provides a total skill/level field, prefer it; otherwise use summed levels.
    if (isset($profile['totalskill']) && is_numeric($profile['totalskill'])) {
        $totalLevel = (int)$profile['totalskill'];
    } elseif (isset($profile['totallevel']) && is_numeric($profile['totallevel'])) {
        $totalLevel = (int)$profile['totallevel'];
    }

    // Insert total as a skill-like entry (level + xp)
    $skills = array_merge([
        'total' => [
            'level' => (int)$totalLevel,
            'xp'    => (int)$totalXp,
        ],
    ], $skills);

    ksort($skills);
    $skillsJson = json_encode($skills, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($skillsJson) || $skillsJson === '') {
        return null;
    }

    $hash = hash('sha256', $totalXp . '|' . $skillsJson);

    return [
        'total_xp' => $totalXp,
        'skills_json' => $skillsJson,
        'snapshot_hash' => $hash,
    ];
}

/* ============================================================
   Activity parsing
   ============================================================ */

function rm_parse_activity_date_utc(string $dateStr): DateTimeImmutable
{
    $dateStr = trim($dateStr);

    $dt = DateTimeImmutable::createFromFormat('d-M-Y H:i', $dateStr, new DateTimeZone('UTC'));
    if ($dt instanceof DateTimeImmutable) {
        return $dt->setTime((int)$dt->format('H'), (int)$dt->format('i'), 0, 0);
    }

    try {
        return new DateTimeImmutable($dateStr, new DateTimeZone('UTC'));
    } catch (Throwable) {
        throw new RuntimeException("Unable to parse activity date: {$dateStr}");
    }
}

function rm_activity_hash(int $memberId, string $dateUtc, string $text, string $details): string
{
    return hash('sha256', $memberId . '|' . $dateUtc . '|' . $text . '|' . $details);
}

function rm_trim(string $s, int $maxLen): string
{
    $s = trim($s);
    if ($s === '') return '';
    if (mb_strlen($s, 'UTF-8') <= $maxLen) return $s;
    return mb_substr($s, 0, $maxLen, 'UTF-8');
}

/* ============================================================
   DB helpers
   ============================================================ */

function rm_db_get_member(PDO $pdo, int $memberId): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, clan_id, rsn, rsn_normalised
        FROM members
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $memberId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}


function rm_db_mark_member_private(PDO $pdo, int $memberId): void
{
    try {
        $st = $pdo->prepare("
            UPDATE members
            SET
                is_private = 1,
                private_since_utc = IF(is_private = 0 OR private_since_utc IS NULL, UTC_TIMESTAMP(3), private_since_utc)
            WHERE id = :id
        ");
        $st->execute([':id' => $memberId]);
    } catch (Throwable $e) {
        // Schema might not be migrated yet; ignore safely.
    }
}

function rm_db_clear_member_private(PDO $pdo, int $memberId): void
{
    try {
        $st = $pdo->prepare("
            UPDATE members
            SET
                is_private = 0,
                private_since_utc = NULL
            WHERE id = :id
              AND is_private = 1
        ");
        $st->execute([':id' => $memberId]);
    } catch (Throwable $e) {
        // Schema might not be migrated yet; ignore safely.
    }
}

function rm_db_find_member_by_rsn(PDO $pdo, int $clanId, string $rsn): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, clan_id, rsn, rsn_normalised
        FROM members
        WHERE clan_id = :clan_id AND rsn = :rsn
        LIMIT 1
    ");
    $stmt->execute([':clan_id' => $clanId, ':rsn' => $rsn]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function rm_db_load_clan_reset(PDO $pdo, int $clanId): ?array
{
    $st = $pdo->prepare("SELECT id, timezone, reset_weekday, reset_time FROM clans WHERE id = :id LIMIT 1");
    $st->execute([':id' => $clanId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Insert activities with inline processing:
 * - match rules (sets rule_id)
 * - upsert caps/visits based on purpose
 *
 * @return int inserted count
 */
function rm_db_insert_activities_with_processing(PDO $pdo, int $memberId, int $clanId, array $activities, bool &$capDetected): int
{
    $inserted = 0;
    // set by reference for the caller
    $capDetected = false;

    $clan = rm_db_load_clan_reset($pdo, $clanId);
    if (!$clan) {
        throw new RuntimeException("Clan not found: {$clanId}");
    }

    $rules = ah_load_enabled_rules($pdo, $clanId);

    $stInsertActivity = $pdo->prepare("
        INSERT INTO member_activities
          (member_id, member_clan_id, activity_hash, activity_date_utc, activity_text, activity_details, rule_id, is_announced, announced_at, created_at)
        VALUES
          (:member_id, :clan_id, :hash, :date_utc, :text, :details, :rule_id, 0, NULL, CURRENT_TIMESTAMP(3))
        ON DUPLICATE KEY UPDATE
          rule_id = IFNULL(member_activities.rule_id, VALUES(rule_id))
    ");

    $stCap = $pdo->prepare("
        INSERT INTO member_caps
          (clan_id, member_id, cap_week_start_utc, cap_week_end_utc, capped_at_utc, created_at)
        VALUES
          (:clan_id, :member_id, :start_utc, :end_utc, :at_utc, CURRENT_TIMESTAMP(3))
        ON DUPLICATE KEY UPDATE
          capped_at_utc = VALUES(capped_at_utc)
    ");

    $stVisit = $pdo->prepare("
        INSERT INTO member_citadel_visits
          (clan_id, member_id, cap_week_start_utc, cap_week_end_utc, visited_at_utc, created_at)
        VALUES
          (:clan_id, :member_id, :start_utc, :end_utc, :at_utc, CURRENT_TIMESTAMP(3))
        ON DUPLICATE KEY UPDATE
          visited_at_utc = VALUES(visited_at_utc)
    ");

    $pdo->beginTransaction();
    try {
        foreach ($activities as $a) {
            if (!is_array($a)) continue;

            $text = rm_trim((string)($a['text'] ?? ''), 255);
            $details = (string)($a['details'] ?? '');
            $dateStr = (string)($a['date'] ?? '');

            if ($text === '' || trim($dateStr) === '') continue;

            $dtUtc = rm_parse_activity_date_utc($dateStr);
            $dateUtc = $dtUtc->format('Y-m-d H:i:s.v');

            $hash = rm_activity_hash($memberId, $dateUtc, $text, $details);

            $matchedRule = ah_match_rule($text, $details, $rules);
            $ruleId = $matchedRule ? (int)$matchedRule['id'] : null;

            $stInsertActivity->execute([
                ':member_id' => $memberId,
                ':clan_id' => $clanId,
                ':hash' => $hash,
                ':date_utc' => $dateUtc,
                ':text' => $text,
                ':details' => ($details !== '' ? $details : null),
                ':rule_id' => $ruleId,
            ]);

            if ((int)$stInsertActivity->rowCount() === 1) {
                $inserted++;
            }

            if ($matchedRule) {
                $purpose = (string)$matchedRule['purpose'];
                if ($purpose === 'cap_detection' || $purpose === 'visit_detection') {
                    [$startUtc, $endUtc] = ah_cap_week_bounds_utc(
                        $dtUtc,
                        (string)$clan['timezone'],
                        (int)$clan['reset_weekday'],
                        (string)$clan['reset_time']
                    );

                    $payload = [
                        ':clan_id' => $clanId,
                        ':member_id' => $memberId,
                        ':start_utc' => $startUtc->format('Y-m-d H:i:s.v'),
                        ':end_utc' => $endUtc->format('Y-m-d H:i:s.v'),
                        ':at_utc' => $dtUtc->format('Y-m-d H:i:s.v'),
                    ];

                    if ($purpose === 'cap_detection') {
                        $stCap->execute($payload);
                        $capDetected = true;
                    } else {
                        $stVisit->execute($payload);
                    }
                }
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    return $inserted;
}

/**
 * Insert XP snapshot (deduped by unique key on member_id + snapshot_hash).
 *
 * @return bool true if inserted, false if duplicate
 */
function rm_db_insert_xp_snapshot(PDO $pdo, int $memberId, int $totalXp, string $skillsJson, string $snapshotHash): bool
{
    $stmt = $pdo->prepare("
        INSERT INTO member_xp_snapshots
          (member_id, total_xp, skills_json, snapshot_hash, captured_at_utc, created_at)
        VALUES
          (:member_id, :total_xp, :skills_json, :hash, UTC_TIMESTAMP(3), CURRENT_TIMESTAMP(3))
        ON DUPLICATE KEY UPDATE
          id = id
    ");

    $stmt->execute([
        ':member_id' => $memberId,
        ':total_xp' => $totalXp,
        ':skills_json' => $skillsJson,
        ':hash' => $snapshotHash,
    ]);

    return ((int)$stmt->rowCount() === 1);
}
