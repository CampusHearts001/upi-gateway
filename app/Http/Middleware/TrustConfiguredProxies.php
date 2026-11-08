<?php

namespace App\Http\Middleware;

use App\Support\TrustedProxyConfiguration;
use Closure;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

class TrustConfiguredProxies extends TrustProxies
{
    public function handle(Request $request, Closure $next)
    {
        $request::setTrustedProxies(
            TrustedProxyConfiguration::addresses(config('proxy.trusted_proxies')),
            Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
        );

        return $next($request);
    }
}
