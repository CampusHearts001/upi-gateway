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
    private const CLOUDFLARE_PROXY_CIDRS = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    private const LOCAL_PROXY_CIDRS = ['127.0.0.1/8', '::1/128'];

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

    public function ensureSetupState(): void
    {
        if ($this->isInstalled()) {
            return;
        }
        $this->withSetupLock(function (): void {
            if ($this->isInstalled()) {
                return;
            }
            if (! is_file($this->envPath()) && ! $this->hasIncompleteSetup()) {
                $this->createPrivateFile($this->inProgressPath(), bin2hex(random_bytes(32)));
            }
            $record = $this->readTokenRecord();
            if ($record === null || $record['expires'] <= time()) {
                $this->writeTokenRecord();
            }
        });
    }

    public function isLocalDatabaseHost(string $host): bool
    {
        $normalized = strtolower(trim($host));

        if (in_array($normalized, ['127.0.0.1', 'localhost', '::1', 'localhost.localdomain'], true)) {
            return true;
        }

        if (str_starts_with($normalized, '/') && (str_ends_with($normalized, '.sock') || str_contains($normalized, 'mysql'))) {
            return true;
        }

        if (filter_var($normalized, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return str_starts_with($normalized, '127.');
        }

        return false;
    }

    public function isAllowedDatabaseHost(string $host): bool
    {
        if ($this->isLocalDatabaseHost($host)) {
            return true;
        }

        $allowRemote = (bool) config('installer.allow_remote_db', false);
        if (! $allowRemote) {
            $envVal = getenv('INSTALLER_ALLOW_REMOTE_DB')
                ?: ($_ENV['INSTALLER_ALLOW_REMOTE_DB'] ?? ($_SERVER['INSTALLER_ALLOW_REMOTE_DB'] ?? false));
            $allowRemote = filter_var($envVal, FILTER_VALIDATE_BOOLEAN);
        }

        if (! $allowRemote) {
            return false;
        }

        $normalized = trim($host);
        if ($normalized === '' || preg_match('/[\s\r\n\'"<>;&|`$]/', $normalized)) {
            return false;
        }

        if (filter_var($normalized, FILTER_VALIDATE_IP)) {
            return true;
        }

        return (bool) preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/', $normalized);
    }

    public function ipInRange(string $ip, string $range): bool
    {
        if ($ip === '' || $range === '') {
            return false;
        }

        if (! str_contains($range, '/')) {
            return $ip === $range;
        }

        [$subnet, $bits] = explode('/', $range, 2);
        $bits = (int) $bits;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        $remBits = $bits % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }

        if ($remBits > 0) {
            $mask = (0xFF << (8 - $remBits)) & 0xFF;
            $ipByte = ord($ipBin[$fullBytes]);
            $subnetByte = ord($subnetBin[$fullBytes]);
            if (($ipByte & $mask) !== ($subnetByte & $mask)) {
                return false;
            }
        }

        return true;
    }

    public function isHttps(array $server = []): bool
    {
        $server = ! empty($server) ? $server : ($_SERVER ?? []);

        // 1. Direct Web Server HTTPS indicators (trusted socket)
        if (! empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off') {
            return true;
        }

        if (isset($server['SERVER_PORT']) && (int) $server['SERVER_PORT'] === 443) {
            return true;
        }

        // 2. Identify if connection comes from a trusted reverse proxy or Cloudflare
        $remoteAddr = (string) ($server['REMOTE_ADDR'] ?? '');
        if ($remoteAddr === '') {
            return false;
        }

        $isCloudflareProxy = false;
        foreach (self::CLOUDFLARE_PROXY_CIDRS as $cidr) {
            if ($this->ipInRange($remoteAddr, $cidr)) {
                $isCloudflareProxy = true;
                break;
            }
        }

        $isLocalProxy = false;
        foreach (self::LOCAL_PROXY_CIDRS as $cidr) {
            if ($this->ipInRange($remoteAddr, $cidr)) {
                $isLocalProxy = true;
                break;
            }
        }

        $isConfiguredProxy = false;
        $trustedProxiesEnv = getenv('TRUSTED_PROXIES') ?: ($_ENV['TRUSTED_PROXIES'] ?? '');
        if (is_string($trustedProxiesEnv) && trim($trustedProxiesEnv) !== '') {
            foreach (array_map('trim', explode(',', $trustedProxiesEnv)) as $configured) {
                if ($configured !== '' && $this->ipInRange($remoteAddr, $configured)) {
                    $isConfiguredProxy = true;
                    break;
                }
            }
        }

        $isTrustedProxy = $isCloudflareProxy || $isLocalProxy || $isConfiguredProxy;

        if (! $isTrustedProxy) {
            return false;
        }

        // 3. Cloudflare CF-Visitor scheme detection
        if (! empty($server['HTTP_CF_VISITOR'])) {
            $visitor = json_decode((string) $server['HTTP_CF_VISITOR'], true);
            if (is_array($visitor) && isset($visitor['scheme']) && strtolower((string) $visitor['scheme']) === 'https') {
                return true;
            }
        }

        // 4. Standard X-Forwarded-Proto and X-Forwarded-Ssl from trusted proxies
        if (! empty($server['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $server['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }

        if (! empty($server['HTTP_X_FORWARDED_SSL']) && strtolower((string) $server['HTTP_X_FORWARDED_SSL']) === 'on') {
            return true;
        }

        return false;
    }

    public function detectedTrustedProxies(array $server = []): string
    {
        $server = ! empty($server) ? $server : ($_SERVER ?? []);
        $remoteAddr = (string) ($server['REMOTE_ADDR'] ?? '');

        foreach (self::CLOUDFLARE_PROXY_CIDRS as $cidr) {
            if ($this->ipInRange($remoteAddr, $cidr)) {
                return implode(',', self::CLOUDFLARE_PROXY_CIDRS);
            }
        }

        return '';
    }

    public function detectApplicationUrl($request = null, array $server = []): string
    {
        $server = ! empty($server) ? $server : ($_SERVER ?? []);
        $scheme = $this->isHttps($server) ? 'https' : 'http';

        $rawHost = (string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? 'localhost');
        $rawHost = trim($rawHost);

        $authority = parse_url($scheme.'://'.$rawHost);
        if (! is_array($authority) || empty($authority['host']) || isset($authority['path'])
            || isset($authority['user']) || isset($authority['query']) || isset($authority['fragment'])) {
            throw new \InvalidArgumentException('The request host is invalid.');
        }
        $host = $authority['host'];
        $port = $authority['port'] ?? null;
        if ($port !== null && ! (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
            $host .= ':'.$port;
        }

        return DeploymentUrl::normalize($scheme.'://'.$host.DeploymentUrl::basePath($server));
    }

    public function detectEnvironmentDiagnostics($request = null, array $server = []): array
    {
        $server = ! empty($server) ? $server : ($_SERVER ?? []);
        $rawSoftware = (string) ($server['SERVER_SOFTWARE'] ?? '');
        $softwareLower = strtolower($rawSoftware);

        if (str_contains($softwareLower, 'nginx')) {
            $serverType = 'nginx';
        } elseif (str_contains($softwareLower, 'litespeed') || str_contains($softwareLower, 'openlitespeed')) {
            $serverType = 'litespeed';
        } elseif (str_contains($softwareLower, 'apache')) {
            $serverType = 'apache';
        } elseif (str_contains($softwareLower, 'caddy')) {
            $serverType = 'caddy';
        } else {
            $serverType = 'unknown';
        }

        $appUrl = $this->detectApplicationUrl($request, $server);
        $urlParts = parse_url($appUrl);
        $subPath = trim((string) ($urlParts['path'] ?? ''), '/');
        $isSubdirectory = $subPath !== '';
        $subPrefix = $isSubdirectory ? '/'.$subPath : '';

        $documentRoot = str_replace('\\', '/', (string) ($server['DOCUMENT_ROOT'] ?? ''));
        $docRootIsPublic = realpath($documentRoot) !== false && realpath($documentRoot) === realpath(public_path());

        $isHttps = $this->isHttps($server);
        $requiresNginxNotice = ($serverType === 'nginx') && ($isSubdirectory || ! $docRootIsPublic);

        $nginxSnippet = DeploymentConfiguration::nginx($appUrl, base_path('public'));

        return [
            'server_software' => $rawSoftware !== '' ? $rawSoftware : 'Unknown',
            'server_type' => $serverType,
            'is_https' => $isHttps,
            'is_subdirectory' => $isSubdirectory,
            'subdirectory_path' => $subPrefix,
            'detected_app_url' => $appUrl,
            'document_root_is_public' => $docRootIsPublic,
            'requires_nginx_rules' => $requiresNginxNotice,
            'nginx_rule_snippet' => trim($nginxSnippet),
            'apache_rule_snippet' => DeploymentConfiguration::apache($appUrl, public_path()),
            'public_path' => public_path(),
            'application_path' => base_path(),
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'php_handler' => self::detectPhpHandler($server['PHP_SAPI'] ?? PHP_SAPI),
            'layout' => $isSubdirectory ? 'Subdirectory' : 'Domain or subdomain (same public-only mapping)',
            'wrapper_warning' => (bool) preg_match('/-(?:main|master|v?\d[\w.-]*)$/i', basename(base_path())),
            'rewrite_module' => function_exists('apache_get_modules') ? (in_array('mod_rewrite', apache_get_modules(), true) ? 'PASS' : 'FAIL') : 'NOT CHECKED',
            'php_handler_required' => $requiresNginxNotice,
        ];
    }

    public function isRequestAuthorized($request = null): bool
    {
        if (is_object($request) && method_exists($request, 'session')) {
            $session = $request->session();
            if (is_object($session) && method_exists($session, 'get') && $session->get('installer.setup_authorized') === true) {
                return true;
            }
        } elseif (! empty($_SESSION['_installer_setup_authorized'])) {
            return true;
        }

        return false;
    }

    public function hasIncompleteSetup(): bool
    {
        $path = $this->inProgressPath();
        if (! $this->privateFileIsValid($path) && is_file($path) && ! is_link($path)) {
            @chmod($path, 0600);
            clearstatcache(true, $path);
        }

        return $this->privateFileIsValid($path);
    }

    private function inProgressPath(): string
    {
        return config('installer.in_progress_path');
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

    public function privateFileIsValid(string $path): bool
    {
        if (is_link($path) || ! is_file($path)) {
            return false;
        }
        $stat = @stat($path);

        return $stat !== false && ($stat['mode'] & 0777) === 0600
            && (! function_exists('posix_geteuid') || $stat['uid'] === posix_geteuid());
    }

    public function setupTokenPath(): string
    {
        return config('installer.setup_token_path', storage_path('installer-setup-token'));
    }

    private function readTokenRecord(): ?array
    {
        $path = $this->setupTokenPath();
        if (! $this->privateFileIsValid($path)) {
            if (is_file($path) && ! is_link($path)) {
                @chmod($path, 0600);
                clearstatcache(true, $path);
            }
            if (! $this->privateFileIsValid($path)) {
                return null;
            }
        }
        $content = (string) @file_get_contents($path);
        $parts = explode(':', $content, 2);
        if (count($parts) !== 2 || ! preg_match('/^[a-f0-9]{64}$/', $parts[0]) || ! ctype_digit($parts[1])) {
            return null;
        }

        return ['token' => $parts[0], 'expires' => (int) $parts[1]];
    }

    private function writeTokenRecord(): string
    {
        $ttl = (int) config('installer.setup_token_ttl', 3600);
        if ($ttl < 1) {
            $ttl = 3600;
        }
        $token = bin2hex(random_bytes(32));
        $contents = $token.':'.(time() + $ttl);
        $path = $this->setupTokenPath();
        if (file_exists($path) || is_link($path)) {
            if (is_file($path) && ! is_link($path)) {
                @chmod($path, 0600);
                clearstatcache(true, $path);
            }
            if (! $this->privateFileIsValid($path)) {
                @unlink($path);
            }
        }
        if (file_exists($path)) {
            $temporary = $path.'.'.bin2hex(random_bytes(8));
            $this->createPrivateFile($temporary, $contents);
            if (! rename($temporary, $path)) {
                @unlink($temporary);
                throw new RuntimeException('Installer token could not be rotated.');
            }
        } else {
            $this->createPrivateFile($path, $contents);
        }

        return $token;
    }

    public function ensureSetupToken(): string
    {
        if ($this->isInstalled()) {
            return '';
        }

        return $this->withSetupLock(function (): string {
            $record = $this->readTokenRecord();
            if ($record === null || $record['expires'] <= time()) {
                return $this->writeTokenRecord();
            }

            return $record['token'];
        });
    }

    public function getSetupToken(): ?string
    {
        $record = $this->readTokenRecord();
        if ($record !== null && $record['expires'] > time()) {
            return $record['token'];
        }

        return null;
    }

    public function validSetupToken(?string $token): bool
    {
        if (! is_string($token) || ! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return false;
        }

        $record = $this->readTokenRecord();

        return $record !== null && $record['expires'] > time() && hash_equals($record['token'], $token);
    }

    public function consumeSetupToken(?string $token): bool
    {
        return $this->withSetupLock(function () use ($token): bool {
            if ($this->isInstalled()) {
                return false;
            }
            if (! $this->validSetupToken($token)) {
                return false;
            }
            $this->writeTokenRecord();

            return true;
        });
    }

    public function withSetupLock(callable $callback): mixed
    {
        $path = config('installer.setup_lock_path', storage_path('installer-setup-lock'));
        if (file_exists($path) || is_link($path)) {
            if (! $this->privateFileIsValid($path)) {
                if (is_file($path) && ! is_link($path)) {
                    @chmod($path, 0600);
                    clearstatcache(true, $path);
                }
                if (! $this->privateFileIsValid($path)) {
                    throw new RuntimeException('Installer lock file is unsafe.');
                }
            }
        } else {
            try {
                $this->createPrivateFile($path, '');
            } catch (RuntimeException $e) {
                if (is_file($path) && ! is_link($path)) {
                    @chmod($path, 0600);
                    clearstatcache(true, $path);
                }
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
     * Detect and classify PHP handler (SAPI) compatibility.
     *
     * Distinguishes supported (verified), compatible (alternative web handlers),
     * unsupported, and unknown handlers without claiming untested compatibility.
     *
     * @return array{sapi: string, name: string, status: string, supported: bool, message: string}
     */
    public static function detectPhpHandler(?string $sapi = null): array
    {
        $sapi = strtolower($sapi ?? PHP_SAPI);

        // Fully supported and verified execution handlers
        $supported = [
            'fpm-fcgi' => [
                'name' => 'PHP-FPM',
                'status' => 'supported',
                'supported' => true,
                'message' => 'PHP-FPM execution verified.',
            ],
            'cli' => [
                'name' => 'CLI',
                'status' => 'supported',
                'supported' => true,
                'message' => 'Command line execution.',
            ],
            'cli-server' => [
                'name' => 'PHP Built-in Server',
                'status' => 'supported',
                'supported' => true,
                'message' => 'Development server execution.',
            ],
        ];

        if (isset($supported[$sapi])) {
            return array_merge(['sapi' => $sapi], $supported[$sapi]);
        }

        // Known alternative web handlers: functional for web execution without claiming verified status
        $compatible = [
            'litespeed' => [
                'name' => 'LiteSpeed SAPI (LSAPI)',
                'status' => 'compatible',
                'supported' => true,
                'message' => 'LiteSpeed LSAPI detected.',
            ],
            'cgi-fcgi' => [
                'name' => 'CGI/FastCGI',
                'status' => 'compatible',
                'supported' => true,
                'message' => 'FastCGI execution detected.',
            ],
            'apache2handler' => [
                'name' => 'Apache 2.0 Handler (mod_php)',
                'status' => 'compatible',
                'supported' => true,
                'message' => 'Apache mod_php execution detected.',
            ],
        ];

        if (isset($compatible[$sapi])) {
            return array_merge(['sapi' => $sapi], $compatible[$sapi]);
        }

        // Known unsupported handlers
        $unsupported = [
            'phpdbg' => 'PHP Debugger (phpdbg)',
            'embed' => 'Embedded PHP',
            'cgi' => 'Raw CGI (non-FastCGI)',
        ];

        if (isset($unsupported[$sapi])) {
            return [
                'sapi' => $sapi,
                'name' => $unsupported[$sapi],
                'status' => 'unsupported',
                'supported' => false,
                'message' => "The PHP handler '{$sapi}' is not supported for web hosting.",
            ];
        }

        // Unknown / unrecognized handler
        return [
            'sapi' => $sapi,
            'name' => 'Unknown Handler ('.$sapi.')',
            'status' => 'unknown',
            'supported' => false,
            'message' => "Unrecognized PHP handler '{$sapi}'.",
        ];
    }

    /**
     * Enforce server-side readiness and document-root security.
     *
     * Validates that:
     * 1. System requirements and writable paths are satisfied.
     * 2. PHP handler is supported or compatible.
     * 3. Document root and web layout do not expose application-private files.
     *
     * Returns a safe, user-facing error message without exposing filesystem paths.
     *
     * @return array{ready: bool, reason: ?string, message: string, handler: array}
     */
    public function verifyServerReadiness(array $server = []): array
    {
        $server = ! empty($server) ? $server : ($_SERVER ?? []);
        $handler = self::detectPhpHandler($server['PHP_SAPI'] ?? PHP_SAPI);

        // 1. PHP Handler check
        if (! $handler['supported']) {
            return [
                'ready' => false,
                'reason' => 'php_handler',
                'message' => $handler['status'] === 'unsupported'
                    ? "The current PHP handler ({$handler['name']}) is not supported."
                    : 'Unrecognized PHP handler. PHP-FPM or a compatible handler is required.',
                'handler' => $handler,
            ];
        }

        // 2. System and directory requirements check
        if (! $this->allRequirementsPassed()) {
            return [
                'ready' => false,
                'reason' => 'requirements',
                'message' => 'Server readiness requirements are not met. Ensure required PHP extensions and writable directories are available.',
                'handler' => $handler,
            ];
        }

        // 3. Document Root and Private File Protection
        $rawSoftware = (string) ($server['SERVER_SOFTWARE'] ?? '');
        $softwareLower = strtolower($rawSoftware);
        $isNginx = str_contains($softwareLower, 'nginx');

        $documentRoot = str_replace('\\', '/', (string) ($server['DOCUMENT_ROOT'] ?? ''));
        $publicPath = realpath(public_path());
        $basePath = realpath(base_path());
        $docRootReal = realpath($documentRoot);
        $docRootIsPublic = $docRootReal !== false && $publicPath !== false && $docRootReal === $publicPath;

        // Ensure private files are not inside public/
        if ($publicPath !== false && (is_file($publicPath.'/.env') || is_file($publicPath.'/composer.json'))) {
            return [
                'ready' => false,
                'reason' => 'public_directory',
                'message' => 'The public directory layout is invalid. Application-private files must not be placed directly in the web root.',
                'handler' => $handler,
            ];
        }

        // If DocumentRoot is set and is NOT public/
        $handlerSapi = strtolower($server['PHP_SAPI'] ?? PHP_SAPI);
        if ($documentRoot !== '' && ! $docRootIsPublic && $handlerSapi !== 'cli' && $handlerSapi !== 'cli-server') {
            $urlParts = parse_url($this->detectApplicationUrl(null, $server));
            $isSubdirectory = trim((string) ($urlParts['path'] ?? ''), '/') !== '';

            // On Apache / LiteSpeed, root and public .htaccess must exist for private file protection
            if (! is_file(base_path('.htaccess')) || ! is_file(public_path('.htaccess'))) {
                return [
                    'ready' => false,
                    'reason' => 'htaccess_missing',
                    'message' => 'Web server configuration files (.htaccess) are missing. Ensure hidden files were extracted properly.',
                    'handler' => $handler,
                ];
            }

            // On Nginx, if DocumentRoot points directly to the project root on a main domain without public mapping
            if ($isNginx && ! $isSubdirectory && $basePath !== false && $docRootReal === $basePath) {
                return [
                    'ready' => false,
                    'reason' => 'nginx_root_exposure',
                    'message' => 'Nginx document root must point to the public directory to prevent private file exposure.',
                    'handler' => $handler,
                ];
            }
        }

        return [
            'ready' => true,
            'reason' => null,
            'message' => 'Server readiness verified.',
            'handler' => $handler,
        ];
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
        $handler = self::detectPhpHandler();

        $checks = [
            ['name' => 'PHP >= 8.2', 'required' => true, 'pass' => version_compare(PHP_VERSION, '8.2.0', '>=')],
            ['name' => 'PHP-FPM execution', 'required' => true, 'pass' => $handler['supported']],
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
            ['name' => 'Iconv Extension', 'required' => true, 'pass' => extension_loaded('iconv')],
            ['name' => 'storage/ writable', 'required' => true, 'pass' => is_dir($storageDir) && is_writable($storageDir)],
            ['name' => 'storage/framework/cache writable', 'required' => true, 'pass' => is_dir($cacheDir) && is_writable($cacheDir)],
            ['name' => 'storage/framework/sessions writable', 'required' => true, 'pass' => is_dir($sessionsDir) && is_writable($sessionsDir)],
            ['name' => 'storage/framework/views writable', 'required' => true, 'pass' => is_dir($viewsDir) && is_writable($viewsDir)],
            ['name' => 'storage/logs writable', 'required' => true, 'pass' => is_dir($logsDir) && is_writable($logsDir)],
            ['name' => 'bootstrap/cache/ writable', 'required' => true, 'pass' => is_dir($bootstrapCacheDir) && is_writable($bootstrapCacheDir)],
            ['name' => '.env writable', 'required' => true, 'pass' => is_writable($envFile) || (! file_exists($envFile) && is_writable($envDir))],
            ['name' => 'Session Support', 'required' => true, 'pass' => function_exists('session_status')],
            ['name' => 'Production Assets (Vite build)', 'required' => true, 'pass' => is_file(public_path('build/manifest.json'))],
        ];

        foreach ($checks as &$check) {
            if (array_key_exists($check['name'], $overrides)) {
                $check['pass'] = (bool) $overrides[$check['name']];
            }
        }

        return $checks;
    }

    public function validateDatabaseConfig(array $data): ?string
    {
        $dbHost = trim((string) ($data['db_host'] ?? ''));
        $dbPort = (int) ($data['db_port'] ?? 0);
        $dbDatabase = trim((string) ($data['db_database'] ?? ''));
        $dbUsername = trim((string) ($data['db_username'] ?? ''));
        $dbPassword = (string) ($data['db_password'] ?? '');
        $appName = trim((string) ($data['app_name'] ?? ''));
        $appUrl = rtrim(trim((string) ($data['app_url'] ?? '')), '/');

        if ($dbHost === '' || preg_match('/[\r\n]/', $dbHost)) {
            return 'Invalid database host.';
        }
        if (! $this->isAllowedDatabaseHost($dbHost)) {
            return 'Database host must be local (127.0.0.1 or localhost) for security during web installation.';
        }
        if ($dbPort < 1 || $dbPort > 65535) {
            return 'Database port must be between 1 and 65535.';
        }
        if ($dbDatabase === '' || ! preg_match('/^[A-Za-z0-9_$-]+$/', $dbDatabase)) {
            return 'Database name contains invalid characters.';
        }
        if ($dbUsername === '' || preg_match('/[\r\n]/', $dbUsername)) {
            return 'Invalid database username.';
        }
        if (preg_match('/[\r\n]/', $dbPassword)) {
            return 'Database password cannot contain newlines.';
        }
        if ($appName === '' || preg_match('/[\r\n]/', $appName)) {
            return 'Invalid application name.';
        }
        if ($appUrl === '' || ! filter_var($appUrl, FILTER_VALIDATE_URL)) {
            return 'Please enter a valid application URL (e.g. https://yourdomain.com).';
        }
        if (! DeploymentUrl::isAllowedProductionUrl($appUrl)) {
            return 'Production installations must use HTTPS (e.g. https://yourdomain.com). Insecure HTTP is only permitted for local testing on 127.0.0.1 or localhost.';
        }

        return null;
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
        if (! $this->isAllowedDatabaseHost($data['db_host'] ?? '')) {
            throw new RuntimeException('Database host must be local (127.0.0.1 or localhost) for security during web installation.');
        }

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
        $data['app_url'] = rtrim(trim($data['app_url']), '/');
        $data['app_url'] = DeploymentUrl::normalize($data['app_url']);
        if (! DeploymentUrl::isAllowedProductionUrl($data['app_url'])) {
            throw new InvalidArgumentException('Production installations must use HTTPS. Insecure HTTP is only permitted on 127.0.0.1 or localhost.');
        }
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
        $trustedProxies = trim((string) ($data['trusted_proxies']
            ?? $this->valueFromEnvironment($env, 'TRUSTED_PROXIES')));
        if ($trustedProxies === '') {
            $trustedProxies = $this->detectedTrustedProxies();
        }
        TrustedProxyConfiguration::addresses($trustedProxies);

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
            'ASSET_URL' => $this->envValue($data['app_url']),
            'TRUSTED_PROXIES' => $this->envValue($trustedProxies),
            'SESSION_PATH' => $this->envValue(DeploymentUrl::cookiePath($data['app_url'])),
            'SESSION_COOKIE' => $this->envValue(DeploymentUrl::cookieName($data['app_url'])),
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
            'app.asset_url' => $data['app_url'],
            'session.path' => DeploymentUrl::cookiePath($data['app_url']),
            'session.cookie' => DeploymentUrl::cookieName($data['app_url']),
            'session.secure' => str_starts_with(strtolower($data['app_url']), 'https://'),
            'proxy.trusted_proxies' => $trustedProxies,
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

        $lockPath = config('installer.setup_lock_path', storage_path('installer-setup-lock'));
        if (file_put_contents($lockPath, now()->toIso8601String(), LOCK_EX) !== false) {
            @chmod($lockPath, 0600);
        }

        if (is_file($this->envPath())) {
            @chmod($this->envPath(), 0600);
        }

        @unlink($this->inProgressPath());
        @unlink($this->setupTokenPath());
        @unlink(storage_path('installer-setup-token'));
        @unlink(storage_path('installer-setup-password'));

        foreach (glob(dirname($this->envPath()).'/.env-install-*') ?: [] as $tempFile) {
            @unlink($tempFile);
        }

        DeploymentFilesystem::prepare(base_path());
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
