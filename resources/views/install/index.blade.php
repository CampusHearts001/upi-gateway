@extends('install.layout')
@php $step = 1; @endphp

@section('content')
<div class="text-center mb-6">
    <p class="installer-kicker">Step 1 · Readiness</p>
    <h1 class="mt-2 text-2xl font-bold text-slate-950 sm:text-3xl">System Requirements &amp; Readiness</h1>
    <p class="mx-auto mt-2 max-w-lg text-sm leading-6 text-slate-500">Complete verification of PHP environment, required extensions, permissions, and database support.</p>
</div>

@include('install.hosting-check', [
    'deploymentUrl' => $diagnostics['detected_app_url'],
    'serverReady' => $allPassed,
])

@include('install.requirements-overview', [
    'overview' => $overview,
    'summary' => $summary,
    'groups' => $groups,
    'requirements' => $requirements,
    'allPassed' => $allPassed,
    'deploymentUrl' => $diagnostics['detected_app_url'],
    'nextUrl' => route('install.database'),
    'recheckUrl' => route('install.recheck'),
    'nativeInstaller' => false,
])
@endsection
