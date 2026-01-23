<?php
declare(strict_types=1);

/**
 * /functions/process_activities.php
 *
 * Catch-up / backfill processor:
 * - Finds activities where rule_id IS NULL
 * - Matches enabled activity_announcement_rules
 * - Sets rule_id
 * - If purpose is cap_detection or visit_detection, upserts caps/visits
 *
 * Useful when:
 * - activities were inserted before rules existed
 * - you add new rules and want to apply them to historical data (only where rule_id is NULL)
 */

date_default_timezone_set('UTC');

require_once __DIR__ . '/activity_helpers.php';

/* ============================================================
   Public API
   ============================================================ */

/**
 * Process rule matching for a clan (activities where rule_id IS NULL).
 *
 * @return array{
 *   ok:bool,
 *   clan_id:int,
 *   fetched:int,
 *   updated_rule_id:int,
 *   caps_upserted:int,
 *   visits_upserted:int,
 *   error:?string
 * }
 */
function process_activities_for_clan(PDO $pdo, int $clanId, int $limit = 1000): array
{
    $res = [
        'ok' => false,
        'clan_id' => $clanId,
        'fetched' => 0,
        'updated_rule_id' => 0,
        'caps_upserted' => 0,
        'visits_upserted' => 0,
        'error' => null,
    ];

    try {
        $clan = pa_db_load_clan_reset($pdo, $clanId);
        if (!$clan) {
            $res['error'] = "Clan not found: {$clanId}";
            return $res;
        }

        $rules = pa_db_load_enabled_rules($pdo, $clanId);
        if (!$rules) {
            $res['ok'] = true;
            return $res;
        }

        $activities = pa_db_fetch_unruled_activities($pdo, $clanId, $limit);
        $res['fetched'] = count($activities);

        if (!$activities) {
            $res['ok'] = true;
            return $res;
        }

        $stUpdateRule = $pdo->prepare("
            UPDATE member_activities
            SET rule_id = :rule_id
            WHERE id = :id
              AND rule_id IS NULL
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
                $text = (string)($a['activity_text'] ?? '');
                $details = (string)($a['activity_details'] ?? '');

                $match = ah_match_rule($text, $details, $rules);
                if ($match === null) continue;

                $stUpdateRule->execute([
                    ':rule_id' => (int)$match['id'],
                    ':id' => (int)$a['id'],
                ]);

                if ((int)$stUpdateRule->rowCount() === 1) {
                    $res['updated_rule_id']++;
                }

                $purpose = (string)$match['purpose'];
                if ($purpose === 'cap_detection' || $purpose === 'visit_detection') {
                    $atUtc = ah_dt_from_db_utc((string)$a['activity_date_utc']);

                    [$startUtc, $endUtc] = ah_cap_week_bounds_utc(
                        $atUtc,
                        (string)$clan['timezone'],
                        (int)$clan['reset_weekday'],
                        (string)$clan['reset_time']
                    );

                    $payload = [
                        ':clan_id' => $clanId,
                        ':member_id' => (int)$a['member_id'],
                        ':start_utc' => $startUtc->format('Y-m-d H:i:s.v'),
                        ':end_utc' => $endUtc->format('Y-m-d H:i:s.v'),
                        ':at_utc' => $atUtc->format('Y-m-d H:i:s.v'),
                    ];

                    if ($purpose === 'cap_detection') {
                        $stCap->execute($payload);
                        $res['caps_upserted']++;
                    } else {
                        $stVisit->execute($payload);
                        $res['visits_upserted']++;
                    }
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $res['ok'] = true;
        return $res;

    } catch (Throwable $e) {
        $res['error'] = $e->getMessage();
        return $res;
    }
}

/**
 * Convenience: process all enabled clans.
 *
 * @return array<int, array> keyed by clan_id
 */
function process_activities_for_all_clans(PDO $pdo, int $limitPerClan = 1000): array
{
    $out = [];

    $stmt = $pdo->query("
        SELECT id
        FROM clans
        WHERE is_enabled = 1
          AND inactive_at IS NULL
        ORDER BY id ASC
    ");

    $clanIds = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    foreach ($clanIds as $cid) {
        $cid = (int)$cid;
        $out[$cid] = process_activities_for_clan($pdo, $cid, $limitPerClan);
    }
    return $out;
}

/* ============================================================
   DB loaders
   ============================================================ */

function pa_db_load_clan_reset(PDO $pdo, int $clanId): ?array
{
    $st = $pdo->prepare("SELECT id, timezone, reset_weekday, reset_time FROM clans WHERE id = :id LIMIT 1");
    $st->execute([':id' => $clanId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}


/**
 * Load enabled rules for a clan, including global rules (clan_id IS NULL).
 * Clan-specific rules take priority over global rules.
 */
function pa_db_load_enabled_rules(PDO $pdo, int $clanId): array
{
    $st = $pdo->prepare("
        SELECT
          id,
          clan_id,
          purpose,
          match_kind,
          match_value,
          message_template,
          discord_announcement_channel_id,
          is_enabled,
          created_at,
          updated_at
        FROM activity_announcement_rules
        WHERE is_enabled = 1
          AND (clan_id = :clan_id OR clan_id IS NULL)
        ORDER BY (clan_id IS NULL) ASC, id ASC
    ");
    $st->execute([':clan_id' => $clanId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}


function pa_db_fetch_unruled_activities(PDO $pdo, int $clanId, int $limit): array
{
    $limit = max(1, min(5000, (int)$limit));

    $sql = "
        SELECT id, member_id, member_clan_id, activity_date_utc, activity_text, activity_details
        FROM member_activities
        WHERE member_clan_id = :clan_id
          AND rule_id IS NULL
        ORDER BY activity_date_utc DESC, id DESC
        LIMIT {$limit}
    ";

    $st = $pdo->prepare($sql);
    $st->execute([':clan_id' => $clanId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
