<?php

return [
    // Minutes a payment link stays valid before the order expires.
    'order_expiry_minutes' => (int) env('GATEWAY_ORDER_EXPIRY_MINUTES', 15),

    // Provider status recovery after expiry; zero disables automatic recovery.
    'late_payment_grace_hours' => max(0, (int) env('GATEWAY_LATE_PAYMENT_GRACE_HOURS', 24)),
    'late_payment_check_interval_seconds' => max(120, (int) env('GATEWAY_LATE_PAYMENT_CHECK_INTERVAL_SECONDS', 300)),
    'late_payment_batch_size' => max(1, min(1000, (int) env('GATEWAY_LATE_PAYMENT_BATCH_SIZE', 100))),

    // Bounded batch size for pending order verification on each scheduler cycle.
    'pending_verification_batch_size' => max(1, min(1000, (int) env('GATEWAY_PENDING_VERIFICATION_BATCH_SIZE', 100))),

    // Distributed lock duration for the order verification scheduler command (seconds).
    'scheduler_lock_seconds' => max(60, min(3600, (int) env('GATEWAY_SCHEDULER_LOCK_SECONDS', 300))),

    // Minimum seconds between two provider status checks for the same order.
    'check_interval_seconds' => 3,

    // ── Provider verification concurrency & locking ───────────────────────────
    // Lease seconds for active provider verification.
    'verification_lease_seconds' => max(15, (int) env('GATEWAY_VERIFICATION_LEASE_SECONDS', 120)),
    // Must be stable and unique per host/container. Cross-node liveness is
    // deliberately not guessed; operators recover those leases explicitly.
    'verification_node_id' => env('GATEWAY_VERIFICATION_NODE_ID'),
    'verification_node_installation_id' => env('GATEWAY_VERIFICATION_NODE_INSTALLATION_ID'),
    'verification_node_boot_id' => env('GATEWAY_VERIFICATION_NODE_BOOT_ID'),

    // ── Evidence binding & late payment deadline security (Priority 26) ──────
    // Require a reliable provider transaction timestamp on all evidence.
    'evidence_require_timestamp' => (bool) env('GATEWAY_EVIDENCE_REQUIRE_TIMESTAMP', true),
    // Maximum allowed age of provider payment evidence in seconds (default: 24 hours).
    'evidence_max_age_seconds' => max(60, (int) env('GATEWAY_EVIDENCE_MAX_AGE_SECONDS', 86400)),
    // Allowed clock-skew between provider servers and gateway in seconds (default: 5 minutes).
    'evidence_clock_skew_seconds' => max(0, (int) env('GATEWAY_EVIDENCE_CLOCK_SKEW_SECONDS', 300)),
    // Default provider timezone for date strings without offset (Paytm uses IST).
    'provider_timezone' => env('GATEWAY_PROVIDER_TIMEZONE', 'Asia/Kolkata'),

    // Unofficial Paytm for Business status endpoint. It may change without notice.
    'paytm_status_url' => env('PAYTM_STATUS_URL', 'https://securegw.paytm.in/merchant-status/getTxnStatus'),

    // Official PhonePe PG (Standard Checkout v2).
    'phonepe_pg' => [
        'sandbox' => [
            'token_url' => 'https://api-preprod.phonepe.com/apis/pg-sandbox/v1/oauth/token',
            'base_url' => 'https://api-preprod.phonepe.com/apis/pg-sandbox',
        ],
        'production' => [
            'token_url' => 'https://api.phonepe.com/apis/identity-manager/v1/oauth/token',
            'base_url' => 'https://api.phonepe.com/apis/pg',
        ],
    ],

    // Official Paytm Payment Gateway.
    'paytm_pg' => [
        'sandbox' => env('PAYTM_PG_SANDBOX_URL', 'https://securestage.paytmpayments.com'),
        'production' => env('PAYTM_PG_PRODUCTION_URL', 'https://secure.paytmpayments.com'),
    ],

    // ── Webhook SSRF protection ───────────────────────────────────────────────
    // Allow http:// webhook URLs (for local dev only). Defaults to false.
    'webhook_allow_insecure' => env('GATEWAY_WEBHOOK_ALLOW_INSECURE', false),
    // Ports that webhook URLs may use. 443 always included; add 80 if insecure allowed.
    'webhook_allowed_ports' => [443],

    // ── Redirect URL security ─────────────────────────────────────────────────
    // Allow http:// redirect URLs (for local dev only). Defaults to false.
    'allow_insecure_redirects' => env('GATEWAY_ALLOW_INSECURE_REDIRECTS', false),

    // ── Content-Security-Policy ───────────────────────────────────────────────
    // Set to true to switch from Report-Only to enforcing CSP.
    // See App\Http\Middleware\SecurityHeaders for what must be fixed first.
    // Set to false to switch to Content-Security-Policy-Report-Only mode.
    'csp_enforce' => env('GATEWAY_CSP_ENFORCE', true),

    // ── API rate limiting ─────────────────────────────────────────────────────
    // Per-API-key request limit per minute. Lower this in tests via config([...]).
    'api_key_rate_limit' => (int) env('GATEWAY_API_KEY_RATE_LIMIT', 120),

    // Per-IP request limit per minute across all API keys.
    'api_ip_rate_limit' => (int) env('GATEWAY_API_IP_RATE_LIMIT', 600),

    // Per-IP failed authentication attempts per minute before brute-force throttle (429).
    'api_failed_auth_ip_rate_limit' => (int) env('GATEWAY_API_FAILED_AUTH_IP_RATE_LIMIT', 30),

];
