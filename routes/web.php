<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\TransactionDebuggerController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChangePasswordController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\ConfirmPasswordController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentAccountController;
use App\Http\Controllers\PublicStorageController;
use App\Http\Controllers\SandboxCheckoutController;
use App\Http\Controllers\SettingsController;
use App\Http\Middleware\RedirectIfInstalled;
use App\Support\InstallationManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

// Public diagnostic metadata only; POST proves a session-bound CSRF round trip.
Route::get('/__upi_deployment_check', function (Request $request, InstallationManager $installer) {
    $probe = bin2hex(random_bytes(16));
    $diagnosticToken = bin2hex(random_bytes(16));
    $request->session()->put('deployment.probe', $probe);
    $request->session()->put('deployment.diagnostic', $diagnosticToken);

    return response()->json([
        'application' => 'upi-gateway', 'routing' => true, 'runtime' => 'laravel',
        'base_path' => rtrim((string) parse_url(config('app.url'), PHP_URL_PATH), '/'),
        'cookie_path' => config('session.path'), 'csrf_token' => csrf_token(), 'probe' => $probe, 'diagnostic_token' => $diagnosticToken,
        'secure' => $request->isSecure(), 'php_fpm' => PHP_SAPI === 'fpm-fcgi',
        'php_handler' => $installer->detectPhpHandler(),
        'login_url' => route('login'), 'dashboard_url' => route('dashboard'), 'installed' => $installer->isInstalled(),
    ])->header('Cache-Control', 'no-store');
});
Route::post('/__upi_deployment_check', function (Request $request, InstallationManager $installer) {
    if ($request->boolean('diagnostic_report')) {
        if ($installer->isInstalled()) {
            abort(403, 'Application is already installed.');
        }

        abort_unless(is_string($request->input('diagnostic_token'))
            && hash_equals((string) $request->session()->pull('deployment.diagnostic'), $request->input('diagnostic_token')), 403);
        $data = $request->validate([
            'stage' => ['required', 'string', 'max:40', 'regex:/^[a-z_]+$/'],
            'message' => ['required', 'string', 'max:500'],
        ]);
        $sanitizedMessage = preg_replace('/(?:\x1b\[[0-9;]*[a-zA-Z]|[\x00-\x1f\x7f])/', ' ', $data['message']);
        $sanitizedMessage = trim(preg_replace('/\s+/', ' ', (string) $sanitizedMessage));
        Log::warning('Installer browser readiness check failed', [
            'stage' => $data['stage'],
            'message' => mb_substr($sanitizedMessage, 0, 500),
            'url_prefix' => rtrim((string) parse_url(config('app.url'), PHP_URL_PATH), '/'),
        ]);

        return response()->json(['logged' => true])->header('Cache-Control', 'no-store');
    }

    abort_unless(is_string($request->input('probe')) && hash_equals((string) $request->session()->pull('deployment.probe'), $request->input('probe')), 403);

    return response()->json(['session' => true, 'csrf' => true])->header('Cache-Control', 'no-store');
});

// ── Installer ─────────────────────────────────────────────────────────────────
Route::any('/install.php', function () {
    http_response_code(200);
    $GLOBALS['__installer_location'] = null;
    @header_remove();
    ob_start();
    try {
        require base_path('install.php');
    } finally {
        $content = (string) ob_get_clean();
    }
    $code = http_response_code() ?: 200;
    if (! empty($GLOBALS['__installer_location'])) {
        return redirect($GLOBALS['__installer_location'], $code === 200 ? 302 : $code);
    }
    $response = response($content, $code);
    foreach (headers_list() as $header) {
        if (str_contains($header, ': ')) {
            [$name, $value] = explode(': ', $header, 2);
            if (in_array(strtolower($name), ['location', 'content-type', 'cache-control'], true)) {
                $response->header($name, $value);
            }
        }
    }
    @header_remove();

    return $response;
})->name('install.php');

Route::middleware(RedirectIfInstalled::class)->prefix('install')->name('install.')->group(function () {
    Route::get('/', [InstallController::class, 'index'])->name('index');
    Route::post('/recheck', [InstallController::class, 'recheck'])->name('recheck');
    Route::get('/database', [InstallController::class, 'database'])->name('database');
    Route::post('/database', [InstallController::class, 'databaseSave'])->name('database.save');
    Route::get('/migrate', [InstallController::class, 'migrate'])->name('migrate');
    Route::post('/migrate', [InstallController::class, 'migrateRun'])->name('migrate.run');
    Route::get('/admin', [InstallController::class, 'admin'])->name('admin');
    Route::post('/admin', [InstallController::class, 'adminSave'])->name('admin.save');
    Route::get('/done', [InstallController::class, 'done'])->name('done');
});

Route::view('/', 'welcome')->name('home');
Route::view('/docs', 'docs')->name('docs');
Route::get('/storage/{path}', PublicStorageController::class)->where('path', '.*')->name('storage.public');

// ── Public checkout pages ─────────────────────────────────────────────────────
Route::get('/pay/{order}', [CheckoutController::class, 'show'])->name('pay.show');
Route::get('/pay/{order}/status', [CheckoutController::class, 'status'])->middleware('throttle:40,1')->name('pay.status');
Route::post('/pay/{order}/start', [CheckoutController::class, 'start'])->middleware('throttle:20,1')->name('pay.start');
Route::match(['get', 'post'], '/pay/{order}/return', [CheckoutController::class, 'return'])->middleware('throttle:30,1')->name('pay.return');
Route::post('/pay/{order}/utr', [CheckoutController::class, 'submitUtr'])->middleware('throttle:10,1')->name('pay.utr');
Route::get('/pay/{order}/qr.svg', [CheckoutController::class, 'qr'])->name('pay.qr');

