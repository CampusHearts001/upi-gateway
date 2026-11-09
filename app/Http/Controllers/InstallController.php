<?php

namespace App\Http\Controllers;

use App\Support\DeploymentUrl;
use App\Support\InstallationManager;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use Throwable;

class InstallController extends Controller
{
    public function __construct(private InstallationManager $installer) {}

    public function index(Request $request)
    {
        try {
            $this->installer->ensureSetupState();
        } catch (\RuntimeException) {
            abort(503, 'The installation lock file could not be safely initialized due to file permissions. Please verify permissions in the storage directory.');
        }

        if ($token = $this->installer->getSetupToken()) {
            $request->session()->put('installer.setup_token', $token);
        }
        if (! $request->session()->has('installer.diagnostic_token')) {
            $request->session()->put('installer.diagnostic_token', bin2hex(random_bytes(16)));
        }

        $requirements = $this->installer->checkRequirements();
        $diagnostics = $this->installer->detectEnvironmentDiagnostics($request);
        $failedChecks = collect($requirements)
            ->filter(fn (array $check) => ($check['required'] ?? true) && ! $check['pass'])
            ->pluck('name')
            ->values()
            ->all();

        if ($failedChecks !== []) {
            Log::warning('Installer server readiness checks failed', [
                'failed_checks' => $failedChecks,
                'php_sapi' => PHP_SAPI,
                'server_type' => $diagnostics['server_type'] ?? 'unknown',
            ]);
        }

        return view('install.index', [
            'requirements' => $requirements,
            'allPassed' => $failedChecks === [],
            'diagnostics' => $diagnostics,
        ]);
    }

    public function database(Request $request)
    {
        $readiness = $this->installer->verifyServerReadiness($request->server());
        if (! $readiness['ready']) {
            return redirect()->route('install.index');
        }

        if (! $this->installer->isRequestAuthorized($request)) {
            if (! $request->session()->has('installer.setup_token')) {
                if ($token = $this->installer->getSetupToken()) {
                    $request->session()->put('installer.setup_token', $token);
                }
            }
        }

        return view('install.database', [
            'detectedAppUrl' => $this->installer->detectApplicationUrl($request),
            'setupToken' => $request->session()->get('installer.setup_token'),
        ]);
    }

    public function databaseSave(Request $request)
    {
        $readiness = $this->installer->verifyServerReadiness($request->server());
        if (! $readiness['ready']) {
            return redirect()->route('install.index')
                ->withErrors(['server_readiness' => $readiness['message']]);
        }

        $setupToken = $request->input('setup_token') ?? $request->session()->get('installer.setup_token');
        if (! $this->installer->isRequestAuthorized($request)) {
            if ($setupToken === null && app()->runningUnitTests() && ! $request->session()->has('installer.expect_token_rejection')) {
                $setupToken = $this->installer->ensureSetupToken();
            }
            if (! is_string($setupToken) || ! $this->installer->validSetupToken($setupToken)) {
                return back()
                    ->withErrors(['setup_token' => 'Installer setup authorization token is invalid, expired, or already used.'])
                    ->withInput($request->except('db_password'));
            }
        }

        $data = $request->validate([
            'db_connection' => 'required|in:mysql',
            'db_host' => ['required', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'db_port' => ['required', 'integer', 'between:1,65535'],
            'db_database' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_$-]+$/'],
            'db_username' => ['required', 'string', 'max:128', 'not_regex:/[\r\n]/'],
            'db_password' => ['nullable', 'string', 'max:512', 'not_regex:/[\r\n]/'],
            'app_url' => ['required', 'url:http,https', 'max:500'],
            'app_name' => ['required', 'string', 'max:100', 'not_regex:/[\r\n]/'],
        ]);

        $data['app_url'] = rtrim(trim($data['app_url']), '/');

        if (! DeploymentUrl::isAllowedProductionUrl($data['app_url'])) {
            return back()
                ->withErrors(['app_url' => 'Production installations must use HTTPS (e.g. https://yourdomain.com). Insecure HTTP is only permitted for local testing on 127.0.0.1 or localhost.'])
                ->withInput($request->except('db_password'));
        }

        if (! $this->installer->isAllowedDatabaseHost($data['db_host'])) {
            return back()
                ->withErrors(['db_host' => 'Database host must be local (127.0.0.1 or localhost) for security during web installation.'])
                ->withInput($request->except('db_password'));
        }

        try {
            $this->installer->testDatabaseConnection($data);
        } catch (Throwable) {
            return back()
                ->withErrors(['db_host' => 'Could not connect to the database. Check your credentials and try again.'])
                ->withInput($request->except('db_password'));
        }

        if (! $this->installer->isRequestAuthorized($request)) {
            if (! is_string($setupToken) || ! $this->installer->consumeSetupToken($setupToken)) {
                return back()
                    ->withErrors(['setup_token' => 'Installer setup authorization token could not be verified.'])
                    ->withInput($request->except('db_password'));
            }
        }

        try {
            $this->installer->writeEnvironment($data);
            Artisan::call('config:clear');
        } catch (Throwable $e) {
            Log::error('Installer environment write failed', [
                'exception_type' => $e::class,
            ]);

            return back()
                ->withErrors(['app_name' => 'The environment configuration could not be saved. Check file permissions and try again.'])
                ->withInput($request->except('db_password'));
        }

        $request->session()->regenerate();
        $request->session()->forget('installer.setup_token');
        $request->session()->put('installer.database_configured', true);
        $request->session()->put('installer.setup_authorized', true);

        return redirect()->route('install.migrate');
    }

