<?php
declare(strict_types=1);

require_once __DIR__ . '/_env.php';

/**
 * Try common .env locations (outside web root first)
 */
tracker_load_env(dirname(__DIR__, 2) . '/.env'); // e.g. /home/username/.env
tracker_load_env(dirname(__DIR__) . '/.env');    // /public_html/.env (fallback)


/**
 * tracker API - DB helper
 *
 * Expects environment variables (recommended on cPanel):
 *   TRACKER_DB_HOST
 *   TRACKER_DB_NAME
 *   TRACKER_DB_USER
 *   TRACKER_DB_PASS
 * Optional:
 *   TRACKER_DB_CHARSET (default utf8mb4)
 */

function tracker_json(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tracker_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $host = getenv('TRACKER_DB_HOST') ?: '';
    $name = getenv('TRACKER_DB_NAME') ?: '';
    $user = getenv('TRACKER_DB_USER') ?: '';
    $pass = getenv('TRACKER_DB_PASS') ?: '';
    $charset = getenv('TRACKER_DB_CHARSET') ?: 'utf8mb4';

    if ($host === '' || $name === '' || $user === '') {
        tracker_json([
            'ok' => false,
            'error' => 'DB env vars not configured. Set TRACKER_DB_HOST/NAME/USER/PASS.'
        ], 500);
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $host, $name, $charset);

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    } catch (Throwable $e) {
        tracker_json([
            'ok' => false,
            'error' => 'DB connection failed',
        ], 500);
    }
}

/**
 * Basic normalisation for searching (matches your rsn_normalised concept).
 */
function tracker_normalise(string $s): string {
    $s = trim($s);
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    $s = mb_strtolower($s);
    return $s;
}

/**
 * Read and sanitise ?q=
 */
function tracker_get_q(int $minLen = 1, int $maxLen = 64): string {
    $q = (string)($_GET['q'] ?? '');
    $q = trim($q);
    if ($q === '') return '';
    if (mb_strlen($q) < $minLen) return '';
    if (mb_strlen($q) > $maxLen) $q = mb_substr($q, 0, $maxLen);
    return $q;
}
