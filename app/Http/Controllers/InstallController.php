<?php

namespace App\Http\Controllers;

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

    public function index()
    {
        $this->installer->ensureSetupToken();

        return view('install.index', ['requirements' => $this->installer->checkRequirements()]);
    }

    public function database()
    {
        $this->installer->ensureSetupToken();

        return view('install.database');
    }

    public function databaseSave(Request $request)
    {
        $setupToken = $request->input('setup_token');
        if (! $this->installer->consumeSetupToken(is_string($setupToken) ? $setupToken : null)) {
            abort(403, 'Invalid installer setup token.');
        }

        $request->session()->regenerate();

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

        try {
            $this->installer->testDatabaseConnection($data);
        } catch (Throwable) {
            return back()
                ->withErrors(['db_host' => 'Could not connect to the database. Check your credentials and try again.'])
                ->withInput($request->except('db_password', 'setup_token'));
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
                ->withInput($request->except('db_password', 'setup_token'));
        }

        $request->session()->put('installer.database_configured', true);
        $request->session()->put('installer.setup_authorized', true);

        return redirect()->route('install.migrate');
    }

    public function migrate()
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable) {
            return redirect()->route('install.database')->withErrors([
                'db_host' => 'Configure a working database connection before running migrations.',
            ]);
        }

        return view('install.migrate');
    }

    public function migrateRun()
    {
        abort_unless(session('installer.setup_authorized'), 403);
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

    public function admin()
    {
        if (! $this->installer->databaseIsMigrated()) {
            return redirect()->route('install.migrate')->withErrors([
                'database' => 'Run the database migrations before creating the administrator.',
            ]);
        }

        if ($this->installer->hasAdminUser()) {
            return redirect()->route('home');
        }

        return view('install.admin');
    }

    public function adminSave(Request $request)
    {
        abort_unless($request->session()->get('installer.setup_authorized'), 403);
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

        return view('install.done');
    }
}
