<?php

namespace Tests\Feature;

use App\Jobs\SendWebhook;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Services\WebhookOutboxDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UtrReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->merchant = User::create(['name' => 'Ravi', 'business_name' => 'Ravi Store', 'email' => 'ravi@example.com', 'password' => 'password123']);
        $this->merchant->paymentAccounts()->create(['provider' => 'manual', 'label' => 'GPay', 'upi_id' => 'ravi@okaxis', 'payee_name' => 'Ravi']);
    }

    private function order(float $amount = 499): Order
    {
        return app(OrderService::class)->create($this->merchant, ['amount' => $amount]);
    }

    public function test_customer_submits_utr_and_order_goes_to_review_not_paid(): void
    {
        $order = $this->order();

        $this->get(route('pay.show', $order))->assertOk()->assertSee('Already paid? Enter your UTR');

        $this->post(route('pay.utr', $order), ['utr' => '412345678901'])->assertRedirect(route('pay.show', $order));

        $order->refresh();
        $this->assertSame(Order::REVIEW, $order->status);
        $this->assertSame('412345678901', $order->customer_utr);
        $this->assertNull($order->utr);
        $this->assertNull($order->paid_at);
        app(WebhookOutboxDispatcher::class)->dispatchDue();
        Queue::assertPushed(SendWebhook::class, fn ($j) => $j->event === 'order.under_review');
        Queue::assertNotPushed(SendWebhook::class, fn ($j) => $j->event === 'order.paid');

        $this->get(route('pay.show', $order))->assertSee('Verifying your payment')->assertSee('412345678901');
        $this->getJson(route('pay.status', $order))->assertJson(['status' => 'review', 'redirect' => null]);

        // Review orders never expire on their own.
        $this->travel(2)->hours();
        $this->artisan('orders:check-pending');
        $this->assertSame(Order::REVIEW, $order->fresh()->status);
    }

    public function test_utr_must_be_12_digits(): void
    {
        $order = $this->order();

        foreach (['12345', 'ABCDEFGHIJKL', '1234567890123'] as $bad) {
            $this->post(route('pay.utr', $order), ['utr' => $bad])->assertSessionHasErrors('utr');
        }
        $this->assertSame(Order::PENDING, $order->fresh()->status);
    }

    public function test_merchant_approves_only_when_utr_matches_and_amount_verified(): void
    {
        $order = $this->order(499.00);
        $this->post(route('pay.utr', $order), ['utr' => '412345678901']);
        $this->actingAs($this->merchant);

        // Missing received amount: validation failure
        $this->post(route('orders.approve-utr', $order), ['bank_utr' => '412345678901'])
            ->assertSessionHasErrors('received_amount');
        $this->assertSame(Order::REVIEW, $order->fresh()->status);

        // Wrong amount: validation failure
        $this->post(route('orders.approve-utr', $order), [
            'bank_utr' => '412345678901',
            'received_amount' => 100.00,
        ])->assertSessionHasErrors('received_amount');
        $this->assertSame(Order::REVIEW, $order->fresh()->status);

        // Wrong UTR from bank app: not approved.
        $this->post(route('orders.approve-utr', $order), [
            'bank_utr' => '999999999999',
            'received_amount' => 499.00,
        ])->assertSessionHasErrors('bank_utr');
        $this->assertSame(Order::REVIEW, $order->fresh()->status);

        // Copied partial UTR (e.g. only 4 digits): regex validation failure
        $this->post(route('orders.approve-utr', $order), [
            'bank_utr' => '8901',
            'received_amount' => 499.00,
        ])->assertSessionHasErrors('bank_utr');
        $this->assertSame(Order::REVIEW, $order->fresh()->status);

        // Correct matching UTR and exact verified amount: approved and marked PAID
        $this->post(route('orders.approve-utr', $order), [
            'bank_utr' => ' 4123 4567 8901 ',
            'received_amount' => 499.00,
        ])->assertSessionHasNoErrors();

        $fresh = $order->fresh();
        $this->assertSame(Order::PAID, $fresh->status);
        $this->assertSame('412345678901', $fresh->utr);
        $this->assertSame('utr_review', $fresh->verified_by);
        $this->assertNotNull($fresh->paid_at);

        app(WebhookOutboxDispatcher::class)->dispatchDue();
        Queue::assertPushed(SendWebhook::class, fn ($j) => $j->event === 'order.paid');
    }

    public function test_reject_lets_customer_retry_then_expires_after_three_attempts(): void
    {
        $order = $this->order();
        $this->actingAs($this->merchant);

        foreach ([1, 2] as $attempt) {
            $this->post(route('pay.utr', $order), ['utr' => "11111111111{$attempt}"]);
            $this->post(route('orders.reject-utr', $order), ['reason' => 'Not in my bank.']);

            $order->refresh();
            $this->assertSame(Order::PENDING, $order->status);
            $this->assertSame('Not in my bank.', $order->review_note);
            $this->assertTrue($order->expires_at->isFuture(), 'Rejecting gives the customer a fresh window.');
        }

        $this->get(route('pay.show', $order))->assertSee("Couldn't verify your last UTR", false)->assertSee('1 attempt left');

        $this->post(route('pay.utr', $order), ['utr' => '111111111113']);
        $this->post(route('orders.reject-utr', $order));

        $order->refresh();
        $this->assertSame(Order::EXPIRED, $order->status);
        $this->assertFalse($order->acceptsCustomerUtr());
        $this->post(route('pay.utr', $order), ['utr' => '111111111114'])->assertSessionHasErrors('utr');
    }

    public function test_same_utr_cannot_be_used_on_two_orders(): void
    {
        $first = $this->order();
        $second = $this->order();

        // While the first is under review...
        $this->post(route('pay.utr', $first), ['utr' => '412345678901']);
        $this->post(route('pay.utr', $second), ['utr' => '412345678901'])->assertSessionHasErrors('utr');

        // ...and after it is approved.
        $this->actingAs($this->merchant)->post(route('orders.approve-utr', $first), [
            'bank_utr' => '412345678901',
            'received_amount' => 499.00,
        ]);
        $this->post(route('pay.utr', $second), ['utr' => '412345678901'])->assertSessionHasErrors('utr');

        // Merchant can't reuse it via manual mark-paid either.
        $this->post(route('orders.mark-paid', $second), ['utr' => '412345678901'])->assertSessionHasErrors('utr');
        $this->assertSame(Order::PENDING, $second->fresh()->status);
    }

    public function test_customer_can_submit_utr_shortly_after_expiry_but_not_much_later(): void
    {
        $order = $this->order();
        $this->travel(20)->minutes();
        $this->artisan('orders:check-pending');
        $this->assertSame(Order::EXPIRED, $order->fresh()->status);

        $this->get(route('pay.show', $order))->assertSee('Already paid? Enter your UTR');
        $this->post(route('pay.utr', $order), ['utr' => '412345678901']);
        $this->assertSame(Order::REVIEW, $order->fresh()->status);

        $late = $this->order();
        $this->travel(2)->hours();
        $this->artisan('orders:check-pending');
        $this->post(route('pay.utr', $late), ['utr' => '412345678902'])->assertSessionHasErrors('utr');
    }

    public function test_paytm_accounts_do_not_show_utr_form(): void
    {
        $this->merchant->paymentAccounts()->delete();
        $this->merchant->paymentAccounts()->create(['provider' => 'paytm_business', 'label' => 'P', 'upi_id' => 'paytmqr1@paytm', 'payee_name' => 'R', 'credentials' => ['mid' => 'M1']]);
        $order = $this->order();

        $this->get(route('pay.show', $order))->assertDontSee('Already paid? Enter your UTR');
        $this->post(route('pay.utr', $order), ['utr' => '412345678901'])->assertSessionHasErrors('utr');
        $this->assertSame(Order::PENDING, $order->fresh()->status);
    }

    public function test_other_merchant_cannot_approve(): void
    {
        $order = $this->order();
        $this->post(route('pay.utr', $order), ['utr' => '412345678901']);

        $other = User::create(['name' => 'X', 'email' => 'x@example.com', 'password' => 'password123']);
        $this->actingAs($other)->post(route('orders.approve-utr', $order), [
            'bank_utr' => '412345678901',
            'received_amount' => 499.00,
        ])->assertNotFound();
        $this->assertSame(Order::REVIEW, $order->fresh()->status);
    }

    public function test_merchant_sees_review_panel_and_badges(): void
    {
        $order = $this->order();
        $this->post(route('pay.utr', $order), ['utr' => '412345678901']);
        $this->actingAs($this->merchant);

        $this->get(route('orders.show', $order))
            ->assertSee("Verify customer's payment", false)
            ->assertSee('FRAUD WARNING: Independent Bank Verification Required', false)
            ->assertSee('••••••••8901')
            ->assertDontSee('412345678901');
        $this->get(route('dashboard'))->assertSee('1 payment waiting for your review');
        $this->get(route('orders.index', ['status' => 'review']))->assertSee($order->order_no)->assertSee('submitted by customer');
    }

    public function test_manual_utr_fraud_protection_workflow_guarantees(): void
    {
        $order = $this->order(250.00);
        $this->post(route('pay.utr', $order), ['utr' => '987654321098']);

        // 1. Unauthenticated approval attempt is blocked
        $this->post(route('orders.approve-utr', $order), [
            'bank_utr' => '987654321098',
            'received_amount' => 250.00,
        ])->assertRedirect(route('login'));

        $this->actingAs($this->merchant);

        // 2. Copied partial UTR (e.g. attempting to submit only visible 4 digits) fails
        $this->post(route('orders.approve-utr', $order), [
            'bank_utr' => '1098',
            'received_amount' => 250.00,
        ])->assertSessionHasErrors('bank_utr');

        // 3. Wrong amount fails validation
        $this->post(route('orders.approve-utr', $order), [
            'bank_utr' => '987654321098',
            'received_amount' => 200.00,
        ])->assertSessionHasErrors('received_amount');

        // 4. Duplicate UTR check: another order with same UTR cannot be approved
        $first = $this->order(100.00);
        $this->post(route('pay.utr', $first), ['utr' => '123456789012']);
        $this->post(route('orders.approve-utr', $first), [
            'bank_utr' => '123456789012',
            'received_amount' => 100.00,
        ])->assertSessionHasNoErrors();

        $second = $this->order(100.00);
        // Force status to review with same UTR
        $second->forceFill(['status' => Order::REVIEW, 'customer_utr' => '123456789012'])->save();
        $this->post(route('orders.approve-utr', $second), [
            'bank_utr' => '123456789012',
            'received_amount' => 100.00,
        ])->assertSessionHasErrors('bank_utr');
    }
}
