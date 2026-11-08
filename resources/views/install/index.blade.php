@extends('install.layout')
@php $step = 1; @endphp

@section('content')
<h1 class="text-2xl font-bold text-slate-900">Server Requirements</h1>
<p class="mt-1 text-sm text-slate-500">Make sure your server meets all requirements before proceeding.</p>

@if (!empty($diagnostics['requires_nginx_rules']))
<div class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-900 text-sm">
    <div class="flex items-center gap-2 font-semibold text-amber-800">
        <x-icon name="alert" class="w-5 h-5 text-amber-600 shrink-0" />
        <span>Nginx Subdirectory Configuration Notice</span>
    </div>
    <p class="mt-1 text-xs text-amber-800 leading-relaxed">
        Nginx does not process <code>.htaccess</code>. Back up the domain configuration in your panel. Replace the PHP-FPM placeholder below with the socket from the active PHP handler, then add these locations to the existing server block, preserving its root and other website locations. The application cannot configure Nginx automatically.
    </p>
    <details class="mt-2 text-xs">
        <summary class="font-medium cursor-pointer text-amber-900 hover:underline">View Nginx configuration rules</summary>
        <label>PHP-FPM address from the active panel PHP handler <input data-php-handler placeholder="unix:/path/from/your/panel.sock"></label>
        <button type="button" data-copy-nginx disabled>Copy configuration</button>
        <pre data-nginx-config class="mt-2 p-3 bg-slate-900 text-emerald-400 rounded-lg overflow-x-auto font-mono text-[11px] leading-snug">{{ $diagnostics['nginx_rule_snippet'] }}</pre>
    </details>
</div>
@endif

@include('install.hosting-check', ['deploymentUrl' => $diagnostics['detected_app_url']])

<div class="mt-6 space-y-2">
    @foreach ($requirements as $req)
        <div class="flex items-center justify-between rounded-lg px-4 py-3 {{ $req['pass'] ? 'bg-emerald-50 ring-1 ring-emerald-200' : 'bg-red-50 ring-1 ring-red-200' }}">
            <span class="text-sm font-medium {{ $req['pass'] ? 'text-emerald-800' : 'text-red-800' }}">{{ $req['name'] }}</span>
            <span class="grid h-7 w-7 place-items-center rounded-full {{ $req['pass'] ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700' }}"><x-icon :name="$req['pass'] ? 'check' : 'x'" class="w-4 h-4" /></span>
        </div>
    @endforeach
</div>

@php $allPass = collect($requirements)->every(fn($r) => $r['pass'] || !($r['required'] ?? true)); @endphp

<div class="mt-8">
    @if ($allPass)
        <a data-deployment-continue data-deployment-href="{{ route('install.database') }}" aria-disabled="true" class="btn btn-primary w-full justify-center py-2.5">
            Continue to database setup <x-icon name="arrow-right" class="w-4 h-4" />
        </a>
    @else
        <div class="flex items-start gap-2 rounded-lg bg-red-50 ring-1 ring-red-200 px-4 py-3 text-sm text-red-700">
            <x-icon name="alert" class="w-5 h-5 shrink-0" /> <span>Please fix the failing requirements and refresh this page.</span>
        </div>
        <button data-reload-page class="btn btn-secondary w-full justify-center py-2.5 mt-3">Recheck</button>
    @endif
</div>
@endsection
