<?php

namespace Tests\Feature;

use App\Jobs\SendWebhook;
use App\Models\Order;
use App\Models\User;
use App\Models\WebhookOutbox;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookGenerationFencingTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->merchant = User::create(['name' => 'Fencing Test', 'email' => 'fencing@example.com', 'password' => 'password12345']);
        $this->merchant->regenerateApiCredentials();
        $this->merchant->update(['webhook_url' => 'https://merchant.example/hook']);
        $this->merchant->paymentAccounts()->create([
            'provider' => 'paytm_business', 'label' => 'Fencing Test', 'payee_name' => 'Test', 'upi_id' => 'test@upi',
            'credentials' => ['mid' => 'TESTMID'],
        ]);
        $this->order = app(OrderService::class)->create($this->merchant, ['amount' => 100]);
    }

    public function test_stale_job_with_mismatched_generation_aborts_before_http(): void
    {
        $event = WebhookOutbox::create([
            'event_id' => 'uuid-fenced-1',
            'order_id' => $this->order->id,
            'sequence' => 1,
            'transition_key' => 'paid:'.$this->order->id,
            'event' => 'order.paid',
            'environment' => Order::LIVE,
            'payload' => [
                'event_id' => 'uuid-fenced-1',
                'event_sequence' => 1,
                'event' => 'order.paid',
                'data' => $this->order->toApiArray(),
            ],
            'status' => 'queued',
            'dispatch_attempts' => 2, // Current generation in outbox is 2
            'delivery_attempts' => 0,
        ]);

        // Stale job carrying generation 1 (e.g. from failed_jobs)
        $staleJob = new SendWebhook($this->order, 'order.paid', 'uuid-fenced-1', 1);

        Http::fake();
        $staleJob->handle();

        // Zero HTTP requests sent
        Http::assertNothingSent();
        // delivery_attempts remains 0
        $this->assertSame(0, $event->fresh()->delivery_attempts);
    }

    public function test_current_generation_proceeds_to_http(): void
    {
        $event = WebhookOutbox::create([
            'event_id' => 'uuid-current-1',
            'order_id' => $this->order->id,
            'sequence' => 1,
            'transition_key' => 'paid:'.$this->order->id,
            'event' => 'order.paid',
            'environment' => Order::LIVE,
            'payload' => [
                'event_id' => 'uuid-current-1',
                'event_sequence' => 1,
                'event' => 'order.paid',
                'data' => $this->order->toApiArray(),
            ],
            'status' => 'queued',
            'dispatch_attempts' => 2,
            'delivery_attempts' => 0,
        ]);

        $currentJob = new SendWebhook($this->order, 'order.paid', 'uuid-current-1', 2);

        Http::fake([
            'https://merchant.example/hook' => Http::response(['status' => 'ok'], 200),
        ]);

        $currentJob->handle();

        Http::assertSentCount(1);
        $fresh = $event->fresh();
        $this->assertSame(1, $fresh->delivery_attempts);
        $this->assertSame('delivered', $fresh->status);
    }

    public function test_legacy_jobs_with_null_generation_remain_compatible(): void
    {
        $event = WebhookOutbox::create([
            'event_id' => 'uuid-legacy-1',
            'order_id' => $this->order->id,
            'sequence' => 1,
            'transition_key' => 'paid:'.$this->order->id,
            'event' => 'order.paid',
            'environment' => Order::LIVE,
            'payload' => [
                'event_id' => 'uuid-legacy-1',
                'event_sequence' => 1,
                'event' => 'order.paid',
                'data' => $this->order->toApiArray(),
            ],
            'status' => 'queued',
            'dispatch_attempts' => 1,
            'delivery_attempts' => 0,
        ]);

        // Legacy job dispatched without publication generation (null)
        $legacyJob = new SendWebhook($this->order, 'order.paid', 'uuid-legacy-1', null);

        Http::fake([
            'https://merchant.example/hook' => Http::response(['status' => 'ok'], 200),
        ]);

        $legacyJob->handle();

        Http::assertSentCount(1);
        $this->assertSame('delivered', $event->fresh()->status);
    }

    public function test_exhausting_maximum_dispatch_generations_transitions_to_blocked(): void
    {
        config(['webhooks.max_dispatch_generations' => 3]);

        $event = WebhookOutbox::create([
            'event_id' => 'uuid-exhausted-1',
            'order_id' => $this->order->id,
            'sequence' => 1,
            'transition_key' => 'paid:'.$this->order->id,
            'event' => 'order.paid',
            'environment' => Order::LIVE,
            'payload' => ['order_id' => $this->order->order_no],
            'status' => 'queued',
            'dispatch_attempts' => 3,
            'delivery_attempts' => 6,
        ]);

        $job = new SendWebhook($this->order, 'order.paid', 'uuid-exhausted-1', 3);
        $job->failed(new \RuntimeException('Connection timed out.'));

        $fresh = $event->fresh();
        $this->assertSame('blocked', $fresh->status);
        $this->assertSame('exhausted_generations', $fresh->last_error);
        $this->assertNull($fresh->available_at);
    }

    public function test_manual_retry_resets_dispatch_attempts_to_zero(): void
    {
        $event = WebhookOutbox::create([
            'event_id' => 'uuid-manual-retry',
            'order_id' => $this->order->id,
            'sequence' => 1,
            'transition_key' => 'paid:'.$this->order->id,
            'event' => 'order.paid',
            'environment' => Order::LIVE,
            'payload' => ['order_id' => $this->order->order_no],
            'status' => 'blocked',
            'dispatch_attempts' => 10,
            'last_error' => 'exhausted_generations',
            'delivered_at' => null,
        ]);

        $this->artisan('webhooks:retry', ['eventId' => 'uuid-manual-retry'])->assertSuccessful();

        $fresh = $event->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertSame(0, $fresh->dispatch_attempts); // Budget reset to 0!
        $this->assertNull($fresh->last_error);
    }
}
