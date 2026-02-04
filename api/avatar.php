<?php
declare(strict_types=1);

/**
 * avatar.php
 *
 * Caches RuneScape chathead avatars locally, but also re-fetches periodically so
 * avatar changes get picked up automatically.
 *
 * Usage:
 *   /api/avatar.php?player=Some_Name
 *   /api/avatar.php?player=Some_Name&force=1   (forces a refresh)
 */

$player = $_GET['player'] ?? '';
$player = trim((string)$player);

if ($player === '') {
    http_response_code(400);
    exit;
}

// Normalise RSN for RuneScape avatar API + cache filename
// (RuneScape endpoint expects underscores)
$normalised = preg_replace('/\s+/', '_', $player);

// Make filesystem-safe (keep underscores)
$safe = preg_replace('/[\\\\\\/\\:\\*\\?\\"\\<\\>\\|]+/', '_', $normalised);
$safe = trim($safe);
if ($safe === '') {
    http_response_code(400);
    exit;
}

$encoded = rawurlencode($normalised);

$cacheDir = __DIR__ . '/../assets/avatars';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

$cacheFile = $cacheDir . '/' . $safe . '.png';
$etagFile  = $cacheFile . '.etag';
$lockFile  = $cacheFile . '.lock';

// Refresh rules
$force = isset($_GET['force']) && (string)$_GET['force'] === '1';
$ttlSeconds = 6 * 60 * 60; // 6 hours
$now = time();

$hasCached = (is_file($cacheFile) && filesize($cacheFile) > 0);
$age = $hasCached ? ($now - (int)filemtime($cacheFile)) : PHP_INT_MAX;
$needsRefresh = $force || (!$hasCached) || ($age >= $ttlSeconds);

// Acquire a lock so multiple requests don't all download at once
$lockHandle = @fopen($lockFile, 'c');
if ($lockHandle) {
    @flock($lockHandle, LOCK_EX);
}

/**
 * Download avatar image with conditional headers (ETag / If-Modified-Since).
 * Returns:
 *   - 'updated'  => file updated
 *   - 'notmod'   => remote says not modified
 *   - 'failed'   => download failed
 */
function refresh_avatar(string $url, string $cacheFile, string $etagFile): string {
    $ifModifiedSince = null;
    if (is_file($cacheFile) && filesize($cacheFile) > 0) {
        $mt = (int)@filemtime($cacheFile);
        if ($mt > 0) $ifModifiedSince = gmdate('D, d M Y H:i:s', $mt) . ' GMT';
    }

    $etag = null;
    if (is_file($etagFile)) {
        $etag = trim((string)@file_get_contents($etagFile));
        if ($etag === '') $etag = null;
    }

    // Prefer cURL if available (lets us read status codes reliably)
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $headers = [
            'User-Agent: 24KRS Avatar Cache',
            'Accept: image/png,image/*;q=0.9,*/*;q=0.8',
        ];
        if ($ifModifiedSince) $headers[] = 'If-Modified-Since: ' . $ifModifiedSince;
        if ($etag) $headers[] = 'If-None-Match: ' . $etag;

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADER => true, // include headers in output
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            curl_close($ch);
            return 'failed';
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headerBlob = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);

        if ($status === 304) {
            return 'notmod';
        }

        if ($status >= 200 && $status < 300 && $body !== '' && strlen($body) > 0) {
            // Grab ETag if present
            if (preg_match('/^etag:\s*(.+)\r?$/im', $headerBlob, $m)) {
                $newEtag = trim($m[1]);
                if ($newEtag !== '') @file_put_contents($etagFile, $newEtag);
            }

            // Write atomically
            $tmp = $cacheFile . '.tmp';
            if (@file_put_contents($tmp, $body) !== false && filesize($tmp) > 0) {
                @rename($tmp, $cacheFile);
                return 'updated';
            }
            @unlink($tmp);
        }

        return 'failed';
    }

    // Fallback: streams (best-effort; status derived from $http_response_header)
    $headers = [
        'User-Agent: 24KRS Avatar Cache',
        'Accept: image/png,image/*;q=0.9,*/*;q=0.8',
    ];
    if ($ifModifiedSince) $headers[] = 'If-Modified-Since: ' . $ifModifiedSince;
    if ($etag) $headers[] = 'If-None-Match: ' . $etag;

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => 10,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $ctx);
    $status = 0;

    if (isset($http_response_header) && is_array($http_response_header) && isset($http_response_header[0])) {
        if (preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $status = (int)$m[1];
        }
        if (preg_match('/^etag:\s*(.+)\r?$/im', implode("\n", $http_response_header), $m2)) {
            $newEtag = trim($m2[1]);
            if ($newEtag !== '') @file_put_contents($etagFile, $newEtag);
        }
    }

    if ($status === 304) return 'notmod';

    if ($status >= 200 && $status < 300 && $body !== false && $body !== '' && strlen((string)$body) > 0) {
        $tmp = $cacheFile . '.tmp';
        if (@file_put_contents($tmp, (string)$body) !== false && filesize($tmp) > 0) {
            @rename($tmp, $cacheFile);
            return 'updated';
        }
        @unlink($tmp);
    }

    return 'failed';
}

// Refresh if needed
if ($needsRefresh) {
    $url = "https://secure.runescape.com/m=avatar-rs/{$encoded}/chat.png";
    refresh_avatar($url, $cacheFile, $etagFile);
}

// Serve (even if refresh failed, serve stale cache if we have it)
if (is_file($cacheFile) && filesize($cacheFile) > 0) {
    $mtime = (int)@filemtime($cacheFile);
    $lastModified = $mtime > 0 ? gmdate('D, d M Y H:i:s', $mtime) . ' GMT' : null;

    // Client cache validation
    if ($lastModified && isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
        $ims = strtotime((string)$_SERVER['HTTP_IF_MODIFIED_SINCE']);
        if ($ims !== false && $mtime > 0 && $ims >= $mtime) {
            header('HTTP/1.1 304 Not Modified');
            if ($lockHandle) { @flock($lockHandle, LOCK_UN); @fclose($lockHandle); }
            exit;
        }
    }

    header('Content-Type: image/png');

    // Allow short browser caching, but revalidate frequently
    header('Cache-Control: public, max-age=300, must-revalidate');
    if ($lastModified) header('Last-Modified: ' . $lastModified);

    // If we have an ETag saved, return it to the browser too
    if (is_file($etagFile)) {
        $etag = trim((string)@file_get_contents($etagFile));
        if ($etag !== '') header('ETag: ' . $etag);
    }

    readfile($cacheFile);

    if ($lockHandle) { @flock($lockHandle, LOCK_UN); @fclose($lockHandle); }
    exit;
}

if ($lockHandle) { @flock($lockHandle, LOCK_UN); @fclose($lockHandle); }
http_response_code(404);
