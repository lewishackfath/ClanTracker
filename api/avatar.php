<?php
declare(strict_types=1);

$player = $_GET['player'] ?? '';
$player = trim($player);

if ($player === '') {
    http_response_code(400);
    exit;
}

// Normalise RSN for RuneScape avatar API
$normalised = str_replace(' ', '_', $player);
$encoded = rawurlencode($normalised);

$cacheDir = __DIR__ . '/../assets/avatars';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

$cacheFile = $cacheDir . '/' . $normalised . '.png';

if (!file_exists($cacheFile) || filesize($cacheFile) === 0) {
    $url = "https://secure.runescape.com/m=avatar-rs/{$encoded}/chat.png";
    $img = @file_get_contents($url);
    if ($img !== false && strlen($img) > 0) {
        file_put_contents($cacheFile, $img);
    }
}

if (file_exists($cacheFile) && filesize($cacheFile) > 0) {
    header('Content-Type: image/png');
    readfile($cacheFile);
    exit;
}

http_response_code(404);
