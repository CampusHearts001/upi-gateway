<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const EXPIRED = 'expired';

    public const REVIEW = 'review'; // customer submitted a UTR, merchant must confirm

    public const FAILED = 'failed';

    public const LIVE = 'live';

    public const SANDBOX = 'sandbox';

    // Customer UTR submissions allowed per order, and how long after expiry
    // a customer may still submit one (they may have paid just before expiry).
    public const MAX_UTR_ATTEMPTS = 3;

    public const UTR_GRACE_MINUTES = 60;

    protected $fillable = [
        'order_no',
        'payment_account_id',
        'client_order_id',
        'amount',
        'status',
        'customer_name',
        'customer_email',
        'customer_mobile',
        'note',
        'redirect_url',
        'expires_at',
        'original_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'provider_response' => 'array',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
            'original_expires_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'utr_submitted_at' => 'datetime',
            'provider_checkout' => 'array',
            'sandbox_simulated_at' => 'datetime',
            'verification_generation' => 'integer',
            'verification_expires_at' => 'datetime',
            'verification_started_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            if ($order->expires_at === null) {
                $order->expires_at = now()->addMinutes((int) config('gateway.order_expiry_minutes', 15));
            }
            if ($order->original_expires_at === null) {
                $order->original_expires_at = $order->expires_at;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'order_no';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class);
    }

    public function webhookLogs(): HasMany
    {
        return $this->hasMany(WebhookLog::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /**
     * Customers can submit a UTR only for manual-verification accounts, while
     * the order is pending or recently expired, and within the attempt limit.
     */
    public function acceptsCustomerUtr(): bool
    {
        return $this->environment === self::LIVE
            && $this->paymentAccount?->provider === 'manual'
            && $this->utr_attempts < self::MAX_UTR_ATTEMPTS
            && ($this->status === self::PENDING
                || ($this->status === self::EXPIRED && $this->expires_at?->gt(now()->subMinutes(self::UTR_GRACE_MINUTES))));
    }

    public function isPastExpiry(): bool
    {
        return $this->expires_at?->isPast() ?? false;
    }

    public function originalExpiryDeadline(): ?CarbonImmutable
    {
        $dt = $this->original_expires_at ?? $this->expires_at;

        return $dt ? CarbonImmutable::instance($dt) : null;
    }

    public function isWithinLatePaymentGrace(): bool
    {
        $hours = max(0, (int) config('gateway.late_payment_grace_hours', 24));

        return $hours > 0 && $this->expires_at !== null
            && $this->expires_at->lte(now())
            && $this->expires_at->gte(now()->subHours($hours));
    }

    public function canRecoverLatePayment(): bool
    {
        return $this->status === self::EXPIRED && $this->environment === self::LIVE
            && $this->isWithinLatePaymentGrace()
            && $this->paymentAccount?->supportsStatusVerification()
            && $this->paymentAccount->paymentEnvironment() === self::LIVE;
    }

    public function scopeRecoverableExpired(Builder $query): void
    {
        $hours = max(0, (int) config('gateway.late_payment_grace_hours', 24));
        $query->where('status', self::EXPIRED)->where('environment', self::LIVE)
            ->where('expires_at', '<=', now())
            ->where('expires_at', '>=', now()->subHours($hours))
            ->when($hours === 0, fn ($q) => $q->whereRaw('1 = 0'))
            ->whereHas('paymentAccount', fn ($q) => $q->whereIn('provider', PaymentAccount::STATUS_VERIFIABLE_PROVIDERS));
    }

    /**
     * Standard UPI deep link. For automated payment provider accounts, `tr`
     * carries our order number so the provider status API can find this exact payment.
     * For manual / personal UPI accounts, `tr` is omitted per NPCI P2P guidelines to
     * prevent rejection by consumer UPI apps (Google Pay, PhonePe, Paytm), and the
     * order reference is preserved in `tn`.
     */
    public function upiIntentUrl(string $scheme = 'upi://pay'): string
    {
        $account = $this->paymentAccount;
        $isManual = $account?->provider === 'manual';

        $params = [
            'pa' => $account?->upi_id,
            'pn' => $account?->payee_name,
            'am' => number_format((float) $this->amount, 2, '.', ''),
            'cu' => 'INR',
        ];

        if (! $isManual) {
            $params['tr'] = $this->order_no;
        }

        $params['tn'] = $this->note ?: $this->order_no;

        return $scheme.'?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    public function paymentUrl(): string
    {
        return $this->environment === self::SANDBOX
            ? route('sandbox.pay.show', $this)
            : route('pay.show', $this);
    }

    /**
     * Shape returned by the API and sent in webhooks.
     */
    public function toApiArray(): array
    {
        return [
            'order_id' => $this->order_no,
            'client_order_id' => $this->client_order_id,
            'amount' => $this->amount,
            'currency' => 'INR',
            'status' => $this->status,
            'environment' => $this->environment,
            'test' => $this->environment === self::SANDBOX,
            'utr' => $this->utr,
            'submitted_utr' => $this->status === self::REVIEW ? $this->customer_utr : null,
            'payment_url' => $this->paymentUrl(),
            // PG orders are paid on the PG's checkout, so there's no UPI link.
            'upi_intent' => $this->environment === self::SANDBOX
                ? null
                : ($this->paymentAccount->isHosted() ? null : $this->upiIntentUrl()),
            'customer' => [
                'name' => $this->customer_name,
                'email' => $this->customer_email,
                'mobile' => $this->customer_mobile,
            ],
            'paid_at' => $this->paid_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'original_expires_at' => ($this->original_expires_at ?? $this->expires_at)?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
