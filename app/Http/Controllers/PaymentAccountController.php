<?php

namespace App\Http\Controllers;

use App\Models\PaymentAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentAccountController extends Controller
{
    public function index(Request $request)
    {
        return view('accounts.index', [
            'accounts' => $request->user()->paymentAccounts()->withCount('orders')->withUsageToday()->oldest()->get(),
            'rotation' => $request->user()->rotation_mode,
        ]);
    }

    public function create()
    {
        $providers = PaymentAccount::PROVIDERS;
        if (! (bool) config('gateway.enable_paytm_business', true)) {
            unset($providers['paytm_business']);
        }

        return view('accounts.create', ['providers' => $providers]);
    }

    public function store(Request $request)
    {
        $provider = $request->input('provider');
        if ($provider === 'paytm_business' && ! (bool) config('gateway.enable_paytm_business', true)) {
            throw ValidationException::withMessages([
                'provider' => 'Paytm for Business integration is disabled by platform policy.',
            ]);
        }

        $hosted = PaymentAccount::PROVIDERS[$provider]['hosted'] ?? false;

        $data = $request->validate([
            'provider' => ['required', Rule::in(array_keys(PaymentAccount::PROVIDERS))],
            'label' => ['required', 'string', 'max:60'],
            // PG accounts have no UPI ID of their own; customers pay on the PG's checkout.
            'upi_id' => [Rule::requiredIf(! $hosted), 'nullable', 'string', 'max:100', 'regex:/^[a-zA-Z0-9.\-_]{2,256}@[a-zA-Z]{2,64}$/'],
            'payee_name' => ['required', 'string', 'max:60'],
            'daily_limit' => ['nullable', 'numeric', 'min:1', 'max:10000000'],
            'mid' => ['required_if:provider,paytm_business,paytm_pg', 'nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9]+$/'],
            'merchant_key' => ['required_if:provider,paytm_pg', 'nullable', 'string', 'max:100'],
            'website_name' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_]+$/'],
            'client_id' => ['required_if:provider,phonepe_pg', 'nullable', 'string', 'max:150'],
            'client_secret' => ['required_if:provider,phonepe_pg', 'nullable', 'string', 'max:200'],
            'client_version' => ['required_if:provider,phonepe_pg', 'nullable', 'string', 'max:10'],
            'environment' => [Rule::requiredIf($hosted), 'nullable', Rule::in(['sandbox', 'production'])],
        ], [
            'upi_id.regex' => 'Enter a valid UPI ID, e.g. yourname@paytm.',
            'upi_id.required' => 'UPI ID is required for this account type.',
            'mid.required_if' => 'Paytm MID is required.',
            'merchant_key.required_if' => 'Paytm Merchant Key is required.',
            'client_id.required_if' => 'PhonePe Client ID is required.',
            'client_secret.required_if' => 'PhonePe Client Secret is required.',
            'client_version.required_if' => 'PhonePe Client Version is required.',
        ]);

        $credentials = match ($data['provider']) {
            'paytm_business' => ['mid' => $data['mid']],
            'paytm_pg' => [
                'mid' => $data['mid'],
                'merchant_key' => $data['merchant_key'],
                'website_name' => ($data['website_name'] ?? null) ?: ($data['environment'] === 'production' ? 'DEFAULT' : 'WEBSTAGING'),
                'environment' => $data['environment'],
            ],
            'phonepe_pg' => [
                'client_id' => $data['client_id'],
                'client_secret' => $data['client_secret'],
                'client_version' => $data['client_version'],
                'environment' => $data['environment'],
            ],
            default => null,
        };

        $user = $request->user();

        $user->paymentAccounts()->create([
            'provider' => $data['provider'],
            'label' => $data['label'],
            'upi_id' => $hosted ? null : $data['upi_id'],
            'payee_name' => $data['payee_name'],
            'credentials' => $credentials,
            'is_default' => ! $user->paymentAccounts()->exists(),
            'daily_limit' => $data['daily_limit'] ?? null,
        ]);

        return redirect()->route('accounts.index')->with('status', 'UPI account added.');
    }

    public function updateLimit(Request $request, PaymentAccount $account)
    {
        $this->authorizeOwner($request, $account);

        $data = $request->validate(['daily_limit' => ['nullable', 'numeric', 'min:1', 'max:10000000']]);
        $account->update(['daily_limit' => $data['daily_limit'] ?? null]);

        return back()->with('status', $account->daily_limit === null
            ? "{$account->label}: daily limit removed."
            : "{$account->label}: daily limit set to ₹".number_format((float) $account->daily_limit).'.');
    }

    public function toggle(Request $request, PaymentAccount $account)
    {
        $this->authorizeOwner($request, $account);
        $account->update(['is_active' => ! $account->is_active]);

        return back()->with('status', $account->is_active ? 'Account enabled.' : 'Account disabled.');
    }

    public function makeDefault(Request $request, PaymentAccount $account)
    {
        $this->authorizeOwner($request, $account);

        DB::transaction(function () use ($request, $account) {
            $request->user()->paymentAccounts()->update(['is_default' => false]);
            $account->update(['is_default' => true]);
        });

        return back()->with('status', 'Default account updated.');
    }

    public function destroy(Request $request, PaymentAccount $account)
    {
        $this->authorizeOwner($request, $account);

        if ($account->orders()->exists()) {
            return back()->withErrors(['account' => 'This account has orders. Disable it instead of deleting.']);
        }

        $account->delete();

        return back()->with('status', 'Account deleted.');
    }

    private function authorizeOwner(Request $request, PaymentAccount $account): void
    {
        abort_unless($account->user_id === $request->user()->id, 404);
    }
}
