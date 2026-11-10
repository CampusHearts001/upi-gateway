<?php

namespace App\Http\Middleware;

use App\Models\Order;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Merchant API authentication via X-Api-Key / X-Api-Secret headers.
 *
 * Enforces:
 *  - Per-API-key rate limit  : config('gateway.api_key_rate_limit') / min
 *  - Per-IP rate limit       : config('gateway.api_ip_rate_limit') / min
 *  - X-Request-Id on every response
 *  - last_used_at / last_used_ip at most once per minute
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next, string $limitScope = 'default'): Response
    {
        $requestId = (string) Str::uuid();
        $request->headers->set('X-Request-Id', $requestId);

        $ip = $request->ip();

        // ── Failed authentication rate limit by IP (Brute-force protection) ────
        $failedAuthLimit = (int) config('gateway.api_failed_auth_ip_rate_limit', 30);
        $failedAuthBucket = 'api-failed-auth:'.$ip;

        $key = (string) $request->header('X-Api-Key');
        $secret = (string) $request->header('X-Api-Secret');

        $environment = str_starts_with($key, 'test_key_') ? Order::SANDBOX : Order::LIVE;
        $keyColumn = $environment === Order::SANDBOX ? 'sandbox_api_key' : 'api_key';
        $secretColumn = $environment === Order::SANDBOX ? 'sandbox_api_secret' : 'api_secret';
        $merchant = $key !== '' ? User::where($keyColumn, $key)->first() : null;
        $expectedSecret = $merchant?->{$secretColumn};

        if (! $merchant || ! $expectedSecret || ! hash_equals($expectedSecret, $secret)) {
            if (RateLimiter::tooManyAttempts($failedAuthBucket, $failedAuthLimit)) {
                return response()->json(['message' => 'Too many failed authentication attempts. Please try again later.'], 429)
                    ->header('Retry-After', (string) RateLimiter::availableIn($failedAuthBucket))
                    ->header('X-RateLimit-Limit', (string) $failedAuthLimit)
                    ->header('X-RateLimit-Remaining', '0')
                    ->header('X-Request-Id', $requestId);
            }
            RateLimiter::hit($failedAuthBucket, 60);

            return response()->json(['message' => 'Invalid API key or secret.'], 401)
                ->header('X-Request-Id', $requestId);
        }

        if (! $merchant->is_active) {
            return response()->json(['message' => 'Merchant account is disabled.'], 403)
                ->header('X-Request-Id', $requestId);
        }

        $request->setUserResolver(fn () => $merchant);
        $request->attributes->set('payment_environment', $environment);

        // ── Per-key throttle ──────────────────────────────────────────────────
        $orderCreate = $limitScope === 'order_create';
        $configuredKeyLimit = config(
            $orderCreate ? 'gateway.order_create_api_key_rate_limit' : 'gateway.api_key_rate_limit',
            $orderCreate ? 1000 : 120
        );
        $keyLimit = filter_var($configuredKeyLimit, FILTER_VALIDATE_INT);
        if (! is_int($keyLimit) || $keyLimit < 1) {
            $keyLimit = $orderCreate ? 1000 : 120;
        }
        $keyBucket = ($orderCreate ? 'api-order-create-key:' : 'api-key:')
            .($environment === Order::LIVE ? '' : 'sandbox:').$merchant->id;

        if (RateLimiter::tooManyAttempts($keyBucket, $keyLimit)) {
            return response()->json(['message' => 'Rate limit exceeded for this API key.'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($keyBucket))
                ->header('X-RateLimit-Limit', (string) $keyLimit)
                ->header('X-RateLimit-Remaining', '0')
                ->header('X-Request-Id', $requestId);
        }
        RateLimiter::hit($keyBucket, 60);

        // ── Per-IP throttle ───────────────────────────────────────────────────
        $ipLimit = (int) config('gateway.api_ip_rate_limit', 600);
        $ipBucket = 'api-ip:'.$request->ip();

        if (RateLimiter::tooManyAttempts($ipBucket, $ipLimit)) {
            return response()->json(['message' => 'Rate limit exceeded for this IP.'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($ipBucket))
                ->header('X-Request-Id', $requestId);
        }
        RateLimiter::hit($ipBucket, 60);

        // ── Track last_used_at / last_used_ip (at most once per minute) ────────
        $trackKey = $environment === Order::LIVE
            ? 'api_track:'.$merchant->id
            : 'api_track:sandbox:'.$merchant->id;
        if (! app('cache')->has($trackKey)) {
            app('cache')->put($trackKey, 1, 60);
            DB::table('users')->where('id', $merchant->id)->update([
                'api_last_used_at' => now(),
                'api_last_used_ip' => $request->ip(),
            ]);
        }

        $response = $next($request);

        return $response->header('X-Request-Id', $requestId);
    }
}
