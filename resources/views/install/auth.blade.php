@extends('install.layout')
@php $step = 1; @endphp

@section('content')
<h1 class="text-2xl font-bold text-slate-900">Setup Authentication</h1>
<p class="mt-1 text-sm text-slate-500">For security, unlock the installation wizard to configure your database.</p>

<form method="POST" action="{{ route('install.unlock') }}" class="mt-6 space-y-5">
    @csrf

    <div>
        <label class="label">Setup Password</label>
        <input type="password" name="setup_password" class="input" placeholder="Enter setup password" autofocus autocomplete="off" required>
        <p class="mt-1.5 text-xs text-slate-500">Read <code>storage/installer-setup-password</code> using cPanel or aaPanel File Manager. Enter the password above or sign in using HTTP Basic Auth.</p>
    </div>

    @if ($errors->any())
        <div class="rounded-lg bg-red-50 ring-1 ring-red-200 px-4 py-3 text-sm text-red-700">
            {{ $errors->first() }}
        </div>
    @endif

    <button type="submit" class="btn btn-primary w-full justify-center py-2.5">
        Unlock &amp; Continue <x-icon name="arrow-right" class="w-4 h-4" />
    </button>
</form>
@endsection
