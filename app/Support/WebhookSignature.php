<?php

namespace App\Support;

class WebhookSignature
{
    public const DEFAULT_TOLERANCE_SECONDS = 300; // 5 minutes

    /**
     * Compute timestamped replay-safe webhook signature.
     *
     * @return array{header: string, timestamp: int, signature: string}
     */
    public static function sign(string $body, string $secret, ?int $timestamp = null): array
    {
        $timestamp = $timestamp ?? time();
        $signedPayload = "{$timestamp}.{$body}";
        $signature = hash_hmac('sha256', $signedPayload, $secret);
        $header = "t={$timestamp},v1={$signature}";

        return [
            'header' => $header,
            'timestamp' => $timestamp,
            'signature' => $signature,
        ];
    }

    /**
     * Verify an incoming webhook signature with timestamp tolerance replay defense.
     */
    public static function verify(
        string $body,
        ?string $signatureHeader,
        string $secret,
        int $tolerance = self::DEFAULT_TOLERANCE_SECONDS,
        ?int $currentTime = null
    ): bool {
        if (! $signatureHeader || trim($signatureHeader) === '' || trim($secret) === '') {
            return false;
        }

        $currentTime = $currentTime ?? time();
        $parts = self::parseHeader($signatureHeader);

        // Required scheme: t=timestamp,v1=signature
        if (! isset($parts['t'], $parts['v1']) || ! ctype_digit((string) $parts['t'])) {
            return false;
        }

        if (strlen($parts['v1']) !== 64 || ! ctype_xdigit($parts['v1'])) {
            return false;
        }

        $timestamp = (int) $parts['t'];
        if ($timestamp <= 0) {
            return false;
        }

        // Verify timestamp is within tolerance window (5 minutes by default)
        if (abs($currentTime - $timestamp) > $tolerance) {
            return false;
        }

        $signedPayload = "{$timestamp}.{$body}";
        $expected = hash_hmac('sha256', $signedPayload, $secret);

        return hash_equals($expected, $parts['v1']);
    }

    /**
     * Parse key=value pairs from the signature header.
     *
     * Rejects headers with unexpected parameters, duplicate keys,
     * empty values, malformed non-pair segments, or multiple equals signs.
     *
     * @return array<string, string>
     */
    public static function parseHeader(string $header): array
    {
        $header = trim($header);
        if ($header === '') {
            return [];
        }

        $items = [];
        $allowedKeys = ['t', 'v1'];

        foreach (explode(',', $header) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                // Empty segment (e.g. double comma or trailing comma)
                return [];
            }

            $parts = explode('=', $pair);
            if (count($parts) !== 2) {
                // Malformed segment: missing "=" or multiple "=" (e.g. "unexpected", "t=1=2")
                return [];
            }

            $key = trim($parts[0]);
            $val = trim($parts[1]);

            if ($key === '' || $val === '') {
                // Empty key or empty value
                return [];
            }

            if (! in_array($key, $allowedKeys, true)) {
                // Unexpected parameter (e.g. "foo=bar", "unexpected")
                return [];
            }

            if (isset($items[$key])) {
                // Duplicate parameter detected — reject header to prevent pollution attacks
                return [];
            }

            $items[$key] = $val;
        }

        return $items;
    }
}
