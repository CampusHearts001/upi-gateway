@extends('layouts.guest')
@section('title', 'Welcome back')
@section('subtitle', 'Log in to your merchant dashboard.')

@section('content')
<form method="POST" action="{{ route('login') }}">
    @csrf
    <div class="mb-5">
        <label for="f-email" class="label">Email</label>
        <input id="f-email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email" @class(['input py-2.5', 'ring-red-400 focus:ring-red-500' => $errors->has('email')]) placeholder="you@business.com">
    </div>
    <div class="mb-5">
        <label for="f-password" class="label">Password</label>
        <input id="f-password" type="password" name="password" required autocomplete="current-password" @class(['input py-2.5', 'ring-red-400 focus:ring-red-500' => $errors->has('password')]) placeholder="••••••••">
    </div>
    <label class="flex items-center gap-2 text-sm text-slate-600 mb-6">
        <input type="checkbox" name="remember" class="rounded border-slate-300 text-brand-600 focus:ring-brand-600"> Keep me logged in
    </label>
    <button class="btn btn-primary w-full py-2.5">Log in <x-icon name="arrow-right" class="w-4 h-4" /></button>
</form>
@if (config('auth.allow_registration', false))
    <p class="text-sm text-center text-slate-500 mt-8">New to {{ config('app.name') }}? <a href="{{ route('register') }}" class="font-semibold text-brand-600 hover:text-brand-700">Create a free account</a></p>
@endif
@endsection
