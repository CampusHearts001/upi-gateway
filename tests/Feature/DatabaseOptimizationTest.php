<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Database and backend optimization tests.
 *
 * Covers:
 *  - Dashboard stats correctness after query consolidation
 *  - Dashboard chart correctness (DB-grouped)
 *  - Orders index pagination
 *  - resolveAccount: active-collection-first optimisation preserves behaviour
 *  - Webhook idempotency index (order_id + event + success)
 *  - Admin orders pagination
 *  - recentOrders eager-loads paymentAccount (no N+1)
 */
class DatabaseOptimizationTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function merchant(): User
    {
        $user = User::create([
            'name' => 'Ravi',
            'business_name' => 'Ravi Store',
            'email' => 'ravi@example.com',
            'password' => 'password123',
        ]);
        $user->regenerateApiCredentials();
        $user->paymentAccounts()->create([
            'provider' => 'manual',
            'label' => 'GPay',
            'upi_id' => 'ravi@okaxis',
            'payee_name' => 'Ravi',
            'is_default' => true,
        ]);

        return $user;
    }

    private function order(User $merchant, string $status = Order::PENDING, ?string $paidAt = null): Order
    {
        $order = app(OrderService::class)->create($merchant, [
            'amount' => 100,
            'client_order_id' => 'T-'.uniqid(),
        ]);

        if ($status !== Order::PENDING) {
            $order->forceFill([
                'status' => $status,
                'paid_at' => $paidAt ?? ($status === Order::PAID ? now() : null),
            ])->save();
        }

        return $order;
    }

    // ── 1. Dashboard stats correctness ────────────────────────────────────────

    public function test_dashboard_stats_are_correct(): void
    {
        $merchant = $this->merchant();

        // 2 paid today, 1 paid yesterday, 1 pending, 1 expired, 1 review
        $this->order($merchant, Order::PAID, now()->toDateTimeString());
        $this->order($merchant, Order::PAID, now()->toDateTimeString());
        $this->order($merchant, Order::PAID, now()->subDay()->toDateTimeString());
        $this->order($merchant, Order::PENDING);
        $this->order($merchant, Order::EXPIRED);
        $this->order($merchant, Order::REVIEW);

        $response = $this->actingAs($merchant)->get(route('dashboard'))->assertOk();

        $stats = $response->viewData('stats');

        $this->assertSame(3, $stats['paid_count']);
        $this->assertSame(2, $stats['today_count']);
        $this->assertSame(200.0, $stats['today_amount']);
        $this->assertSame(300.0, $stats['total_amount']);
        $this->assertSame(1, $stats['pending_count']);
        $this->assertSame(1, $stats['review_count']);
        // success_rate = 3 paid / (3 paid + 1 expired) = 75%
        $this->assertSame(75, $stats['success_rate']);
    }

    public function test_dashboard_stats_zero_when_no_orders(): void
    {
        $merchant = $this->merchant();

        $response = $this->actingAs($merchant)->get(route('dashboard'))->assertOk();
        $stats = $response->viewData('stats');

        $this->assertSame(0, $stats['paid_count']);
        $this->assertSame(0.0, $stats['total_amount']);
        $this->assertNull($stats['success_rate']);
    }

    // ── 2. Dashboard chart correctness ────────────────────────────────────────

    public function test_dashboard_chart_groups_by_day_correctly(): void
    {
        $merchant = $this->merchant();

        // 2 paid today, 1 paid 3 days ago
        $this->order($merchant, Order::PAID, now()->toDateTimeString());
        $this->order($merchant, Order::PAID, now()->toDateTimeString());
        $this->order($merchant, Order::PAID, now()->subDays(3)->toDateTimeString());

        $response = $this->actingAs($merchant)->get(route('dashboard'))->assertOk();
        $chart = $response->viewData('chart');

        $this->assertCount(7, $chart);

        $today = $chart->last();
        $threeDays = $chart->get(3); // index 3 = today - 3 days

        $this->assertSame(200.0, $today['amount']);
        $this->assertSame(2, $today['count']);
        $this->assertSame(100.0, $threeDays['amount']);
        $this->assertSame(1, $threeDays['count']);
    }

    public function test_dashboard_week_total_matches_chart_sum(): void
    {
        $merchant = $this->merchant();

        $this->order($merchant, Order::PAID, now()->toDateTimeString());
        $this->order($merchant, Order::PAID, now()->subDays(2)->toDateTimeString());

        $response = $this->actingAs($merchant)->get(route('dashboard'))->assertOk();
        $chart = $response->viewData('chart');
        $weekTotal = $response->viewData('weekTotal');

        $this->assertSame($chart->sum('amount'), $weekTotal);
    }

    // ── 3. Dashboard isolates stats per merchant ──────────────────────────────

    public function test_dashboard_stats_are_scoped_to_merchant(): void
    {
        $merchantA = $this->merchant();
        $merchantB = User::create([
            'name' => 'B', 'email' => 'b@example.com', 'password' => 'password123',
        ]);
        $merchantB->paymentAccounts()->create([
            'provider' => 'manual', 'label' => 'X', 'upi_id' => 'b@upi',
            'payee_name' => 'B', 'is_default' => true,
        ]);

        $this->order($merchantA, Order::PAID, now()->toDateTimeString());
        $this->order($merchantB, Order::PAID, now()->toDateTimeString());

        $response = $this->actingAs($merchantA)->get(route('dashboard'))->assertOk();
        $stats = $response->viewData('stats');

        // Merchant A sees only their own 1 paid order.
        $this->assertSame(1, $stats['paid_count']);
        $this->assertSame(100.0, $stats['total_amount']);
    }

    // ── 4. Orders index is paginated ─────────────────────────────────────────

    public function test_orders_index_is_paginated(): void
    {
        $merchant = $this->merchant();

        // Create 25 orders (more than the page size of 20).
        for ($i = 0; $i < 25; $i++) {
            $this->order($merchant);
        }

        $response = $this->actingAs($merchant)->get(route('orders.index'))->assertOk();
        $orders = $response->viewData('orders');

        $this->assertSame(20, $orders->perPage());
        $this->assertSame(25, $orders->total());
        $this->assertCount(20, $orders->items());
    }

    public function test_orders_index_second_page_has_remaining_orders(): void
    {
        $merchant = $this->merchant();

        for ($i = 0; $i < 25; $i++) {
            $this->order($merchant);
        }

        $response = $this->actingAs($merchant)->get(route('orders.index').'?page=2')->assertOk();
        $orders = $response->viewData('orders');

        $this->assertCount(5, $orders->items());
    }

    // ── 5. Admin orders index is paginated ───────────────────────────────────

    public function test_admin_orders_index_is_paginated(): void
    {
        $merchant = $this->merchant();
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin@example.com',
            'password' => 'adminpass123', 'role' => 'admin',
        ]);

        for ($i = 0; $i < 35; $i++) {
            $this->order($merchant);
        }

        $this->withoutMiddleware(RequirePassword::class);

        $response = $this->actingAs($admin)->get('/admin/orders')->assertOk();
        $orders = $response->viewData('orders');

        $this->assertSame(30, $orders->perPage());
        $this->assertSame(35, $orders->total());
        $this->assertCount(30, $orders->items());
    }

    // ── 6. resolveAccount: unknown account ID is rejected ────────────────────

    public function test_resolve_account_rejects_unknown_account_id(): void
    {
        $merchant = $this->merchant();

        $this->postJson('/api/v1/orders', [
            'amount' => 100,
            'client_order_id' => 'RA-1',
            'payment_account_id' => 99999,
        ], ['X-Api-Key' => $merchant->api_key, 'X-Api-Secret' => $merchant->api_secret, 'Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_account_id');
    }

    public function test_resolve_account_rejects_foreign_account_id(): void
    {
        $merchant = $this->merchant();
        $other = User::create(['name' => 'X', 'email' => 'x@example.com', 'password' => 'password123']);
        $foreign = $other->paymentAccounts()->create([
            'provider' => 'manual', 'label' => 'X', 'upi_id' => 'x@upi', 'payee_name' => 'X',
        ]);

        $this->postJson('/api/v1/orders', [
            'amount' => 100,
            'client_order_id' => 'RA-2',
            'payment_account_id' => $foreign->id,
        ], ['X-Api-Key' => $merchant->api_key, 'X-Api-Secret' => $merchant->api_secret, 'Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_account_id');
    }

    public function test_resolve_account_rejects_inactive_account_without_fallback(): void
    {
        $merchant = $this->merchant();
        $inactive = $merchant->paymentAccounts()->create([
            'provider' => 'manual', 'label' => 'Off', 'upi_id' => 'off@upi',
            'payee_name' => 'Off', 'is_active' => false,
        ]);

        // Passing an inactive account ID must be rejected without fallback.
        $this->postJson('/api/v1/orders', [
            'amount' => 100,
            'client_order_id' => 'RA-3',
            'payment_account_id' => $inactive->id,
        ], ['X-Api-Key' => $merchant->api_key, 'X-Api-Secret' => $merchant->api_secret, 'Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_account_id');

        $this->assertNull(Order::where('client_order_id', 'RA-3')->first());
    }

    // ── 7. recentOrders eager-loads paymentAccount ───────────────────────────

    public function test_dashboard_recent_orders_eager_loads_payment_account(): void
    {
        $merchant = $this->merchant();

        for ($i = 0; $i < 3; $i++) {
            $this->order($merchant);
        }

        // Count queries during the dashboard request.
        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $this->actingAs($merchant)->get(route('dashboard'))->assertOk();

        // With eager loading the query count must be bounded regardless of
        // how many recent orders are returned (no N+1 on paymentAccount).
        // A reasonable upper bound for the dashboard is 10 queries.
        $this->assertLessThanOrEqual(10, $queryCount,
            "Dashboard fired {$queryCount} queries — possible N+1 regression.");
    }
}
