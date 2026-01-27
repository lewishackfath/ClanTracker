<?php
// api/ge/_bootstrap.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function ge_json_exit(array $payload, int $code = 200): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

/**
 * Load .env from project root (walk upwards a few levels).
 * Compatible with PHP 7.4+.
 */
function ge_load_env(): void {
  $candidates = [
    __DIR__ . '/../../.env',
    __DIR__ . '/../../../.env',
    __DIR__ . '/../../../../.env',
    __DIR__ . '/../../../../../.env',
  ];

  $envPath = null;
  foreach ($candidates as $p) {
    if (is_file($p)) { $envPath = $p; break; }
  }
  if ($envPath === null) return;

  $lines = @file($envPath, FILE_IGNORE_NEW_LINES);
  if (!is_array($lines)) return;

  foreach ($lines as $line) {
    $line = trim((string)$line);
    if ($line === '') continue;
    if (substr($line, 0, 1) === '#') continue;

    $pos = strpos($line, '=');
    if ($pos === false) continue;

    $key = trim(substr($line, 0, $pos));
    $val = trim(substr($line, $pos + 1));
    if ($key === '') continue;

    // Strip surrounding quotes
    if (strlen($val) >= 2) {
      $first = substr($val, 0, 1);
      $last  = substr($val, -1);
      if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
        $val = substr($val, 1, -1);
      }
    }

    // Don't overwrite existing env
    if (getenv($key) === false) {
      putenv($key . '=' . $val);
    }
    if (!isset($_ENV[$key])) {
      $_ENV[$key] = $val;
    }
  }
}

ge_load_env();

function ge_env(string $key, $default = null) {
  $v = getenv($key);
  if ($v !== false) return $v;
  if (isset($_ENV[$key])) return $_ENV[$key];
  return $default;
}

/**
 * Support both styles:
 *  - DB_HOST / DB_NAME / DB_USER / DB_PASS / DB_PORT / DB_CHARSET
 *  - TRACKER_DB_HOST / TRACKER_DB_NAME / TRACKER_DB_USER / TRACKER_DB_PASS / TRACKER_DB_PORT / TRACKER_DB_CHARSET
 */
function ge_db_get(string $key, $default = null) {
  $v = ge_env($key, null);
  if ($v !== null && $v !== '') return $v;

  // Fallback to TRACKER_ prefix used by tracker app
  $v2 = ge_env('TRACKER_' . $key, null);
  if ($v2 !== null && $v2 !== '') return $v2;

  return $default;
}

function ge_pdo(): PDO {
  $host = (string)ge_db_get('DB_HOST', '');
  $name = (string)ge_db_get('DB_NAME', '');
  $user = (string)ge_db_get('DB_USER', '');
  $pass = (string)ge_db_get('DB_PASS', '');
  $port = (int)ge_db_get('DB_PORT', 3306);
  $charset = (string)ge_db_get('DB_CHARSET', 'utf8mb4');

  if ($host === '' || $name === '' || $user === '') {
    ge_json_exit([
      'ok' => false,
      'error' => 'Database environment variables missing',
      'expected' => [
        'DB_HOST','DB_NAME','DB_USER','DB_PASS','DB_PORT','DB_CHARSET',
        'TRACKER_DB_HOST','TRACKER_DB_NAME','TRACKER_DB_USER','TRACKER_DB_PASS','TRACKER_DB_PORT','TRACKER_DB_CHARSET'
      ]
    ], 500);
  }

  $dsn = 'mysql:host=' . $host . ';dbname=' . $name . ';port=' . $port . ';charset=' . $charset;

  return new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
}

function ge_http_get_json(string $url, int $timeoutSeconds = 12): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
    CURLOPT_TIMEOUT => $timeoutSeconds,
    CURLOPT_ENCODING => '',
    CURLOPT_USERAGENT => '24KRS-CapCheck-GE/1.0 (+24krs.com.au)',
    CURLOPT_HTTPHEADER => [
      'Accept: application/json',
      'Accept-Encoding: gzip, deflate',
      'Cache-Control: no-cache',
    ],
  ]);

  $raw = curl_exec($ch);
  $errno = curl_errno($ch);
  $err = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  curl_close($ch);

  if ($raw === false) {
    return [
      '__ok' => false,
      '__error' => $err ?: 'curl error',
      '__errno' => $errno,
      '__code' => 0,
    ];
  }

  // Strip UTF-8 BOM + leading whitespace
  if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
    $raw = substr($raw, 3);
  }
  $body = ltrim($raw);

  if ($code < 200 || $code >= 300) {
    return [
      '__ok' => false,
      '__error' => 'HTTP ' . $code,
      '__code' => $code,
      '__content_type' => $contentType,
      '__body' => substr($body, 0, 800),
    ];
  }

  $json = json_decode($body, true);
  if (!is_array($json)) {
    return [
      '__ok' => false,
      '__error' => 'Invalid JSON from upstream',
      '__code' => $code,
      '__content_type' => $contentType,
      '__json_error' => function_exists('json_last_error_msg') ? json_last_error_msg() : (string)json_last_error(),
      '__body' => substr($body, 0, 800),
    ];
  }

  $json['__ok'] = true;
  $json['__code'] = $code;
  $json['__content_type'] = $contentType;
  return $json;
}

function ge_now_utc(): string {
  return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
}

const GE_BASE = 'https://services.runescape.com/m=itemdb_rs';
