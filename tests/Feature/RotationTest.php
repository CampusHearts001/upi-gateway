<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RotationTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = User::create(['name' => 'Ravi', 'email' => 'ravi@example.com', 'password' => 'password123']);
        $this->merchant->regenerateApiCredentials();
    }

    private function account(string $label, ?float $limit = 100000, bool $default = false, bool $active = true): PaymentAccount
    {
        return $this->merchant->paymentAccounts()->create([
            'provider' => 'manual', 'label' => $label, 'upi_id' => strtolower($label).'@upi',
            'payee_name' => 'Ravi', 'daily_limit' => $limit, 'is_default' => $default, 'is_active' => $active,
        ]);
    }

    private function order(float $amount, array $extra = [])
    {
        static $n = 0;

        return $this->postJson('/api/v1/orders', ['amount' => $amount, 'client_order_id' => 'R'.(++$n)] + $extra, [
            'X-Api-Key' => $this->merchant->api_key, 'X-Api-Secret' => $this->merchant->api_secret,
        ]);
    }

    public function test_random_rotation_spreads_orders_across_active_accounts(): void
    {
        $accounts = collect(['A', 'B', 'C'])->map(fn ($l) => $this->account($l));
        $this->account('Off', active: false);

        foreach (range(1, 60) as $i) {
            $this->order(10)->assertCreated();
        }

        $used = Order::pluck('payment_account_id')->unique();
        $this->assertEqualsCanonicalizing($accounts->pluck('id')->all(), $used->values()->all(), 'All 3 active accounts should be used, never the disabled one.');
    }

    public function test_full_account_is_skipped(): void
    {
        $small = $this->account('Small', 100);
        $big = $this->account('Big', 100000);

        // Small can take ₹100 in total; afterwards everything must go to Big.
        $this->order(100, ['payment_account_id' => $small->id])->assertCreated();

        foreach (range(1, 10) as $i) {
            $this->order(50)->assertCreated();
        }

        $this->assertSame(1, Order::where('payment_account_id', $small->id)->count());
        $this->assertSame(10, Order::where('payment_account_id', $big->id)->count());
    }

    public function test_limit_counts_paid_today_and_open_pending_but_not_expired(): void
    {
        $acc = $this->account('Only', 1000);

        $this->order(600)->assertCreated();                   // pending: 600 reserved
        $this->order(500)->assertUnprocessable()->assertJsonValidationErrors('amount');

        Order::query()->update(['status' => Order::EXPIRED]);  // expired orders free the room
        $this->order(500)->assertCreated();

        Order::where('status', Order::PENDING)->update(['status' => Order::PAID, 'paid_at' => now()]);
        $this->order(500)->assertCreated();                   // 500 paid + 500 pending = 1000
        $this->order(1)->assertUnprocessable();

        // Paid yesterday doesn't count today.
        Order::query()->update(['status' => Order::PAID, 'paid_at' => now()->subDay()]);
        $this->order(1000)->assertCreated();
    }

    public function test_explicit_account_is_used_while_it_has_room(): void
    {
        $this->account('A');
        $b = $this->account('B');

        foreach (range(1, 5) as $i) {
            $this->order(10, ['payment_account_id' => $b->id])->assertCreated();
        }

        $this->assertSame(5, Order::where('payment_account_id', $b->id)->count());
    }

    public function test_explicit_account_disabled_is_rejected_without_fallback(): void
    {
        $off = $this->account('Off', active: false);
        $other = $this->account('Other');

        $this->order(60, ['payment_account_id' => $off->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_account_id');

        $this->assertSame(0, Order::where('payment_account_id', $other->id)->count());
    }

    public function test_explicit_account_over_limit_is_rejected_without_fallback(): void
    {
        $tiny = $this->account('Tiny', 50);
        $other = $this->account('Other');

        $this->order(60, ['payment_account_id' => $tiny->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_account_id');

        $this->assertSame(0, Order::where('payment_account_id', $other->id)->count());
    }

    public function test_explicit_account_from_wrong_environment_is_rejected(): void
    {
        $sandboxAccount = $this->merchant->paymentAccounts()->create([
            'provider' => 'phonepe_pg',
            'label' => 'PhonePe Sandbox',
            'payee_name' => 'Ravi',
            'credentials' => [
                'client_id' => 'SANDBOX_CLIENT',
                'client_secret' => 'SECRET',
                'client_version' => '1',
                'environment' => 'sandbox',
            ],
            'is_active' => true,
        ]);

        $this->order(100, ['payment_account_id' => $sandboxAccount->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_account_id');
    }

    public function test_account_creation_ui_renders_manual_upi_compliance_advisory(): void
    {
        $this->actingAs($this->merchant);

        $this->get(route('accounts.create'))
            ->assertOk()
            ->assertSee('Commercial UPI Compliance Advisory')
            ->assertSee('registered merchant or current-account VPA')
            ->assertSee('personal savings-account VPAs')
            ->assertSee('gateway does not independently verify bank account ownership')
            ->assertSee('receiving-bank velocity limits, payment rejection, or operational account restrictions')
            ->assertDontSee('account freezes');
    }

    public function test_account_index_ui_renders_rotation_acceptable_use_advisory(): void
    {
        $this->account('Active');
        $this->actingAs($this->merchant);

        $this->get(route('accounts.index'))
            ->assertOk()
            ->assertSee('Rotation Acceptable-Use Advisory')
            ->assertSee('legitimate operational load distribution')
            ->assertSee('must not be used to bypass bank-imposed transaction limits')
            ->assertSee('receiving-bank velocity limits, payment rejection, or operational account restrictions')
            ->assertDontSee('account freezes');
    }

    public function test_unknown_or_foreign_account_id_is_rejected(): void
    {
        $this->account('Mine');
        $stranger = User::create(['name' => 'X', 'email' => 'x@example.com', 'password' => 'password123']);
        $foreign = $stranger->paymentAccounts()->create(['provider' => 'manual', 'label' => 'X', 'upi_id' => 'x@upi', 'payee_name' => 'X']);

        $this->order(10, ['payment_account_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('payment_account_id');
        $this->order(10, ['payment_account_id' => 99999])->assertUnprocessable()->assertJsonValidationErrors('payment_account_id');
    }

    public function test_no_limit_account_always_accepts(): void
    {
        $this->account('Unlimited', null);

        $this->order(100000)->assertCreated();
        $this->order(100000)->assertCreated();
    }

    public function test_default_mode_prefers_default_then_falls_back(): void
    {
        $this->merchant->update(['rotation_mode' => 'default']);
        $other = $this->account('Other');
        $main = $this->account('Main', 1000, default: true);

        $this->order(1000)->assertJsonPath('data.upi_intent', fn ($u) => str_contains($u, 'pa=main%40upi'));
        $this->order(10)->assertJsonPath('data.upi_intent', fn ($u) => str_contains($u, 'pa=other%40upi'));
    }

    public function test_accounts_api_lists_usage(): void
    {
        $acc = $this->account('Shop', 1000);
        $this->order(250)->assertCreated();

        $this->getJson('/api/v1/accounts', ['X-Api-Key' => $this->merchant->api_key, 'X-Api-Secret' => $this->merchant->api_secret])
            ->assertOk()
            ->assertJsonPath('rotation_mode', 'random')
            ->assertJsonPath('data.0.id', $acc->id)
            ->assertJsonPath('data.0.used_today', '250.00')
            ->assertJsonPath('data.0.remaining_today', '750.00');
    }

    public function test_merchant_can_update_limit_and_rotation_from_dashboard(): void
    {
        $acc = $this->account('Shop');
        $this->actingAs($this->merchant);

        $this->post(route('accounts.limit', $acc), ['daily_limit' => 50000])->assertRedirect();
        $this->assertSame('50000.00', $acc->fresh()->daily_limit);

        $this->post(route('accounts.limit', $acc), ['daily_limit' => ''])->assertRedirect();
        $this->assertNull($acc->fresh()->daily_limit);

        $this->post(route('settings.rotation'), ['rotation_mode' => 'default'])->assertRedirect();
        $this->assertSame('default', $this->merchant->fresh()->rotation_mode);

        $this->get(route('accounts.index'))->assertOk()->assertSee('Account rotation')->assertSee('ID '.$acc->id);
    }
}
