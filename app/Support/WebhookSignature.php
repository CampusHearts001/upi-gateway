<?php

namespace App\Support;

class WebhookSignature
{
    public const DEFAULT_TOLERANCE_SECONDS = 300; // 5 minutes

    /**
     * Compute timestamped replay-safe webhook signature.
     *
     * @return array{header: string, timestamp: int, signature: string, legacy_signature: string}
     */
    public static function sign(string $body, string $secret, ?int $timestamp = null): array
    {
        $timestamp = $timestamp ?? time();
        $signedPayload = "{$timestamp}.{$body}";
        $signature = hash_hmac('sha256', $signedPayload, $secret);
        $header = "t={$timestamp},v1={$signature}";
        $legacy = hash_hmac('sha256', $body, $secret);

        return [
            'header' => $header,
            'timestamp' => $timestamp,
            'signature' => $signature,
            'legacy_signature' => $legacy,
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

        // Modern scheme: t=timestamp,v1=signature
        if (isset($parts['t'], $parts['v1'])) {
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

        // Backward compatibility fallback: raw hex HMAC of body
        $expectedLegacy = hash_hmac('sha256', $body, $secret);

        return hash_equals($expectedLegacy, trim($signatureHeader));
    }

    /**
     * Parse key=value pairs from the signature header.
     *
     * @return array<string, string>
     */
    public static function parseHeader(string $header): array
    {
        $items = [];
        foreach (explode(',', $header) as $pair) {
            $parts = explode('=', trim($pair), 2);
            if (count($parts) === 2) {
                $items[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $items;
    }
}