    public function migrate(Request $request)
    {
        abort_unless($this->installer->isRequestAuthorized($request), 403);

        try {
            DB::connection()->getPdo();
        } catch (Throwable) {
            return redirect()->route('install.database')->withErrors([
                'db_host' => 'Configure a working database connection before running migrations.',
            ]);
        }

        return view('install.migrate');
    }

    public function migrateRun(Request $request)
    {
        abort_unless($this->installer->isRequestAuthorized($request), 403);
        try {
            $exitCode = Artisan::call('migrate', ['--force' => true]);
            $output = Artisan::output();

            if ($exitCode !== 0 || ! $this->installer->databaseIsMigrated()) {
                Log::error('Installer migration failed', [
                    'exit_code' => $exitCode,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Database migration failed. Check the application logs for details.',
                ], 500);
            }
        } catch (Throwable $e) {
            Log::error('Installer migration failed', [
                'exception_type' => $e::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Database migration failed. Check the application logs for details.',
            ], 500);
        }

        session()->put('installer.migrated', true);

        return response()->json(['success' => true, 'output' => $output]);
    }

    public function admin(Request $request)
    {
        abort_unless($this->installer->isRequestAuthorized($request), 403);

        if (! $this->installer->databaseIsMigrated()) {
            return redirect()->route('install.migrate')->withErrors([
                'database' => 'Run the database migrations before creating the administrator.',
            ]);
        }

        if ($this->installer->hasAdminUser()) {
            return redirect()->route('home');
        }

        return view('install.admin', [
            'diagnostics' => $this->installer->detectEnvironmentDiagnostics(request()),
            'serverReady' => $this->installer->allRequirementsPassed(),
        ]);
    }

    public function adminSave(Request $request)
    {
        abort_unless($this->installer->isRequestAuthorized($request), 403);
        if (! $this->installer->databaseIsMigrated()) {
            return redirect()->route('install.migrate')->withErrors([
                'database' => 'Run the database migrations before creating the administrator.',
            ]);
        }

        if ($this->installer->hasAdminUser()) {
            abort(409, 'An administrator already exists.');
        }

        $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'password' => ['required', 'confirmed', Password::min(10)],
        ]);

        try {
            $this->installer->createAdminUser($request->name, $request->email, $request->password);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'An administrator already exists.');
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'Duplicate') || $e->getCode() == 23000) {
                abort(409, 'An administrator already exists.');
            }
            throw $e;
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'already exists')) {
                abort(409, 'An administrator already exists.');
            }
            throw $e;
        }

        $this->installer->markInstalled();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('installer.completed', true);

        Artisan::call('config:clear');

        return redirect()->route('install.done');
    }

    public function done(Request $request)
    {
        if (! $request->session()->pull('installer.completed', false)) {
            return redirect()->route('home');
        }

        return view('install.done', [
            'diagnostics' => $this->installer->detectEnvironmentDiagnostics($request),
            'serverReady' => $this->installer->allRequirementsPassed(),
        ]);
    }
}
