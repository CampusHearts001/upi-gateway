<?php

namespace App\Services\Verifiers;

use App\Models\Order;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class VerificationResult
{
    private function __construct(
        public readonly bool $paid,
        public readonly ?string $utr = null,
        public readonly ?string $txnId = null,
        public readonly array $raw = [],
        public readonly ?string $provider = null,
        public readonly ?int $orderId = null,
        public readonly ?string $orderNumber = null,
        public readonly ?string $amount = null,
        public readonly ?string $currency = null,
        public readonly ?CarbonImmutable $verifiedAt = null,
        public readonly ?int $paymentAccountId = null,
        public readonly ?string $environment = null,
        public readonly ?CarbonImmutable $originalExpiresAt = null,
        public readonly ?CarbonImmutable $occurredAt = null,
    ) {}

    /**
     * Provider-bound success issuance. Keep provider-specific entry points so
     * application code cannot use one generic success factory.
     */
    public static function fromPaytmBusiness(
        Order $order,
        string|int|float $amount,
        ?string $utr,
        ?string $txnId,
        array $raw = [],
        ?string $currency = 'INR',
        ?CarbonImmutable $verifiedAt = null,
        ?CarbonImmutable $occurredAt = null,
        ?CarbonImmutable $originalExpiresAt = null,
    ): self {
        return self::successful($order, 'paytm_business', $amount, $utr, $txnId, $raw, $currency, $verifiedAt, $occurredAt, $originalExpiresAt);
    }

    public static function fromPaytmPg(
        Order $order,
        string|int|float $amount,
        ?string $utr,
        ?string $txnId,
        array $raw = [],
        ?string $currency = 'INR',
        ?CarbonImmutable $verifiedAt = null,
        ?CarbonImmutable $occurredAt = null,
        ?CarbonImmutable $originalExpiresAt = null,
    ): self {
        return self::successful($order, 'paytm_pg', $amount, $utr, $txnId, $raw, $currency, $verifiedAt, $occurredAt, $originalExpiresAt);
    }

    public static function fromPhonePePg(
        Order $order,
        string|int|float $amount,
        ?string $utr,
        ?string $txnId,
        array $raw = [],
        ?string $currency = 'INR',
        ?CarbonImmutable $verifiedAt = null,
        ?CarbonImmutable $occurredAt = null,
        ?CarbonImmutable $originalExpiresAt = null,
    ): self {
        return self::successful($order, 'phonepe_pg', $amount, $utr, $txnId, $raw, $currency, $verifiedAt, $occurredAt, $originalExpiresAt);
    }

    private static function successful(
        Order $order,
        string $provider,
        string|int|float $amount,
        ?string $utr,
        ?string $txnId,
        array $raw,
        ?string $currency,
        ?CarbonImmutable $verifiedAt,
        ?CarbonImmutable $occurredAt,
        ?CarbonImmutable $originalExpiresAt,
    ): self {
        if (trim($utr ?? '') === '' && trim($txnId ?? '') === '') {
            throw new InvalidArgumentException('Provider verification requires a transaction reference.');
        }

        $deadline = $originalExpiresAt ?? $order->originalExpiryDeadline();
        $occurred = $occurredAt;
        if ($occurred === null && ! empty($raw)) {
            $occurred = self::extractRawTimestamp($provider, $raw);
        } elseif ($occurred === null && empty($raw)) {
            $occurred = $verifiedAt ?? CarbonImmutable::now();
        }

        return new self(
            paid: true,
            utr: $utr,
            txnId: $txnId,
            raw: $raw,
            provider: $provider,
            orderId: $order->id,
            orderNumber: $order->order_no,
            amount: number_format((float) $amount, 2, '.', ''),
            currency: $currency,
            verifiedAt: $verifiedAt ?? CarbonImmutable::now(),
            paymentAccountId: $order->paymentAccount?->id,
            environment: $order->paymentAccount?->paymentEnvironment(),
            originalExpiresAt: $deadline,
            occurredAt: $occurred,
        );
    }

    public static function parseProviderTimestamp(string|int|float|null $value, ?string $timezone = null): ?CarbonImmutable
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $tz = $timezone ?: config('gateway.provider_timezone', 'Asia/Kolkata');
        $trimmed = trim((string) $value);

        if (ctype_digit($trimmed) && strlen($trimmed) >= 12) {
            return CarbonImmutable::createFromTimestampMs((int) $trimmed);
        }
        if (ctype_digit($trimmed) && strlen($trimmed) == 10) {
            return CarbonImmutable::createFromTimestamp((int) $trimmed);
        }

        try {
            if (preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $trimmed)) {
                return CarbonImmutable::parse($trimmed)->setTimezone('UTC');
            }

            $cleaned = preg_replace('/\.\d+$/', '', $trimmed);

            return CarbonImmutable::createFromFormat('Y-m-d H:i:s', $cleaned, $tz)->setTimezone('UTC');
        } catch (\Throwable) {
            try {
                return CarbonImmutable::parse($trimmed, $tz)->setTimezone('UTC');
            } catch (\Throwable) {
                return null;
            }
        }
    }

    public static function extractRawTimestamp(string $provider, array $raw): ?CarbonImmutable
    {
        $value = match ($provider) {
            'paytm_business' => $raw['TXNDATE'] ?? $raw['TXNDATETIME'] ?? $raw['txnDate'] ?? null,
            'paytm_pg' => $raw['txnDate'] ?? $raw['TXNDATE'] ?? ($raw['body']['txnDate'] ?? null),
            'phonepe_pg' => self::findPhonePeTimestamp($raw),
            default => $raw['occurred_at'] ?? $raw['timestamp'] ?? $raw['paid_at'] ?? null,
        };

        return self::parseProviderTimestamp($value);
    }

    private static function findPhonePeTimestamp(array $raw): string|int|null
    {
        $payment = collect($raw['paymentDetails'] ?? [])->firstWhere('state', 'COMPLETED') ?? [];

        return $payment['completedAt'] ?? $payment['timestamp'] ?? $raw['completedAt'] ?? $raw['timestamp'] ?? null;
    }

    public static function pending(array $raw = []): self
    {
        return new self(false, raw: $raw);
    }
}
