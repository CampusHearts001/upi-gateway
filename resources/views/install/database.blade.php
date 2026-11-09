@extends('install.layout')
@php $step = 2; @endphp

@section('content')
<p class="installer-kicker">Step 2 · Database</p>
<h1 class="mt-2 text-2xl font-bold text-slate-950 sm:text-3xl">Connect your database</h1>
<p class="mt-2 text-sm leading-6 text-slate-500">Enter the MySQL credentials provided by your hosting provider.</p>

<form method="POST" action="{{ route('install.database.save') }}" class="mt-6 space-y-5">
    @csrf

    <div>
        <label class="label">App Name</label>
        <input type="text" name="app_name" value="{{ old('app_name', 'UPI Gateway') }}" class="input" required>
    </div>

    <div>
        <label class="label">App URL</label>
        <input type="url" name="app_url" value="{{ old('app_url', $detectedAppUrl ?? 'http://localhost') }}" class="input" placeholder="https://yourdomain.com" required>
        <div class="mt-1 text-xs text-slate-500">Auto-detected application URL. Ensure this includes HTTPS and any subdirectory path.</div>
    </div>

    <input type="hidden" name="db_connection" value="mysql">
    <div class="installer-note flex items-start gap-3 px-4 py-3.5 text-sm leading-6 text-slate-600">
        <x-icon name="shield" class="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
        MySQL is required. Enter the credentials for your application database.
    </div>

    <div id="mysql_fields" class="space-y-4">
        <div class="grid gap-3 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <label class="label">Host</label>
                <input type="text" name="db_host" value="{{ old('db_host', '127.0.0.1') }}" class="input" placeholder="127.0.0.1">
            </div>
            <div>
                <label class="label">Port</label>
                <input type="text" name="db_port" value="{{ old('db_port', '3306') }}" class="input" placeholder="3306">
            </div>
        </div>
        <div>
            <label class="label">Database Name</label>
            <input type="text" name="db_database" value="{{ old('db_database', 'upi_gateway') }}" class="input" placeholder="upi_gateway">
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <label class="label">Username</label>
                <input type="text" name="db_username" value="{{ old('db_username', 'root') }}" class="input">
            </div>
            <div>
                <label class="label">Password</label>
                <input type="password" name="db_password" value="{{ old('db_password') }}" class="input">
            </div>
        </div>
    </div>

    @if ($errors->any())
        <div class="flex items-start gap-3 rounded-xl bg-red-50 ring-1 ring-red-200 px-4 py-3.5 text-sm leading-6 text-red-700">
            <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0" /><span>{{ $errors->first() }}</span>
        </div>
    @endif

    <button type="submit" class="btn btn-primary w-full justify-center py-2.5">
        Test connection and continue <x-icon name="arrow-right" class="w-4 h-4" />
    </button>
</form>
@endsection
