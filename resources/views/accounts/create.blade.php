@extends('layouts.app')
@section('title', 'Add payment account')
@section('subtitle', 'Customers pay directly into this account.')
@section('back', route('accounts.index'))

@section('content')
@php
    $meta = [
        'paytm_business' => [
            'Paytm for Business',
            'Your Paytm Business QR. Confirmed automatically via MID polling.',
            'bolt',
            'Unofficial / Experimental',
            'bg-amber-100 text-amber-800 ring-1 ring-amber-300',
        ],
        'phonepe_pg' => [
            'PhonePe PG',
            'Official PhonePe Payment Gateway. UPI, cards & netbanking.',
            'shield',
            'Official',
            'bg-emerald-50 text-emerald-700',
        ],
        'paytm_pg' => [
            'Paytm PG',
            'Official Paytm Payment Gateway with signed APIs.',
            'shield',
            'Official',
            'bg-emerald-50 text-emerald-700',
        ],
        'manual' => [
            'Any UPI ID',
            'Any bank or app. Customer submits UTR, you confirm it.',
            'qr',
            null,
            null,
        ],
    ];
    $selected = old('provider', null);
@endphp
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <form method="POST" action="{{ route('accounts.store') }}" class="card p-6 lg:col-span-2">
        @csrf
        <div class="label">Account type</div>
        <div class="grid sm:grid-cols-2 gap-3 mb-6">
            @foreach ($providers as $key => $provider)
                @php [$title, $desc, $icon, $badge, $badgeClass] = $meta[$key] ?? [$provider['label'], '', 'qr', null, 'bg-emerald-50 text-emerald-700']; @endphp
                <label class="relative flex gap-3 cursor-pointer rounded-xl p-4 ring-1 ring-slate-200 hover:ring-slate-300 has-[:checked]:ring-2 has-[:checked]:ring-brand-600 has-[:checked]:bg-brand-50/40 transition">
                    <input type="radio" name="provider" value="{{ $key }}" class="sr-only peer" @checked($selected === $key) data-provider-choice required>
                    <span class="grid place-items-center w-10 h-10 rounded-lg bg-white ring-1 ring-slate-200 text-slate-600 peer-checked:text-brand-600 shrink-0"><x-icon :name="$icon" class="w-5 h-5" /></span>
                    <span>
                        <span class="flex items-center gap-2 text-sm font-semibold text-slate-900">{{ $title }}
                            @if ($badge)<span class="rounded-full {{ $badgeClass }} px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide">{{ $badge }}</span>@endif
                        </span>
                        <span class="block mt-1 text-xs text-slate-500">{{ $desc }}</span>
                    </span>
                </label>
            @endforeach
        </div>
        @error('provider')<p class="mb-4 -mt-3 text-xs text-red-600">{{ $message }}</p>@enderror

        <div class="grid sm:grid-cols-2 gap-x-4">
            <x-input name="label" label="Account label" placeholder="e.g. Main shop" required />
            <x-input name="payee_name" label="Business / payee name" :value="auth()->user()->displayName()" required help="Shown to customers while paying." />
        </div>

        <div data-for="paytm_business" class="mb-5 rounded-xl bg-amber-50 p-4 ring-1 ring-amber-200 text-amber-900 text-xs">
            <div class="flex items-start gap-2.5">
                <x-icon name="alert" class="w-5 h-5 text-amber-600 shrink-0 mt-0.5" />
                <div class="space-y-1.5">
                    <span class="font-bold text-amber-950 text-sm">Paytm for Business Integration Advisory</span>
                    <p class="leading-relaxed">
                        Paytm for Business status verification relies on an unofficial merchant status endpoint without signed response verification or API SLAs. Paytm may rate-limit, alter, or discontinue this endpoint at any time without notice.
                    </p>
                    <p class="leading-relaxed">
                        For production-critical commercial operations, we strongly recommend using an official payment gateway provider (<span class="font-semibold">PhonePe PG</span> or <span class="font-semibold">Paytm PG</span>) that provides cryptographically signed API authentication and hosted checkout.
                    </p>
                </div>
            </div>
        </div>

        <div data-for="manual" class="mb-5 rounded-xl bg-amber-50 p-4 ring-1 ring-amber-200 text-amber-900 text-xs">
            <div class="flex items-start gap-2.5">
                <x-icon name="alert" class="w-5 h-5 text-amber-600 shrink-0 mt-0.5" />
                <div class="space-y-1.5">
                    <span class="font-bold text-amber-950 text-sm">Commercial UPI Compliance Advisory</span>
                    <p class="leading-relaxed">
                        For commercial payment collections, we strongly recommend using a registered merchant or current-account VPA. Using personal savings-account VPAs for business collections may create bank-policy violations, incoming payment blocks, or account restrictions by your bank.
                    </p>
                    <p class="leading-relaxed">
                        For production commercial transaction volumes, official payment gateway providers (PhonePe PG or Paytm PG) are recommended. Please note that this gateway does not independently verify bank account ownership or legal account classification (Savings vs. Current).
                    </p>
                </div>
            </div>
        </div>

        <div data-for="paytm_business manual">
            <x-input name="upi_id" label="UPI ID" placeholder="paytmqr28100505xxxxxx@paytm" class="font-mono" />
        </div>

        <div data-for="phonepe_pg paytm_pg" class="mb-5">
            <span class="label">Environment</span>
            <div class="grid grid-cols-2 gap-3">
                @foreach (['sandbox' => ['Sandbox (test)', 'Test credentials. No real money.'], 'production' => ['Production (live)', 'Real payments with live credentials.']] as $env => [$envTitle, $envDesc])
                    <label class="cursor-pointer rounded-lg p-3 ring-1 ring-slate-200 has-[:checked]:ring-2 has-[:checked]:ring-brand-600">
                        <input type="radio" name="environment" value="{{ $env }}" class="sr-only" @checked(old('environment', 'sandbox') === $env)>
                        <span class="block text-sm font-semibold text-slate-900">{{ $envTitle }}</span>
                        <span class="block text-xs text-slate-500">{{ $envDesc }}</span>
                    </label>
                @endforeach
            </div>
            @error('environment')<p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>

        <div data-for="paytm_business paytm_pg">
            <x-input name="mid" label="Paytm Merchant ID (MID)" placeholder="e.g. AbCdEf12345678901234" class="font-mono"
                help="Paytm for Business app → Profile → Business settings, or Paytm dashboard → Developer settings → API keys." />
        </div>

        <div data-for="paytm_pg">
            <div class="grid sm:grid-cols-2 gap-x-4">
                <x-input name="merchant_key" label="Merchant Key" type="password" autocomplete="off" class="font-mono" help="Paytm dashboard → Developer settings → API keys." />
                <x-input name="website_name" label="Website name" placeholder="WEBSTAGING / DEFAULT" class="font-mono" help="Leave empty to use WEBSTAGING (sandbox) or DEFAULT (live)." />
            </div>
        </div>

        <div data-for="phonepe_pg">
            <div class="grid sm:grid-cols-2 gap-x-4">
                <x-input name="client_id" label="Client ID" autocomplete="off" class="font-mono" />
                <x-input name="client_version" label="Client Version" placeholder="e.g. 1" class="font-mono" />
            </div>
            <x-input name="client_secret" label="Client Secret" type="password" autocomplete="off" class="font-mono" help="PhonePe Business dashboard → Developer settings → API keys." />
        </div>

        <x-input name="daily_limit" label="Daily limit (₹)" type="number" min="1" step="1" value="100000"
            help="Max amount this account accepts per day. Once reached, new orders rotate to your other accounts. Leave empty for no limit." />

        <div class="flex justify-end gap-2 border-t border-slate-100 pt-5 mt-2">
            <a href="{{ route('accounts.index') }}" class="btn btn-secondary">Cancel</a>
            <button class="btn btn-primary">Save account</button>
        </div>
    </form>

    <div class="space-y-4">
        <div class="card p-5">
            <div class="flex items-center gap-2 text-sm font-semibold text-slate-900"><x-icon name="shield" class="w-5 h-5 text-emerald-600" /> Your money stays yours</div>
            <p class="mt-2 text-sm text-slate-600">Payments go straight to your own UPI or PG account. {{ config('app.name') }} only creates the payment and checks its status. We never hold or settle funds.</p>
        </div>
        <div class="card p-5" data-for="paytm_business">
            <div class="flex items-center gap-2 text-sm font-semibold text-slate-900"><x-icon name="bolt" class="w-5 h-5 text-amber-600" /> Paytm for Business (Unofficial)</div>
            <ol class="mt-3 space-y-2 text-sm text-slate-600 list-decimal list-inside">
                <li>Use the UPI ID of your Paytm for Business QR.</li>
                <li>Enter your MID so the gateway can query transaction status.</li>
                <li>Orders are marked paid automatically once the status and amount match.</li>
            </ol>
            <div class="mt-3 rounded-lg bg-amber-50 p-3 ring-1 ring-amber-200 text-xs text-amber-900">
                <span class="font-semibold text-amber-950">Integration Notice:</span>
                <p class="mt-1 leading-relaxed">
                    Uses Paytm's merchant status endpoint, which is not an official public API and does not provide cryptographically signed responses. For production reliability, use PhonePe PG or Paytm PG.
                </p>
            </div>
        </div>
        <div class="card p-5" data-for="phonepe_pg paytm_pg">
            <div class="flex items-center gap-2 text-sm font-semibold text-slate-900"><x-icon name="shield" class="w-5 h-5 text-sky-600" /> How the official PG works</div>
            <ol class="mt-3 space-y-2 text-sm text-slate-600 list-decimal list-inside">
                <li>The customer opens your payment link and is sent to the PG's secure checkout.</li>
                <li>They pay with UPI, card or netbanking.</li>
                <li>We confirm the payment with the PG's official Status API and record the UTR.</li>
            </ol>
            <p class="mt-3 text-xs text-slate-500">Settlement and PG charges follow your agreement with PhonePe/Paytm. Start with Sandbox credentials to test.</p>
        </div>
        <div class="card p-5" data-for="manual">
            <div class="flex items-center gap-2 text-sm font-semibold text-slate-900"><x-icon name="receipt" class="w-5 h-5 text-sky-600" /> How manual verification works</div>
            <p class="mt-2 text-sm text-slate-600">After paying, the customer enters their 12-digit UTR. You match it with your bank/UPI app and approve.</p>
            <div class="mt-3 rounded-lg bg-amber-50 p-3 ring-1 ring-amber-200 text-xs text-amber-900">
                <span class="font-semibold text-amber-950">Compliance Notice:</span>
                <p class="mt-1 leading-relaxed">
                    Personal savings-account VPAs are intended for personal P2P transfers. Using them for business collections risks receiving-bank velocity limits, payment rejection, or operational account restrictions under individual bank terms of service. Use a registered merchant current-account VPA or official PG for production sales.
                </p>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script nonce="{{ $csp_nonce }}">
    function syncProvider() {
        const provider = document.querySelector('input[name=provider]:checked')?.value;
        document.querySelectorAll('[data-for]').forEach(el => {
            const show = Boolean(provider && el.dataset.for.split(' ').includes(provider));
            el.style.display = show ? '' : 'none';
            // Hidden fields must not be submitted with stale values.
            el.querySelectorAll('input:not([type=radio])').forEach(i => i.disabled = !show);
        });
    }
    document.querySelectorAll('[data-provider-choice]').forEach(el => el.addEventListener('change', syncProvider));
    syncProvider();
</script>
@endpush
@endsection
