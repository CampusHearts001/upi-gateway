@extends('install.layout')
@php $step = 1; @endphp

@section('content')
<h1 class="text-2xl font-bold text-slate-900">Server Requirements</h1>
<p class="mt-1 text-sm text-slate-500">Make sure your server meets all requirements before proceeding.</p>

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
        @if ($isAuthorized ?? false)
            <a href="{{ route('install.database') }}" class="btn btn-primary w-full justify-center py-2.5">
                Continue to database setup <x-icon name="arrow-right" class="w-4 h-4" />
            </a>
        @else
            <form method="POST" action="{{ route('install.unlock') }}" class="space-y-4">
                @csrf
                <div class="rounded-lg bg-slate-50 p-4 ring-1 ring-slate-200">
                    <label class="label">Setup Password</label>
                    <input type="password" name="setup_password" class="input mt-1" placeholder="Enter setup password" autocomplete="off" required>
                    <p class="mt-1.5 text-xs text-slate-500">Read <code>storage/installer-setup-password</code> in your hosting File Manager or server shell to unlock installation. (HTTP Basic Auth is also supported).</p>
                </div>
                @if ($errors->has('setup_password'))
                    <div class="rounded-lg bg-red-50 ring-1 ring-red-200 px-4 py-3 text-sm text-red-700">
                        {{ $errors->first('setup_password') }}
                    </div>
                @endif
                <button type="submit" class="btn btn-primary w-full justify-center py-2.5">
                    Unlock &amp; Continue to database setup <x-icon name="arrow-right" class="w-4 h-4" />
                </button>
            </form>
        @endif
    @else
        <div class="flex items-start gap-2 rounded-lg bg-red-50 ring-1 ring-red-200 px-4 py-3 text-sm text-red-700">
            <x-icon name="alert" class="w-5 h-5 shrink-0" /> <span>Please fix the failing requirements and refresh this page.</span>
        </div>
        <button data-reload-page class="btn btn-secondary w-full justify-center py-2.5 mt-3">Recheck</button>
    @endif
</div>
@endsection
