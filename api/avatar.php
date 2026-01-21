<?php
declare(strict_types=1);

require_once __DIR__ . '/_db.php';

// GET /api/avatar.php?player=RSN

function avatar_get_param(string $key, int $maxLen = 64): string {
    $v = (string)($_GET[$key] ?? '');
    $v = trim($v);
    if ($v === '') return '';
    if (mb_strlen($v) > $maxLen) $v = mb_substr($v, 0, $maxLen);
    return $v;
}

function avatar_safe_filename(string $rsn): string {
    // Keep it readable while avoiding path traversal / illegal characters.
    $s = trim($rsn);
    // Replace filesystem-unfriendly chars
    $s = preg_replace('/[\\\/\:\*\?\"\<\>\|]+/', '_', $s) ?? $s;
    // Collapse whitespace
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    $s = trim($s);
    if ($s === '') $s = 'unknown';
    return $s;
}

function avatar_fetch_png(string $url, int $timeout = 10): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => '24KRS ClanTracker (+https://tracker.24krs.com.au)'
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ct   = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($body === false) return [false, null, $code, $err ?: 'cURL error'];
    if ($code !== 200) return [false, null, $code, 'HTTP ' . $code];

    // Some edge cases return text/html; we still accept if it looks like PNG
    $isPng = (strpos($ct, 'image/png') !== false) || (strncmp((string)$body, "\x89PNG", 4) === 0);
    if (!$isPng) return [false, null, $code, 'Unexpected content type'];

    return [true, (string)$body, $code, null];
}

try {
    $rsn = avatar_get_param('player', 64);
    if ($rsn === '') tracker_json(['ok' => false, 'error' => 'Missing player'], 400);

    $root = dirname(__DIR__); // project root (where index.html lives)
    $dir = $root . '/assets/avatars';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $fileBase = avatar_safe_filename($rsn);
    $path = $dir . '/' . $fileBase . '.png';

    // Cache for 7 days
    $maxAgeSeconds = 7 * 24 * 60 * 60;
    $isFresh = is_file($path) && (time() - (int)filemtime($path) < $maxAgeSeconds);

    if (!$isFresh) {
        $url = 'https://secure.runescape.com/m=avatar-rs/' . rawurlencode($rsn) . '/chat.png';
        [$ok, $png, $code, $err] = avatar_fetch_png($url, 10);

        if ($ok && is_string($png) && $png !== '') {
            // Best-effort write
            @file_put_contents($path, $png);
        } elseif (!is_file($path)) {
            // No cache + fetch failed
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            echo json_encode([
                'ok' => false,
                'error' => 'Avatar not found',
                'hint' => $err ?: ('HTTP ' . (string)$code),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // Serve cached file
    $size = @filesize($path);
    $mtime = @filemtime($path);
    $etag = $mtime && $size ? 'W/"' . $mtime . '-' . $size . '"' : null;

    if ($etag && isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string)$_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        header('ETag: ' . $etag);
        header('Cache-Control: public, max-age=86400');
        exit;
    }

    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    if ($etag) header('ETag: ' . $etag);

    // Output bytes
    readfile($path);
    exit;

} catch (Throwable $e) {
    tracker_json([
        'ok' => false,
        'error' => 'Avatar endpoint failed',
        'hint' => $e->getMessage(),
    ], 500);
}
