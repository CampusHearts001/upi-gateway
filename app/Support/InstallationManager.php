<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class InstallationManager
{
    public function hasAdminUser(): bool
    {
        try {
            if (Schema::hasTable('installation_guards')
                && DB::table('installation_guards')->where('name', 'primary_admin')->exists()) {
                return true;
            }

            return Schema::hasTable('users')
                && DB::table('users')->where('role', 'admin')->exists();
        } catch (Throwable) {
            return is_file($this->envPath());
        }
    }

    public function isInstalled(): bool
    {
        if (is_file($this->installedPath())) {
            return true;
        }

        // A lost marker must never reopen an existing installation while its
        // database is unavailable. A fresh installation has no .env yet.
        if (is_file($this->envPath())) {
            try {
                DB::connection()->getPdo();
            } catch (Throwable) {
                return ! $this->hasIncompleteSetup();
            }
        }

        return $this->hasAdminUser();
    }

    public function ensureSetupToken(): void
    {
        if (is_file($this->installedPath()) || (is_file($this->envPath()) && ! $this->hasIncompleteSetup())) {
            return;
        }
        $this->withSetupLock(function (): void {
            if (! is_file($this->envPath()) && ! $this->hasIncompleteSetup()) {
                $this->createPrivateFile($this->inProgressPath(), bin2hex(random_bytes(32)));
            }
            if (! $this->hasIncompleteSetup()) {
                return;
            }
            $record = $this->readTokenRecord();
            if ($record === null || $record['expires'] <= time()) {
                $this->writeTokenRecord();
            }
        });
    }

    public function validSetupToken(?string $token): bool
    {
        if (! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return false;
        }

        $record = $this->readTokenRecord();

        return $this->hasIncompleteSetup() && $record !== null
            && $record['expires'] > time() && hash_equals($record['token'], $token);
    }

    public function consumeSetupToken(?string $token): bool
    {
        return $this->withSetupLock(function () use ($token): bool {
            if (! $this->validSetupToken($token) || $this->isInstalled()) {
                return false;
            }
            // Rotate before database work. The old value cannot be replayed;
            // the new private value permits recovery after an interrupted request.
            $this->writeTokenRecord();

            return true;
        });
    }

    public function hasIncompleteSetup(): bool
    {
        return $this->privateFileIsValid($this->inProgressPath());
    }

    private function inProgressPath(): string
    {
        return config('installer.in_progress_path');
    }

    private function readTokenRecord(): ?array
    {
        $path = $this->setupTokenPath();
        if (! $this->privateFileIsValid($path)) {
            return null;
        }
        $parts = explode(':', (string) @file_get_contents($path), 2);
        if (count($parts) !== 2 || ! preg_match('/^[a-f0-9]{64}$/', $parts[0]) || ! ctype_digit($parts[1])) {
            return null;
        }

        return ['token' => $parts[0], 'expires' => (int) $parts[1]];
    }

    private function writeTokenRecord(): void
    {
        $ttl = (int) config('installer.setup_token_ttl', 3600);
        if ($ttl < 1) {
            throw new RuntimeException('Installer token lifetime must be positive.');
        }
        $path = $this->setupTokenPath();
        $contents = bin2hex(random_bytes(32)).':'.(time() + $ttl);
        if (file_exists($path) || is_link($path)) {
            if (! $this->privateFileIsValid($path)) {
                throw new RuntimeException('Installer token file is unsafe.');
            }
            $temporary = $path.'.'.bin2hex(random_bytes(8));
            $this->createPrivateFile($temporary, $contents);
            if (! rename($temporary, $path)) {
                @unlink($temporary);
                throw new RuntimeException('Installer token could not be rotated.');
            }
        } else {
            $this->createPrivateFile($path, $contents);
        }
    }

    private function createPrivateFile(string $path, string $contents): void
    {
        $previousUmask = umask(0077);
        try {
            $handle = @fopen($path, 'x');
        } finally {
            umask($previousUmask);
        }
        if ($handle === false) {
            throw new RuntimeException('Installer private file could not be created.');
        }
        try {
            if (! chmod($path, 0600) || fwrite($handle, $contents) !== strlen($contents)) {
                throw new RuntimeException('Installer private file could not be saved.');
            }
        } catch (Throwable $e) {
            @unlink($path);
            throw $e;
        } finally {
            fclose($handle);
        }
    }

    private function privateFileIsValid(string $path): bool
    {
        if (is_link($path) || ! is_file($path)) {
            return false;
        }
        $stat = @stat($path);

        return $stat !== false && ($stat['mode'] & 0777) === 0600
            && (! function_exists('posix_geteuid') || $stat['uid'] === posix_geteuid());
    }

    private function withSetupLock(callable $callback): mixed
    {
        $path = config('installer.setup_lock_path');
        if (file_exists($path) || is_link($path)) {
            if (! $this->privateFileIsValid($path)) {
                throw new RuntimeException('Installer lock file is unsafe.');
            }
        } else {
            try {
                $this->createPrivateFile($path, '');
            } catch (RuntimeException $e) {
                if (! $this->privateFileIsValid($path)) {
                    throw $e;
                }
            }
        }
        $handle = @fopen($path, 'r+');
        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Installer lock could not be acquired.');
        }
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function setupTokenPath(): string
    {
        return config('installer.setup_token_path', storage_path('installer-setup-token'));
    }

    public function databaseIsMigrated(): bool
    {
        try {
            if (! Schema::hasTable('migrations')) {
                return false;
            }

            $migrator = app('migrator');
            if (! $migrator->repositoryExists()) {
                return false;
            }

            $migrationFiles = array_keys($migrator->getMigrationFiles([database_path('migrations')]));
            if (empty($migrationFiles)) {
                return false;
            }

            $ran = $migrator->getRepository()->getRan();
            if (empty($ran)) {
                return false;
            }

            $pending = array_diff($migrationFiles, $ran);
            if (! empty($pending)) {
                return false;
            }

            $requiredTables = [
                'users',
                'cache',
                'jobs',
                'payment_accounts',
                'orders',
                'webhook_logs',
                'admin_audit_logs',
                'installation_guards',
                'webhook_outbox',
                'verification_nodes',
                'verification_lease_recovery_audits',
            ];

            foreach ($requiredTables as $table) {
                if (! Schema::hasTable($table)) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Inspect system and directory requirements for browser/cPanel installation.
     *
     * @param  array<string, bool>  $overrides
     * @return array<int, array{name: string, required: bool, pass: bool}>
     */
    public function checkRequirements(array $overrides = []): array
    {
        $storageDir = storage_path();
        $cacheDir = storage_path('framework/cache');
        $sessionsDir = storage_path('framework/sessions');
        $viewsDir = storage_path('framework/views');
        $logsDir = storage_path('logs');
        $bootstrapCacheDir = base_path('bootstrap/cache');
        $envFile = $this->envPath();
        $envDir = dirname($envFile);

        $checks = [
            ['name' => 'PHP >= 8.2', 'required' => true, 'pass' => version_compare(PHP_VERSION, '8.2.0', '>=')],
            ['name' => 'PDO Extension', 'required' => true, 'pass' => extension_loaded('pdo')],
            ['name' => 'PDO MySQL (required)', 'required' => true, 'pass' => extension_loaded('pdo_mysql')],
            ['name' => 'OpenSSL Extension', 'required' => true, 'pass' => extension_loaded('openssl')],
            ['name' => 'Mbstring Extension', 'required' => true, 'pass' => extension_loaded('mbstring')],
            ['name' => 'Tokenizer Extension', 'required' => true, 'pass' => extension_loaded('tokenizer')],
            ['name' => 'JSON Extension', 'required' => true, 'pass' => extension_loaded('json')],
            ['name' => 'cURL Extension', 'required' => true, 'pass' => extension_loaded('curl')],
            ['name' => 'Fileinfo Extension', 'required' => true, 'pass' => extension_loaded('fileinfo')],
            ['name' => 'XML Extension', 'required' => true, 'pass' => extension_loaded('xml')],
            ['name' => 'Ctype Extension', 'required' => true, 'pass' => extension_loaded('ctype')],
            ['name' => 'BCMath Extension', 'required' => true, 'pass' => extension_loaded('bcmath')],
            ['name' => 'storage/ writable', 'required' => true, 'pass' => is_dir($storageDir) && is_writable($storageDir)],
            ['name' => 'storage/framework/cache writable', 'required' => true, 'pass' => is_dir($cacheDir) && is_writable($cacheDir)],
            ['name' => 'storage/framework/sessions writable', 'required' => true, 'pass' => is_dir($sessionsDir) && is_writable($sessionsDir)],
            ['name' => 'storage/framework/views writable', 'required' => true, 'pass' => is_dir($viewsDir) && is_writable($viewsDir)],
            ['name' => 'storage/logs writable', 'required' => true, 'pass' => is_dir($logsDir) && is_writable($logsDir)],
            ['name' => 'bootstrap/cache/ writable', 'required' => true, 'pass' => is_dir($bootstrapCacheDir) && is_writable($bootstrapCacheDir)],
            ['name' => '.env writable', 'required' => true, 'pass' => is_writable($envFile) || (! file_exists($envFile) && is_writable($envDir))],
        ];

        foreach ($checks as &$check) {
            if (array_key_exists($check['name'], $overrides)) {
                $check['pass'] = (bool) $overrides[$check['name']];
            }
        }

        return $checks;
    }

    /**
     * @param  array<string, bool>  $overrides
     */
    public function allRequirementsPassed(array $overrides = []): bool
    {
        foreach ($this->checkRequirements($overrides) as $check) {
            if (($check['required'] ?? true) && ! $check['pass']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Safely create the initial administrator within a database transaction and guard.
     *
     * @throws RuntimeException
     */
    public function createAdminUser(string $name, string $email, string $password): User
    {
        if (! $this->databaseIsMigrated()) {
            throw new RuntimeException('Database migrations must be completed before creating an administrator.');
        }

        if ($this->hasAdminUser()) {
            throw new RuntimeException('An administrator already exists.');
        }

        return DB::transaction(function () use ($name, $email, $password) {
            if ($this->hasAdminUser()) {
                throw new RuntimeException('An administrator already exists.');
            }

            DB::table('installation_guards')->insert([
                'name' => 'primary_admin',
                'created_at' => now(),
            ]);

            if (User::where('role', 'admin')->exists()) {
                throw new RuntimeException('An administrator already exists.');
            }

            $admin = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'role' => 'admin',
                'is_active' => true,
            ]);

            DB::table('installation_guards')
                ->where('name', 'primary_admin')
                ->update(['user_id' => $admin->id]);

            return $admin;
        });
    }

    public function configureDatabase(array $data): void
    {
        if (($data['db_connection'] ?? null) !== 'mysql') {
            throw new RuntimeException('Only MySQL is supported as the application database.');
        }

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host' => $data['db_host'],
            'database.connections.mysql.port' => $data['db_port'],
            'database.connections.mysql.database' => $data['db_database'],
            'database.connections.mysql.username' => $data['db_username'],
            'database.connections.mysql.password' => $data['db_password'] ?? '',
        ]);

        DB::purge('mysql');
    }

    public function testDatabaseConnection(array $data): void
    {
        $originalDefault = config('database.default');
        $originalConnection = config('database.connections.mysql');

        try {
            $this->configureDatabase($data);
            DB::connection('mysql')->select('SELECT 1');
        } catch (Throwable $e) {
            DB::purge('mysql');
            config([
                'database.default' => $originalDefault,
                'database.connections.mysql' => $originalConnection,
            ]);

            throw $e;
        }
    }

    public function writeEnvironment(array $data): void
    {
        $path = $this->envPath();
        $template = base_path('.env.example');
        $env = is_file($path) ? file_get_contents($path) : file_get_contents($template);

        if ($env === false) {
            throw new RuntimeException('The environment template could not be read.');
        }

        $existingKey = $this->valueFromEnvironment($env, 'APP_KEY');
        $appKey = $existingKey !== ''
            ? $existingKey
            : 'base64:'.base64_encode(Encrypter::generateKey(config('app.cipher')));

        $existingNodeId = $data['verification_node_id'] ?? $this->valueFromEnvironment($env, 'GATEWAY_VERIFICATION_NODE_ID');
        $nodeId = $this->isPlaceholderNodeId($existingNodeId)
            ? bin2hex(random_bytes(16))
            : $existingNodeId;

        $replacements = [
            'APP_NAME' => $this->envValue($data['app_name']),
            'APP_ENV' => 'production',
            'APP_KEY' => $appKey,
            'APP_DEBUG' => 'false',
            'ALLOW_REGISTRATION' => 'false',
            'APP_URL' => $this->envValue($data['app_url']),
            'LOG_LEVEL' => 'error',
            'SESSION_SECURE_COOKIE' => str_starts_with(strtolower($data['app_url']), 'https://') ? 'true' : 'false',
            'GATEWAY_VERIFICATION_NODE_ID' => $this->envValue($nodeId),
            'DB_CONNECTION' => $data['db_connection'],
            'DB_HOST' => $this->envValue($data['db_host'] ?? '127.0.0.1'),
            'DB_PORT' => (string) ($data['db_port'] ?? '3306'),
            'DB_DATABASE' => $this->envValue($data['db_database']),
            'DB_USERNAME' => $this->envValue($data['db_username'] ?? ''),
            'DB_PASSWORD' => $this->envValue($data['db_password'] ?? ''),
        ];

        foreach ($replacements as $key => $value) {
            $pattern = '/^'.preg_quote($key, '/').'=.*/m';
            $env = preg_match($pattern, $env)
                ? preg_replace_callback($pattern, fn () => "{$key}={$value}", $env)
                : rtrim($env)."\n{$key}={$value}\n";
        }

        $directory = dirname($path);
        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new RuntimeException('The environment directory is not writable.');
        }

        $temporary = tempnam($directory, '.env-install-');
        if ($temporary === false || file_put_contents($temporary, $env, LOCK_EX) === false) {
            throw new RuntimeException('The environment file could not be written.');
        }

        chmod($temporary, 0600);
        if (! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('The environment file could not be replaced.');
        }

        config([
            'app.key' => $appKey,
            'app.env' => 'production',
            'app.debug' => false,
            'app.url' => $data['app_url'],
            'session.secure' => str_starts_with(strtolower($data['app_url']), 'https://'),
            'gateway.verification_node_id' => $nodeId,
        ]);
    }

    public function markInstalled(): void
    {
        $path = $this->installedPath();
        $directory = dirname($path);

        if (! is_dir($directory) || ! is_writable($directory)
            || file_put_contents($path, now()->toIso8601String(), LOCK_EX) === false) {
            throw new RuntimeException('Installation could not be finalized.');
        }

        chmod($path, 0600);
        if (is_file($this->setupTokenPath()) && ! @unlink($this->setupTokenPath())) {
            throw new RuntimeException('The installer setup token could not be removed.');
        }
        @unlink($this->inProgressPath());
    }

    public function installedPath(): string
    {
        return config('installer.installed_path', storage_path('installed'));
    }

    public function envPath(): string
    {
        return config('installer.env_path', base_path('.env'));
    }

    private function valueFromEnvironment(string $env, string $key): string
    {
        if (! preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $env, $matches)) {
            return '';
        }

        return trim($matches[1], " \t\n\r\0\x0B\"");
    }

    public function envValue(string $value): string
    {
        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }

    public function isPlaceholderNodeId(?string $identity): bool
    {
        if ($identity === null || trim($identity) === '') {
            return true;
        }

        $normalized = strtolower(trim($identity));

        return in_array($normalized, [
            'default', 'localhost', 'node', 'node-1', 'upi-gateway-node-1',
            'change-me', 'change-me-unique-node-id', 'your-node-id',
        ], true) || str_contains($normalized, 'change-me');
    }
}
