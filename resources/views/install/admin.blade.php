@extends('install.layout')
@php $step = 4; @endphp

@section('content')
@include('install.hosting-check', ['deploymentUrl' => config('app.url'), 'serverReady' => $serverReady])
<p class="installer-kicker">Step 4 · Account</p>
<h1 class="mt-2 text-2xl font-bold text-slate-950 sm:text-3xl">Create your admin account</h1>
<p class="mt-2 text-sm leading-6 text-slate-500">Use these credentials to securely access and manage your dashboard.</p>

<form method="POST" action="{{ route('install.admin.save') }}" class="mt-6 space-y-5">
    @csrf

    <div>
        <label class="label">Full Name</label>
        <input type="text" name="name" value="{{ old('name') }}" class="input @error('name') ring-red-400 @enderror" placeholder="Mayank Singh" required>
        @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>

    <div>
        <label class="label">Email Address</label>
        <input type="email" name="email" value="{{ old('email') }}" @class(['input', 'ring-red-400 focus:ring-red-500' => $errors->has('email')]) placeholder="admin@example.com" required autocomplete="email">
        @error('email')<p class="mt-1.5 flex items-start gap-1.5 text-xs text-red-600"><x-icon name="alert" class="mt-px w-3.5 h-3.5" /> <span>{{ $message }}</span></p>@enderror
    </div>

    <div>
        <label class="label">Password</label>
        <input type="password" name="password" @class(['input', 'ring-red-400 focus:ring-red-500' => $errors->has('password')]) placeholder="Min 10 characters" required autocomplete="new-password">
        @error('password')<p class="mt-1.5 flex items-start gap-1.5 text-xs text-red-600"><x-icon name="alert" class="mt-px w-3.5 h-3.5" /> <span>{{ $message }}</span></p>@enderror
    </div>

    <div>
        <label class="label">Confirm Password</label>
        <input type="password" name="password_confirmation" class="input" required autocomplete="new-password">
    </div>

    <button hidden data-deployment-submit type="submit" disabled class="btn btn-primary w-full justify-center py-2.5">
        Create account and finish <x-icon name="arrow-right" class="w-4 h-4" />
    </button>
</form>
@endsection
