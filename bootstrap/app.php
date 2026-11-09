<?php

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\EnsureActive;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\RedirectToInstall;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetDeploymentBasePath;
use App\Http\Middleware\TrustConfiguredProxies;
use App\Support\DeploymentFilesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;

// Create missing runtime directories without following an external symlink or
// changing the permissions or contents of an existing directory.
DeploymentFilesystem::prepare(dirname(__DIR__));

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(SetDeploymentBasePath::class);
        $middleware->replace(TrustProxies::class, TrustConfiguredProxies::class);

        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'active' => EnsureActive::class,
            'api.key' => AuthenticateApiKey::class,
        ]);

        // AuthenticateSession invalidates sessions when the user's password hash
        // changes, covering password changes AND API-key regeneration (which
        // touches the password column to bump the hash seen by the middleware).
        // It must come AFTER StartSession and AFTER Auth middleware.
        $middleware->web(prepend: [
            RedirectToInstall::class,
        ], append: [
            AuthenticateSession::class,
            SecurityHeaders::class,
        ]);

        $middleware->api(prepend: [
            RedirectToInstall::class,
        ]);

        $middleware->redirectUsersTo(fn () => route('dashboard'));

        // Paytm PG POSTs the customer back here from its own domain.
        // install.php manages its own session CSRF validation independently.
        $middleware->validateCsrfTokens(except: ['pay/*/return', 'install.php', '*/install.php']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
