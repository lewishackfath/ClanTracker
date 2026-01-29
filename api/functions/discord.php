<?php
declare(strict_types=1);

/**
 * /functions/discord.php
 *
 * Minimal Discord REST helper for SENDING messages only.
 *
 * This file intentionally does NOT:
 * - format messages
 * - decide which channel to use
 * - decide who to ping
 * - manage announcement state
 *
 * It just sends a payload to Discord and returns a structured result.
 *
 * Requirements:
 * - A Discord Bot token with permission to post in the target channel.
 *
 * Example:
 *   require_once __DIR__ . '/functions/discord.php';
 *   $res = discord_send_message($botToken, $channelId, "Hello world");
 */

date_default_timezone_set('UTC');

/**
 * Convert role IDs to Discord mention strings: <@&ROLE_ID>
 */
function discord_role_mentions(array $roleIds): string
{
    $roleIds = array_values(array_filter(array_map('strval', $roleIds)));
    if (!$roleIds) return '';
    return implode(' ', array_map(static fn(string $id): string => "<@&{$id}>", $roleIds));
}

/**
 * Send a message to a Discord channel via Bot token.
 *
 * @param string $botToken  Discord Bot token (WITHOUT the "Bot " prefix)
 * @param string $channelId Discord channel ID (snowflake, digits as string)
 * @param string $content   Message content (0-2000 chars). Can be '' if you provide embeds.
 * @param array  $options   Optional payload fields:
 *   - embeds: array (Discord embed objects)
 *   - allowed_mentions: array (Discord allowed_mentions object)
 *   - tts: bool
 *
 * @return array{
 *   ok:bool,
 *   status:?int,
 *   error:?string,
 *   retry_after_ms:?int,
 *   body_raw:?string,
 *   body_json:mixed
 * }
 */
function discord_send_message(string $botToken, string $channelId, string $content, array $options = []): array
{
    $url = "https://discord.com/api/v10/channels/" . rawurlencode($channelId) . "/messages";

    $payloadArr = [
        'content' => $content,
        // Safe default: allow role mentions only if explicitly included in content via <@&id>
        'allowed_mentions' => $options['allowed_mentions'] ?? ['parse' => ['roles']],
    ];

    if (isset($options['embeds'])) {
        $payloadArr['embeds'] = $options['embeds'];
    }
    if (isset($options['tts'])) {
        $payloadArr['tts'] = (bool)$options['tts'];
    }

    $payload = json_encode($payloadArr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if (!is_string($payload) || $payload === '') {
        return [
            'ok' => false,
            'status' => null,
            'error' => 'Failed to JSON encode Discord payload',
            'retry_after_ms' => null,
            'body_raw' => null,
            'body_json' => null,
        ];
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return [
            'ok' => false,
            'status' => null,
            'error' => 'curl_init failed',
            'retry_after_ms' => null,
            'body_raw' => null,
            'body_json' => null,
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bot ' . $botToken,
            'User-Agent: RS24K-Tracker/1.0',
        ],
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        return [
            'ok' => false,
            'status' => null,
            'error' => "cURL error: {$err}",
            'retry_after_ms' => null,
            'body_raw' => null,
            'body_json' => null,
        ];
    }

    // Try parse JSON response (Discord replies JSON for both success + error)
    $json = json_decode((string)$body, true);

    // Handle rate limiting: Discord returns 429 with JSON including retry_after (seconds) and sometimes global.
    $retryAfterMs = null;
    if ($code === 429 && is_array($json)) {
        $retry = $json['retry_after'] ?? null;
        if (is_numeric($retry)) {
            $retryAfterMs = (int)round(((float)$retry) * 1000);
        }
    }

    if ($code < 200 || $code >= 300) {
        $msg = "Discord HTTP {$code}";
        if (is_array($json) && isset($json['message'])) {
            $msg .= ": " . (string)$json['message'];
        }
        return [
            'ok' => false,
            'status' => $code,
            'error' => $msg,
            'retry_after_ms' => $retryAfterMs,
            'body_raw' => (string)$body,
            'body_json' => $json,
        ];
    }

    return [
        'ok' => true,
        'status' => $code,
        'error' => null,
        'retry_after_ms' => null,
        'body_raw' => (string)$body,
        'body_json' => $json,
    ];
}