Route::get('/sandbox/pay/{order}', [SandboxCheckoutController::class, 'show'])
    ->middleware('throttle:60,1')->name('sandbox.pay.show');
Route::post('/sandbox/pay/{order}/simulate', [SandboxCheckoutController::class, 'simulate'])
    ->middleware('throttle:20,1')->name('sandbox.pay.simulate');

// ── Guest auth ────────────────────────────────────────────────────────────────
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
});

// ── Password confirmation (requires auth, no active check — even disabled accounts
//    should be able to confirm before being bounced by EnsureActive) ────────────
Route::middleware('auth')->group(function () {
    Route::get('/confirm-password', [ConfirmPasswordController::class, 'show'])->name('password.confirm');
    Route::post('/confirm-password', [ConfirmPasswordController::class, 'confirm']);
});

// ── Authenticated routes ──────────────────────────────────────────────────────
Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/accounts', [PaymentAccountController::class, 'index'])->name('accounts.index');
    Route::get('/accounts/create', [PaymentAccountController::class, 'create'])->name('accounts.create');
    Route::post('/accounts', [PaymentAccountController::class, 'store'])->name('accounts.store');
    // Scope account bindings to the authenticated user so Merchant B cannot
    // reach Merchant A's accounts even if they guess the numeric ID.
    Route::post('/accounts/{account}/limit', [PaymentAccountController::class, 'updateLimit'])->name('accounts.limit')->can('update', 'account');
    Route::post('/accounts/{account}/toggle', [PaymentAccountController::class, 'toggle'])->name('accounts.toggle')->can('update', 'account');
    Route::post('/accounts/{account}/default', [PaymentAccountController::class, 'makeDefault'])->name('accounts.default')->can('update', 'account');
    Route::delete('/accounts/{account}', [PaymentAccountController::class, 'destroy'])->name('accounts.destroy')->can('delete', 'account');

    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/create', [OrderController::class, 'create'])->name('orders.create');
    Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('/orders/{order}/recheck', [OrderController::class, 'recheck'])->name('orders.recheck');
    Route::post('/orders/{order}/approve-utr', [OrderController::class, 'approveUtr'])->name('orders.approve-utr');
    Route::post('/orders/{order}/reject-utr', [OrderController::class, 'rejectUtr'])->name('orders.reject-utr');
    Route::post('/orders/{order}/mark-paid', [OrderController::class, 'markPaid'])->name('orders.mark-paid');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::post('/settings/profile', [SettingsController::class, 'updateProfile'])->name('settings.profile');
    Route::post('/settings/webhook', [SettingsController::class, 'updateWebhook'])->name('settings.webhook');
    Route::post('/settings/sandbox/webhook', [SettingsController::class, 'updateSandboxWebhook'])->name('settings.sandbox.webhook');
    Route::post('/settings/sandbox/regenerate-keys', [SettingsController::class, 'regenerateSandboxKeys'])->name('settings.sandbox.regenerate');
    Route::post('/settings/sandbox/revoke-keys', [SettingsController::class, 'revokeSandboxKeys'])->name('settings.sandbox.revoke');
    Route::post('/settings/rotation', [SettingsController::class, 'updateRotation'])->name('settings.rotation');
    Route::post('/settings/redirect-domains', [SettingsController::class, 'updateRedirectDomains'])->name('settings.redirect-domains');
    Route::post('/settings/regenerate-keys', [SettingsController::class, 'regenerateKeys'])->name('settings.regenerate');
    Route::post('/settings/regenerate-webhook-secret', [SettingsController::class, 'regenerateWebhookSecret'])->name('settings.regenerate-webhook-secret');
    Route::post('/settings/sandbox/regenerate-webhook-secret', [SettingsController::class, 'regenerateSandboxWebhookSecret'])->name('settings.sandbox.regenerate-webhook-secret');

    // Password change — uses Auth::logoutOtherDevices to sign out other sessions.
    Route::get('/settings/password', [ChangePasswordController::class, 'show'])->name('password.change.show');
    Route::put('/settings/password', [ChangePasswordController::class, 'update'])->name('password.change');

    // Admin routes — require password re-confirmation within the last 15 minutes.
    Route::prefix('admin')->name('admin.')->middleware(['admin', 'password.confirm:,900'])->group(function () {
        Route::get('/merchants', [AdminController::class, 'merchants'])->name('merchants');
        Route::post('/merchants/{user}/toggle', [AdminController::class, 'toggleMerchant'])->name('merchants.toggle');
        Route::get('/orders', [AdminController::class, 'orders'])->name('orders');
        Route::get('/orders/{order}', [AdminController::class, 'showOrder'])->name('order.show');
        Route::post('/orders/{order}/manual-payment-override', [AdminController::class, 'manualPaymentOverride'])
            ->can('manual-payment-override')->name('orders.manual-payment-override');
        Route::get('/transaction-debugger', [TransactionDebuggerController::class, 'index'])->name('transactions.index');
        Route::get('/transaction-debugger/{order}', [TransactionDebuggerController::class, 'show'])->name('transactions.show');
    });
});
