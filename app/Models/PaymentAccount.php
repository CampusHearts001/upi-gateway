<?php

namespace App\Models;

use App\Services\Verifiers\ManualVerifier;
use App\Services\Verifiers\PaytmBusinessVerifier;
use App\Services\Verifiers\PaytmPgVerifier;
use App\Services\Verifiers\PhonePePgVerifier;
use App\Services\Verifiers\Verifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class PaymentAccount extends Model
{
    // These providers have server-to-server order status verifiers. Manual
    // UTR accounts and the internal Sandbox simulator are deliberately absent.
    public const STATUS_VERIFIABLE_PROVIDERS = ['paytm_business', 'paytm_pg', 'phonepe_pg'];

    public function supportsStatusVerification(): bool
    {
        return in_array($this->provider, self::STATUS_VERIFIABLE_PROVIDERS, true);
    }

    /**
     * Supported providers and the verifier that confirms payments for each.
     * To add a new provider, write a Verifier and register it here.
     */
    public const PROVIDERS = [
        'paytm_business' => ['label' => 'Paytm for Business (auto verify)', 'verifier' => PaytmBusinessVerifier::class, 'hosted' => false],
        'phonepe_pg' => ['label' => 'PhonePe PG (official)', 'verifier' => PhonePePgVerifier::class, 'hosted' => true],
        'paytm_pg' => ['label' => 'Paytm PG (official)', 'verifier' => PaytmPgVerifier::class, 'hosted' => true],
        'manual' => ['label' => 'Any UPI ID (manual verify)', 'verifier' => ManualVerifier::class, 'hosted' => false],
    ];

    protected $fillable = [
        'provider',
        'label',
        'upi_id',
        'payee_name',
        'credentials',
        'is_active',
        'is_default',
        'daily_limit',
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'daily_limit' => 'decimal:2',
        ];
    }

    /**
     * Adds `used_today`: amount paid today plus open pending and in-review orders, i.e.
     * what counts against the daily limit.
     */
    public function scopeWithUsageToday(Builder $query): void
    {
        $query->withSum(['orders as used_today' => fn ($q) => $q->where(fn ($q) => $q
            ->where(fn ($q) => $q->where('status', Order::PAID)->where('paid_at', '>=', today()))
            ->orWhere(fn ($q) => $q->where('status', Order::PENDING)->where('expires_at', '>', now()))
            ->orWhere('status', Order::REVIEW))], 'amount');
    }

    public function usedToday(): float
    {
        return (float) ($this->used_today ?? 0);
    }

    public function remainingToday(): ?float
    {
        return $this->daily_limit === null ? null : max(0, (float) $this->daily_limit - $this->usedToday());
    }

    public function canAccept(float $amount): bool
    {
        return $this->daily_limit === null || $this->usedToday() + $amount <= (float) $this->daily_limit;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function providerLabel(): string
    {
        return self::PROVIDERS[$this->provider]['label'] ?? $this->provider;
    }

    /**
     * Official PGs: the customer pays on the PG's checkout, not our UPI QR.
     */
    public function isHosted(): bool
    {
        return self::PROVIDERS[$this->provider]['hosted'] ?? false;
    }

    public function environment(): ?string
    {
        return $this->isHosted() ? ($this->credential('environment') === 'production' ? 'production' : 'sandbox') : null;
    }

    /** Application environment; unknown hosted configurations fail closed. */
    public function paymentEnvironment(): ?string
    {
        return match ($this->provider) {
            'paytm_pg', 'phonepe_pg' => match ($this->credential('environment')) {
                'production' => Order::LIVE,
                'sandbox' => Order::SANDBOX,
                default => null,
            },
            'paytm_business', 'manual' => Order::LIVE,
            default => null,
        };
    }

    public function assertCompatibleWithOrder(Order $order): void
    {
        if ($order->payment_account_id !== $this->id || $order->user_id !== $this->user_id
            || ! in_array($order->environment, [Order::LIVE, Order::SANDBOX], true)
            || $this->paymentEnvironment() !== $order->environment) {
            throw ValidationException::withMessages([
                'payment_account_id' => 'The payment account does not match this order and its environment.',
            ]);
        }
    }

    public function assertLiveProviderOrder(Order $order, string $provider): void
    {
        $this->assertCompatibleWithOrder($order);

        if ($order->environment !== Order::LIVE || $this->provider !== $provider) {
            throw ValidationException::withMessages([
                'payment_account_id' => 'Live provider verification requires a matching Live payment account.',
            ]);
        }
    }

    public function verifier(): Verifier
    {
        return app(self::PROVIDERS[$this->provider]['verifier']);
    }

    public function credential(string $key): ?string
    {
        return $this->credentials[$key] ?? null;
    }
}
