<?php
declare(strict_types=1);

/**
 * Proxies RuneScape Wiki item icons and caches them locally.
 * Usage: /api/wiki_item_icon.php?item=Dragon%20helm
 *
 * Returns:
 *  - 200 image (cached or freshly fetched)
 *  - 404 if not found / fetch failed
 */

header('X-Content-Type-Options: nosniff');

function param(string $k, int $maxLen = 120): string {
    $v = (string)($_GET[$k] ?? '');
    $v = trim($v);
    if ($v === '') return '';
    if (mb_strlen($v) > $maxLen) $v = mb_substr($v, 0, $maxLen);
    return $v;
}

function normalise_item(string $item): string {
    $item = trim($item);
    $item = preg_replace('/\s+/', ' ', $item) ?? $item;
    return $item;
}

function file_safe(string $s): string {
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '_', $s) ?? $s;
    $s = trim($s, '_');
    if ($s === '') $s = 'item';
    return $s;
}

function ensure_dir(string $dir): void {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

function send_404(): void {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found";
    exit;
}

$item = param('item');
if ($item === '') send_404();

$item = normalise_item($item);

// Cache folder
$cacheDir = __DIR__ . '/../cache/item-icons';
ensure_dir($cacheDir);

// Cache key
$cacheKey = file_safe($item) . '_' . substr(sha1($item), 0, 10);
$cachePath = $cacheDir . '/' . $cacheKey . '.png';

// Serve cached if fresh
$maxAgeSeconds = 60 * 60 * 24 * 14; // 14 days
if (is_file($cachePath)) {
    $age = time() - (int)@filemtime($cachePath);
    if ($age >= 0 && $age < $maxAgeSeconds) {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400');
        readfile($cachePath);
        exit;
    }
}

// Candidate file names (wiki uses underscores)
$underscored = str_replace(' ', '_', $item);

// Try a few variations
$candidates = [
    $underscored . '.png',
    $item . '.png',
    ucfirst($underscored) . '.png',
    ucfirst($item) . '.png',
];

// Correct direct image host
$directBase = 'https://runescape.wiki/images/';
// Fallback (sometimes helpful)
$filePathBase = 'https://runescape.wiki/w/Special:FilePath/';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 12,
    CURLOPT_USERAGENT => '24K Tracker (tracker.24krs.com.au) icon fetch',
    CURLOPT_SSL_VERIFYPEER => true,
]);

$imgData = null;

foreach ($candidates as $fn) {
    // 1) Try direct image URL first (your corrected format)
    $url = $directBase . rawurlencode($fn);
    curl_setopt($ch, CURLOPT_URL, $url);
    $body = curl_exec($ch);

    if ($body !== false) {
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        if ($code >= 200 && $code < 300 && str_starts_with($ctype, 'image/')) {
            $imgData = $body;
            break;
        }
    }

    // 2) Fallback: Special:FilePath (kept as backup)
    $url = $filePathBase . rawurlencode($fn);
    curl_setopt($ch, CURLOPT_URL, $url);
    $body = curl_exec($ch);

    if ($body !== false) {
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        if ($code >= 200 && $code < 300 && str_starts_with($ctype, 'image/')) {
            $imgData = $body;
            break;
        }
    }
}

curl_close($ch);

if ($imgData === null) {
    send_404();
}

// Cache (best-effort)
@file_put_contents($cachePath, $imgData);

// Serve
header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
echo $imgData;
exit;
