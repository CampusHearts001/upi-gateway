@extends('layouts.app')
@section('title', 'API & Webhooks')
@section('subtitle', 'Connect your website or app to accept payments automatically.')
@section('actions')
    <a href="{{ route('docs') }}" class="btn btn-secondary"><x-icon name="book" class="w-4 h-4" /> Read API docs</a>
@endsection

@section('content')
<div class="space-y-10">
    {{-- API keys --}}
    <section class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div>
            <h2 class="text-base font-semibold text-slate-900">API credentials</h2>
            <p class="mt-1 text-sm text-slate-500">Send these as <span class="kbd">X-Api-Key</span> and <span class="kbd">X-Api-Secret</span> headers. Keep the secret on your server only.</p>
        </div>
        <div class="card p-6 lg:col-span-2 space-y-5">
            <div>
                <label class="label">API key</label>
                <div class="flex gap-2">
                    <input readonly value="{{ $user->api_key }}" class="input font-mono bg-slate-50">
                    <x-copy :text="$user->api_key" />
                </div>
            </div>
            @if (session('live_api_secret_once_encrypted'))
                @php $liveSecretOnce = Crypt::decryptString(session('live_api_secret_once_encrypted')); @endphp
                <div class="rounded-lg bg-emerald-50 p-4 ring-1 ring-emerald-300 space-y-2">
                    <label class="label text-emerald-900 font-semibold">Live API secret · copy now (will not be shown again)</label>
                    <div class="flex gap-2">
                        <input id="secret" readonly value="{{ $liveSecretOnce }}" class="input font-mono bg-white text-emerald-950 font-semibold">
                        <x-copy :text="$liveSecretOnce" />
                    </div>
                    <p class="text-xs text-emerald-800">Store this secret securely on your server. It is also used to sign webhooks (HMAC-SHA256).</p>
                </div>
            @else
                <div>
                    <label class="label">API secret</label>
                    <div class="flex gap-2">
                        <input type="text" readonly value="••••••••••••••••••••••••••••••••••••••••" class="input font-mono bg-slate-50 text-slate-400 select-none" disabled>
                    </div>
                    <p class="help">Hidden for security. If you lost your secret, click "Regenerate" below to issue a new pair.</p>
                </div>
            @endif
            <div class="flex items-center justify-between gap-4 rounded-lg bg-red-50/60 ring-1 ring-red-100 px-4 py-3">
                <div class="text-sm text-red-800"><b>Regenerate keys</b><div class="text-xs text-red-700/80">Old keys stop working immediately.</div></div>
                <form method="POST" action="{{ route('settings.regenerate') }}">@csrf
                    <button data-confirm="Old keys will stop working immediately. Continue?" class="btn btn-danger btn-sm"><x-icon name="refresh" class="w-4 h-4" /> Regenerate</button>
                </form>
            </div>
        </div>
    </section>

    {{-- Sandbox --}}
    <section class="grid grid-cols-1 lg:grid-cols-3 gap-6 pt-10 border-t border-slate-200">
        <div>
            <div class="inline-flex rounded-full bg-amber-100 px-2.5 py-1 text-xs font-bold tracking-wide text-amber-800">SANDBOX · TEST ONLY</div>
            <h2 class="mt-3 text-base font-semibold text-slate-900">Sandbox credentials</h2>
            <p class="mt-1 text-sm text-slate-500">Create simulated payments without contacting Paytm, PhonePe, banks, or UPI providers.</p>
        </div>
        <div class="card p-6 lg:col-span-2 space-y-5 ring-amber-200">
            @if ($user->sandbox_api_key)
                <div>
                    <label class="label">Sandbox API key</label>
                    <div class="flex gap-2">
                        <input readonly value="{{ $user->sandbox_api_key }}" class="input font-mono bg-amber-50">
                        <x-copy :text="$user->sandbox_api_key" />
                    </div>
                </div>
                @if (session('sandbox_api_secret_once_encrypted'))
                    @php $sandboxSecretOnce = Crypt::decryptString(session('sandbox_api_secret_once_encrypted')); @endphp
                    <div class="rounded-lg bg-amber-50 p-4 ring-1 ring-amber-300">
                        <label class="label">Sandbox API secret · shown once</label>
                        <div class="flex gap-2">
                            <input readonly value="{{ $sandboxSecretOnce }}" class="input font-mono bg-white">
                            <x-copy :text="$sandboxSecretOnce" />
                        </div>
                        <p class="mt-2 text-xs font-medium text-amber-800">Copy this now. It cannot be retrieved after leaving this page.</p>
                    </div>
                @else
                    <p class="text-sm text-slate-500">The Sandbox secret is stored encrypted and cannot be displayed again. Regenerate it if lost.</p>
                @endif
                <div class="flex flex-wrap justify-end gap-2">
                    <form method="POST" action="{{ route('settings.sandbox.revoke') }}">@csrf
                        <button data-confirm="Revoke Sandbox credentials? Existing TEST integrations will stop working." class="btn btn-danger btn-sm">Revoke</button>
                    </form>
                    <form method="POST" action="{{ route('settings.sandbox.regenerate') }}">@csrf
                        <button data-confirm="Regenerate Sandbox credentials? The old TEST credentials will stop working." class="btn btn-secondary btn-sm">Regenerate</button>
                    </form>
                </div>
            @else
                <p class="text-sm text-slate-600">No Sandbox credentials have been generated.</p>
                <form method="POST" action="{{ route('settings.sandbox.regenerate') }}" class="flex justify-end">@csrf
                    <button class="btn btn-primary">Generate Sandbox credentials</button>
                </form>
            @endif

            <form method="POST" action="{{ route('settings.sandbox.webhook') }}" class="border-t border-slate-100 pt-5">
                @csrf
                <x-input name="sandbox_webhook_url" label="Sandbox webhook endpoint" type="url" :value="$user->sandbox_webhook_url" placeholder="https://sandbox.yoursite.com/webhook" class="font-mono"
                    help="Must differ from the Live webhook URL. TEST events are signed only with the Sandbox webhook secret." />
                <div class="flex justify-end"><button class="btn btn-primary">Save Sandbox webhook</button></div>
            </form>

            <div class="border-t border-slate-100 pt-5 space-y-3">
                <label class="label">Sandbox Webhook Signing Secret</label>
                @if (session('sandbox_webhook_secret_once_encrypted'))
                    @php $sbWhSecretOnce = Crypt::decryptString(session('sandbox_webhook_secret_once_encrypted')); @endphp
                    <div class="rounded-lg bg-amber-50 p-4 ring-1 ring-amber-300">
                        <label class="label text-amber-900 font-semibold">Sandbox Webhook Secret · copy now (shown once)</label>
                        <div class="flex gap-2">
                            <input readonly value="{{ $sbWhSecretOnce }}" class="input font-mono bg-white text-amber-950 font-semibold">
                            <x-copy :text="$sbWhSecretOnce" />
                        </div>
                        <p class="mt-2 text-xs font-medium text-amber-800">Copy this now. It cannot be retrieved after leaving this page.</p>
                    </div>
                @else
                    <div class="flex gap-2">
                        <input type="text" readonly value="{{ $user->sandbox_webhook_secret ? '••••••••••••••••••••••••••••••••••••••••' : 'Using Sandbox API secret (fallback)' }}" class="input font-mono bg-amber-50/50 text-slate-500 select-none" disabled>
                    </div>
                    <p class="help">Dedicated secret used to sign sandbox webhooks (separate from API authentication keys).</p>
                @endif
                <div class="flex justify-end">
                    <form method="POST" action="{{ route('settings.sandbox.regenerate-webhook-secret') }}">
                        @csrf
                        <button data-confirm="Generate a new Sandbox webhook signing secret?" class="btn btn-secondary btn-sm"><x-icon name="refresh" class="w-4 h-4" /> Regenerate Sandbox Secret</button>
                    </form>
                </div>
            </div>
        </div>
    </section>

    {{-- Webhook --}}
    <section class="grid grid-cols-1 lg:grid-cols-3 gap-6 pt-10 border-t border-slate-200">
        <div>
            <h2 class="text-base font-semibold text-slate-900">Webhook</h2>
            <p class="mt-1 text-sm text-slate-500">We POST <span class="kbd">order.paid</span> and <span class="kbd">order.expired</span> events here, retrying up to 6 times.</p>
        </div>
        <div class="card p-6 lg:col-span-2 space-y-5">
            <form method="POST" action="{{ route('settings.webhook') }}">
                @csrf
                <x-input name="webhook_url" label="Endpoint URL" type="url" :value="$user->webhook_url" placeholder="https://yoursite.com/upi/webhook" class="font-mono"
                    help="Verify the X-Signature header on every request. See the API docs for sample code." />
                <div class="flex justify-end"><button class="btn btn-primary">Save webhook</button></div>
            </form>

            <div class="border-t border-slate-100 pt-5 space-y-3">
                <label class="label">Dedicated Webhook Signing Secret</label>
                @if (session('live_webhook_secret_once_encrypted'))
                    @php $liveWhSecretOnce = Crypt::decryptString(session('live_webhook_secret_once_encrypted')); @endphp
                    <div class="rounded-lg bg-emerald-50 p-4 ring-1 ring-emerald-300">
                        <label class="label text-emerald-900 font-semibold">Live Webhook Secret · copy now (shown once)</label>
                        <div class="flex gap-2">
                            <input readonly value="{{ $liveWhSecretOnce }}" class="input font-mono bg-white text-emerald-950 font-semibold">
                            <x-copy :text="$liveWhSecretOnce" />
                        </div>
                        <p class="mt-2 text-xs font-medium text-emerald-800">Copy this now. It cannot be retrieved after leaving this page.</p>
                    </div>
                @else
                    <div class="flex gap-2">
                        <input type="text" readonly value="{{ $user->webhook_secret ? '••••••••••••••••••••••••••••••••••••••••' : 'Using Live API secret (fallback)' }}" class="input font-mono bg-slate-50 text-slate-500 select-none" disabled>
                    </div>
                    <p class="help">Dedicated secret used to sign Live webhooks (separate from API authentication keys).</p>
                @endif
                <div class="flex justify-end">
                    <form method="POST" action="{{ route('settings.regenerate-webhook-secret') }}">
                        @csrf
                        <button data-confirm="Generate a new Live webhook signing secret?" class="btn btn-secondary btn-sm"><x-icon name="refresh" class="w-4 h-4" /> Regenerate Webhook Secret</button>
                    </form>
                </div>
            </div>
        </div>
    </section>

    {{-- Profile --}}
    <section class="grid grid-cols-1 lg:grid-cols-3 gap-6 pt-10 border-t border-slate-200">
        <div>
            <h2 class="text-base font-semibold text-slate-900">Business profile</h2>
            <p class="mt-1 text-sm text-slate-500">Your business name is shown to customers on the checkout page.</p>
        </div>
        <form method="POST" action="{{ route('settings.profile') }}" class="card p-6 lg:col-span-2">
            @csrf
            <div class="grid sm:grid-cols-2 gap-x-4">
                <x-input name="name" label="Your name" :value="$user->name" required />
                <x-input name="business_name" label="Business name" :value="$user->business_name" required />
                <x-input name="phone" label="Phone" :value="$user->phone" />
                <div class="mb-5">
                    <label class="label">Email</label>
                    <input value="{{ $user->email }}" disabled class="input bg-slate-50 text-slate-500">
                </div>
            </div>
            <div class="flex justify-end"><button class="btn btn-primary">Save profile</button></div>
        </form>
    </section>
</div>
@endsection
