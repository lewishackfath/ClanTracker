<?php
declare(strict_types=1);

/**
 * /functions/activity_helpers.php
 *
 * Shared helpers for:
 * - Loading enabled activity rules for a clan
 * - Matching an activity to a rule (first match wins)
 * - Cap-week bounds (UTC) from clan reset settings
 */

date_default_timezone_set('UTC');

/**
 * Loads enabled rules for a clan.
 * Ordered so cap/visit rules win first.
 *
 * @return array<int, array{id:int, clan_id:?int, purpose:string, match_kind:string, match_value:string}>
 */
function ah_load_enabled_rules(PDO $pdo, int $clanId): array
{
    $st = $pdo->prepare("
        SELECT id, clan_id, purpose, match_kind, match_value
        FROM activity_announcement_rules
        WHERE (clan_id = :clan_id OR clan_id IS NULL)
          AND is_enabled = 1
        ORDER BY
          (clan_id IS NULL) ASC,
          CASE purpose
            WHEN 'cap_detection' THEN 0
            WHEN 'visit_detection' THEN 1
            ELSE 10
          END,
          id ASC
    ");
    $st->execute([':clan_id' => $clanId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $rules = [];
    foreach ($rows as $r) {
        $rules[] = [
            'id' => (int)$r['id'],
            'clan_id' => (isset($r['clan_id']) ? (is_null($r['clan_id']) ? null : (int)$r['clan_id']) : null),
            'purpose' => (string)$r['purpose'],
            'match_kind' => (string)$r['match_kind'],
            'match_value' => (string)$r['match_value'],
        ];
    }
    return $rules;
}

/**
 * Returns first matching rule or null.
 *
 * match_kind supported:
 * - text_equals, text_contains, text_regex
 * - details_equals, details_contains, details_regex
 * - contains/combined_contains, equals/combined_equals, regex/combined_regex
 */

/**
 * Normalise a regex pattern stored in DB.
 * If the value already looks like a delimited regex (e.g. /.../i), it is returned as-is.
 * Otherwise we wrap it in '/' delimiters and add the 'i' flag for case-insensitive matching.
 */
function ah_normalise_regex(string $val): string
{
    $val = trim($val);
    if ($val === '') return $val;

    // Looks like /pattern/flags
    if (strlen($val) >= 2 && $val[0] === '/') {
        // find last unescaped slash
        $last = strrpos($val, '/');
        if ($last !== 0) {
            return $val;
        }
    }

    // Escape existing slashes to avoid delimiter collision
    $escaped = str_replace('/', '\/', $val);
    return '/' . $escaped . '/i';
}

function ah_match_rule(string $text, string $details, array $rules): ?array
{
    $combined = $text . "\n" . $details;

    foreach ($rules as $r) {
        $kind = strtolower(trim((string)$r['match_kind']));
        $val  = (string)$r['match_value'];
        if ($val === '') continue;

        $ok = false;

        switch ($kind) {
            case 'text_equals':
                $ok = ($text === $val);
                break;

            case 'text_contains':
                $ok = (stripos($text, $val) !== false);
                break;

            case 'text_regex':
                $ok = @preg_match(ah_normalise_regex($val), $text) === 1;
                break;

            case 'details_equals':
                $ok = ($details === $val);
                break;

            case 'details_contains':
                $ok = (stripos($details, $val) !== false);
                break;

            case 'details_regex':
                $ok = @preg_match(ah_normalise_regex($val), $details) === 1;
                break;

            case 'contains':
            case 'combined_contains':
                $ok = (stripos($combined, $val) !== false);
                break;

            case 'equals':
            case 'combined_equals':
                $ok = (trim($combined) === trim($val));
                break;

            case 'regex':
            case 'combined_regex':
                $ok = @preg_match(ah_normalise_regex($val), $combined) === 1;
                break;

            default:
                $ok = false;
                break;
        }

        if ($ok) return $r;
    }

    return null;
}

/**
 * Parse DB DATETIME(3) string to UTC DateTimeImmutable.
 */
function ah_dt_from_db_utc(string $dt): DateTimeImmutable
{
    $dt = trim($dt);
    if ($dt === '') {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    $fmt = 'Y-m-d H:i:s.v';
    $d = DateTimeImmutable::createFromFormat($fmt, $dt, new DateTimeZone('UTC'));
    if ($d instanceof DateTimeImmutable) return $d;

    return new DateTimeImmutable($dt, new DateTimeZone('UTC'));
}

/**
 * Compute cap-week bounds in UTC using clan reset weekday/time + timezone.
 * reset_weekday uses PHP 'w' convention: 0=Sun..6=Sat.
 *
 * @return array{0:DateTimeImmutable,1:DateTimeImmutable}
 */
function ah_cap_week_bounds_utc(
    DateTimeImmutable $activityUtc,
    string $clanTimezone,
    int $resetWeekday,
    string $resetTime
): array {
    $tz = new DateTimeZone($clanTimezone ?: 'UTC');

    $local = $activityUtc->setTimezone($tz);
    $localW = (int)$local->format('w');

    $diffDays = ($localW - $resetWeekday + 7) % 7;
    $candidate = $local->modify("-{$diffDays} days");

    $parts = explode(':', $resetTime);
    $hh = (int)($parts[0] ?? 0);
    $mm = (int)($parts[1] ?? 0);
    $ss = (int)($parts[2] ?? 0);

    $resetThisWeek = $candidate->setTime($hh, $mm, $ss, 0);

    if ($local < $resetThisWeek) {
        $resetThisWeek = $resetThisWeek->modify('-7 days');
    }

    $startUtc = $resetThisWeek->setTimezone(new DateTimeZone('UTC'));
    $endUtc = $startUtc->modify('+7 days');

    return [$startUtc, $endUtc];
}
