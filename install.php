<?php

use App\Support\DeploymentFilesystem;
use App\Support\DeploymentUrl;
use App\Support\InstallationManager;
use App\Support\NativeInstallerSession;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

/**
 * Root-level Browser Installation Wizard for cPanel and Shared Hosting.
 *
 * Allows full browser-based deployment without SSH, Composer, Node.js, or npm.
 */

// ── 1. Early Safety & PHP Version Checks ─────────────────────────────────────
if (version_compare(PHP_VERSION, '8.2.0', '<')) {
    error_log('[UPI Gateway installer] Server readiness check failed: unsupported PHP version '.PHP_VERSION);
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Installation readiness</title></head><body style="font-family:sans-serif;padding:2rem;background:#f8fafc;color:#0f172a;text-align:center;">';
    echo '<main style="max-width:30rem;margin:10vh auto;background:white;padding:2rem;border:1px solid #e2e8f0;border-radius:1rem;"><h1>Setup not ready</h1>';
    echo '<p style="color:#64748b;line-height:1.5;">Your server is not ready for installation. Please contact your hosting administrator.</p></main>';
    echo '</body></html>';

    return;
}

http_response_code(200);

$baseDir = __DIR__;
$installedFile = $baseDir.'/storage/installed';

// ── Safe Runtime Storage Self-Healing ─────────────────────────────────────────
$runtimeDirectories = [
    $baseDir.'/storage/app/public',
    $baseDir.'/storage/app/private',
    $baseDir.'/storage/framework/cache/data',
    $baseDir.'/storage/framework/sessions',
    $baseDir.'/storage/framework/views',
    $baseDir.'/storage/logs',
    $baseDir.'/bootstrap/cache',
];
foreach ($runtimeDirectories as $runtimeDir) {
    if (! is_dir($runtimeDir)) {
        @mkdir($runtimeDir, 0755, true);
    }
}
require_once $baseDir.'/app/Support/DeploymentUrl.php';
require_once $baseDir.'/app/Support/DeploymentFilesystem.php';
require_once $baseDir.'/app/Support/NativeInstallerSession.php';
DeploymentFilesystem::prepare($baseDir);
$installerBasePath = DeploymentUrl::basePath($_SERVER);
$installerUrl = $installerBasePath.'/install.php';

if (! function_exists('installer_redirect')) {
    function installer_redirect(string $url, int $status = 302): void
    {
        $GLOBALS['__installer_location'] = $url;
        http_response_code($status);
        header('Location: '.$url, true, $status);
    }
}

if (! function_exists('installer_ip_in_range')) {
    function installer_ip_in_range(string $ip, string $range): bool
    {
        if (str_contains($range, '/')) {
            [$subnet, $bits] = explode('/', $range, 2);
        } else {
            $subnet = $range;
            $bits = str_contains($range, ':') ? 128 : 32;
        }

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int) $bits;
        $maxBits = strlen($ipBin) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $bytes = (int) ($bits / 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }

        if ($remainder > 0) {
            $mask = ~(0xFF >> $remainder) & 0xFF;
            if ((ord($ipBin[$bytes]) & $mask) !== (ord($subnetBin[$bytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }
}

if (! function_exists('installer_is_https')) {
    function installer_is_https(array $server = []): bool
    {
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

        // Standard Cloudflare edge proxy CIDR blocks (IPv4 & IPv6)
        $cloudflareCidrs = [
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
            '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
            '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
            '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
        ];

        // Loopback addresses (local Nginx/LiteSpeed reverse proxies on cPanel)
        $localProxyCidrs = ['127.0.0.1/8', '::1/128'];

        $isCloudflareProxy = false;
        foreach ($cloudflareCidrs as $cidr) {
            if (installer_ip_in_range($remoteAddr, $cidr)) {
                $isCloudflareProxy = true;
                break;
            }
        }

        $isLocalProxy = false;
        foreach ($localProxyCidrs as $cidr) {
            if (installer_ip_in_range($remoteAddr, $cidr)) {
                $isLocalProxy = true;
                break;
            }
        }

        $isConfiguredProxy = false;
        $trustedProxiesEnv = getenv('TRUSTED_PROXIES') ?: ($_ENV['TRUSTED_PROXIES'] ?? '');
        if (is_string($trustedProxiesEnv) && trim($trustedProxiesEnv) !== '') {
            foreach (array_map('trim', explode(',', $trustedProxiesEnv)) as $configured) {
                if ($configured !== '' && installer_ip_in_range($remoteAddr, $configured)) {
                    $isConfiguredProxy = true;
                    break;
                }
            }
        }

        $isTrustedProxy = $isCloudflareProxy || $isLocalProxy || $isConfiguredProxy;

        if (! $isTrustedProxy) {
            // Forwarded headers from untrusted direct clients MUST NOT be trusted
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
}

// ── 2. Session & Security Configuration ──────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = installer_is_https($_SERVER);

    if (! NativeInstallerSession::start($baseDir, $installerBasePath, $isHttps)) {
        error_log('[UPI Gateway installer] Native session could not be started with the configured or private application session storage.');
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Setup unavailable</title></head><body><h1>Setup unavailable</h1><p>The installer could not start a secure session. Please verify the hosting account can write to the private storage/framework/sessions directory.</p></body></html>';

        return;
    }
}

if (empty($_SESSION['_installer_csrf'])) {
    $_SESSION['_installer_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['_installer_csrf'];

// ── 3. Installation Marker & Re-entry Protection ─────────────────────────────
// If the marker file exists, or if Laravel is already installed with an admin user,
// immediately lock the installer to prevent re-installation or credential overwrites.
$alreadyInstalled = file_exists($installedFile);

if (! $alreadyInstalled && file_exists($baseDir.'/vendor/autoload.php')) {
    try {
        require_once $baseDir.'/vendor/autoload.php';
        $app = function_exists('app') && app()->bound(Kernel::class)
            ? app()
            : (require $baseDir.'/bootstrap/app.php');
        $kernel = $app->make(Kernel::class);
        $kernel->bootstrap();
        $installer = app(InstallationManager::class);
        if ($installer->isInstalled()) {
            $alreadyInstalled = true;
        }
    } catch (Throwable) {
        // An existing environment may belong to an installed app with an offline database.
        $alreadyInstalled = is_file($baseDir.'/.env') && ! (isset($installer) && $installer->hasIncompleteSetup());
    }
}

$showCompletion = $alreadyInstalled
    && ($_GET['step'] ?? '') === 'done'
    && ! empty($_SESSION['_installer_completed']);

if ($alreadyInstalled && ! $showCompletion) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Installer Locked · UPI Gateway</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; color: #0f172a; margin: 0; padding: 2rem; display: flex; align-items: center; justify-content: center; min-height: 80vh; }
            .card { background: #ffffff; max-width: 480px; width: 100%; padding: 2rem; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); border: 1px solid #e2e8f0; text-align: center; }
            .badge { display: inline-block; padding: 0.35rem 0.85rem; border-radius: 9999px; background: #fee2e2; color: #991b1b; font-size: 0.8rem; font-weight: 700; margin-bottom: 1rem; }
            h1 { font-size: 1.35rem; margin-bottom: 0.75rem; color: #0f172a; }
            p { color: #64748b; font-size: 0.95rem; line-height: 1.5; margin-bottom: 1.5rem; }
            .btn { display: inline-block; padding: 0.65rem 1.5rem; background: #4f46e5; color: white; text-decoration: none; border-radius: 0.5rem; font-weight: 600; font-size: 0.95rem; }
            .btn:hover { background: #4338ca; }
        </style>
    </head>
    <body>
        <div class="card">
            <span class="badge">INSTALLATION LOCKED</span>
            <h1>Application Already Installed</h1>
            <p>The installation wizard has been permanently locked for security to protect existing accounts and transaction data.</p>
            <a href="<?= htmlspecialchars($installerBasePath.'/login', ENT_QUOTES, 'UTF-8') ?>" class="btn">Go to Merchant Dashboard</a>
        </div>
    </body>
    </html>
    <?php
    return;
}

// ── 4. Wizard Helpers ────────────────────────────────────────────────────────
if (! file_exists($baseDir.'/vendor/autoload.php')) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Setup Error · UPI Gateway</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; color: #0f172a; margin: 0; padding: 2rem; display: flex; align-items: center; justify-content: center; min-height: 85vh; }
            .card { background: #ffffff; max-width: 600px; width: 100%; padding: 2.25rem; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); border: 1px solid #e2e8f0; }
            .badge { display: inline-block; padding: 0.35rem 0.85rem; border-radius: 9999px; background: #fee2e2; color: #991b1b; font-size: 0.8rem; font-weight: 700; margin-bottom: 1rem; }
            h1 { font-size: 1.35rem; margin-bottom: 0.75rem; color: #0f172a; margin-top: 0; }
            p { color: #475569; font-size: 0.95rem; line-height: 1.5; margin-bottom: 1rem; }
            .box { background: #f1f5f9; border-left: 4px solid #4f46e5; padding: 1rem; border-radius: 0.375rem; font-size: 0.9rem; margin: 1.25rem 0; color: #1e293b; }
            .box ul { margin: 0.5rem 0 0 1.25rem; padding: 0; }
            .box li { margin-bottom: 0.35rem; }
            .btn { display: inline-block; padding: 0.75rem 1.5rem; background: #4f46e5; color: white; text-decoration: none; border-radius: 0.5rem; font-weight: 600; font-size: 0.95rem; text-align: center; }
            .btn:hover { background: #4338ca; }
        </style>
    </head>
    <body>
        <div class="card">
            <span class="badge">SETUP ERROR</span>
            <h1>Missing Application Dependencies</h1>
            <p>The required <code>vendor/autoload.php</code> file was not found on this server. This application requires the pre-bundled production package to run without SSH or Composer.</p>
            <div class="box">
                <strong>Common Causes &amp; Solutions:</strong>
                <ul>
                    <li><strong>Incomplete Extraction:</strong> Ensure the ZIP package fully extracted and your hosting account has enough disk space and file inode quota.</li>
                    <li><strong>Nested Extraction:</strong> If your hosting File Manager extracted files into a subfolder (e.g., <code>upi-gateway-main/</code>), move all files directly into the website directory.</li>
                    <li><strong>Alternative Download:</strong> Both the GitHub <code>main.zip</code> and official Release ZIP include pre-bundled dependencies. If your download was truncated, re-download the verified production package below.</li>
                </ul>
            </div>
            <a href="https://github.com/mynkdev-21/upi-gateway/releases/download/latest-main/upi-gateway-production.zip" class="btn" target="_blank" rel="noopener">Download Ready-to-Install Package</a>
        </div>
    </body>
    </html>
    <?php
    return;
}

require_once $baseDir.'/vendor/autoload.php';
$app = function_exists('app') && app()->bound(Kernel::class)
    ? app()
    : (require $baseDir.'/bootstrap/app.php');
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$installer = app(InstallationManager::class);
try {
    $installer->ensureSetupState();
} catch (RuntimeException) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Setup Unavailable · UPI Gateway</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; color: #0f172a; margin: 0; padding: 2rem; display: flex; align-items: center; justify-content: center; min-height: 80vh; }
            .card { background: #ffffff; max-width: 480px; width: 100%; padding: 2rem; border-radius: 1rem; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); border: 1px solid #e2e8f0; text-align: center; }
            .badge { display: inline-block; padding: 0.35rem 0.85rem; border-radius: 9999px; background: #fee2e2; color: #991b1b; font-size: 0.8rem; font-weight: 700; margin-bottom: 1rem; }
            h1 { font-size: 1.35rem; margin-bottom: 0.75rem; color: #0f172a; }
            p { color: #64748b; font-size: 0.95rem; line-height: 1.5; margin-bottom: 1.5rem; }
        </style>
    </head>
    <body>
        <div class="card">
            <span class="badge">SETUP UNAVAILABLE</span>
            <h1>Installation Lock Unavailable</h1>
            <p>The installation lock file could not be safely initialized due to file permissions. Please verify permissions in the storage directory.</p>
        </div>
    </body>
    </html>
    <?php
    return;
}

if (empty($_SESSION['_installer_setup_token'])) {
    if ($token = $installer->getSetupToken()) {
        $_SESSION['_installer_setup_token'] = $token;
    }
}
if (empty($_SESSION['_installer_diagnostic_token'])) {
    $_SESSION['_installer_diagnostic_token'] = bin2hex(random_bytes(16));
}
$diagnosticToken = $_SESSION['_installer_diagnostic_token'];

$step = $_GET['step'] ?? (function_exists('request') ? request()->query('step', 'requirements') : 'requirements');
$error = null;
$success = null;

$method = $_SERVER['REQUEST_METHOD'] ?? (function_exists('request') ? request()->method() : 'GET');
$requestServer = function_exists('request') && request() ? (array) request()->server() : [];
$serverState = $installer->serverState($_SERVER, $requestServer, PHP_SAPI);

// Validate CSRF token on POST
if ($method === 'POST') {
    $submittedToken = $_POST['_token'] ?? (function_exists('request') ? request()->input('_token', '') : '');
    if (! hash_equals($csrfToken, (string) $submittedToken)) {
        http_response_code(403);
        $error = 'Security session expired. Please refresh the page and try again.';
        $step = $_GET['step'] ?? (function_exists('request') ? request()->query('step', 'requirements') : 'requirements');
    }
}

// ── 5. Action Handlers ───────────────────────────────────────────────────────
if ($method === 'POST' && $step === 'deployment-check') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    if ($alreadyInstalled) {
        http_response_code(403);
        echo json_encode(['error' => 'Application is already installed.']);

        return;
    }
    if ($error) {
        http_response_code(403);
        echo json_encode(['error' => $error, 'session' => false, 'csrf' => false, 'ready' => false]);

        return;
    }
    if (! $error && (string) ($_POST['diagnostic_report'] ?? '') === '1') {
        $submittedToken = (string) ($_POST['diagnostic_token'] ?? '');
        $sessionToken = (string) ($_SESSION['_installer_diagnostic_token'] ?? '');
        if ($sessionToken === '' || ! hash_equals($sessionToken, $submittedToken)) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid diagnostic token.']);

            return;
        }
        $_SESSION['_installer_diagnostic_token'] = bin2hex(random_bytes(16));

        $stage = preg_match('/^[a-z_]{1,40}$/', (string) ($_POST['stage'] ?? '')) ? (string) $_POST['stage'] : 'unknown';
        $rawMessage = (string) ($_POST['message'] ?? 'Unknown readiness failure');
        $sanitizedMessage = preg_replace('/(?:\x1b\[[0-9;]*[a-zA-Z]|[\x00-\x1f\x7f])/', ' ', $rawMessage);
        $sanitizedMessage = trim(preg_replace('/\s+/', ' ', (string) $sanitizedMessage));
        error_log('[UPI Gateway installer] Browser readiness check failed: '.json_encode([
            'stage' => $stage,
            'message' => mb_substr($sanitizedMessage, 0, 500),
            'url_prefix' => $installerBasePath,
        ], JSON_UNESCAPED_SLASHES));
        echo json_encode(['logged' => true]);

        return;
    }
    $readiness = $installer->verifyServerReadiness($serverState);
    $overview = $installer->getRequirementsOverview([], $serverState);
    if (! $error && $readiness['ready'] && $overview['allPassed']) {
        $_SESSION['_installer_readiness_passed'] = true;
    } else {
        unset($_SESSION['_installer_readiness_passed']);
    }
    echo json_encode([
        'session' => ! $error,
        'csrf' => ! $error,
        'ready' => $readiness['ready'] && $overview['allPassed'],
        'php_handler' => $installer->detectPhpHandler(),
        'cookie_path' => ini_get('session.cookie_path'),
        'requirements' => $overview['requirements'],
        'summary' => $overview['summary'],
        'groups' => $overview['groups'],
        'allPassed' => $overview['allPassed'],
    ]);

    return;
}

if ($step === 'requirements') {
    $readiness = $installer->verifyServerReadiness($serverState);
    if ($readiness['ready'] && $installer->allRequirementsPassed([], $serverState)) {
        $_SESSION['_installer_readiness_passed'] = true;
    } else {
        unset($_SESSION['_installer_readiness_passed']);
    }
}

if (! $error && $step === 'database') {
    $readiness = $installer->verifyServerReadiness($serverState);
    if (! $readiness['ready'] || ! $installer->allRequirementsPassed([], $serverState)) {
        if ($method === 'POST') {
            http_response_code(403);
            $error = $readiness['ready'] ? 'Resolve all failed requirements before proceeding.' : $readiness['message'];
            $step = 'requirements';
        } else {
            installer_redirect($installerUrl.'?step=requirements');

            return;
        }
    } else {
        $_SESSION['_installer_readiness_passed'] = true;
    }
}

if (in_array($step, ['migrate', 'admin'], true) && empty($_SESSION['_installer_setup_authorized'])) {
    if ($method === 'POST') {
        http_response_code(403);
        $error = 'Database setup must be completed before proceeding.';
    } else {
        installer_redirect($installerUrl.'?step=database');

        return;
    }
}

if ($step === 'admin' && ! $installer->databaseIsMigrated()) {
    if ($method === 'POST') {
        http_response_code(409);
        $error = 'Database setup must be completed before creating the administrator.';
    } else {
        installer_redirect($installerUrl.'?step=migrate');

        return;
    }
}

if ($method === 'POST' && ! $error) {
    if (! $error && $step === 'database') {
        if (empty($_SESSION['_installer_setup_authorized'])) {
            $setupToken = $_POST['setup_token'] ?? ($_SESSION['_installer_setup_token'] ?? null);
            if (! is_string($setupToken) || ! $installer->validSetupToken($setupToken)) {
                http_response_code(403);
                $error = 'Installer setup authorization token is invalid, expired, or already used.';
            }
        }

        $dbHost = trim($_POST['db_host'] ?? '');
        $dbPort = (int) ($_POST['db_port'] ?? 3306);
        $dbDatabase = trim($_POST['db_database'] ?? '');
        $dbUsername = trim($_POST['db_username'] ?? '');
        $dbPassword = (string) ($_POST['db_password'] ?? '');
        $appName = trim($_POST['app_name'] ?? 'UPI Gateway');
        $appUrl = rtrim(trim($_POST['app_url'] ?? ''), '/');

        // Validation
        if (! $error) {
            $error = $installer->validateDatabaseConfig($_POST);
        }

        if (! $error) {
            // Test connection with PDO directly
            try {
                $installer->testDatabaseConnection([
                    'db_connection' => 'mysql',
                    'db_host' => $dbHost,
                    'db_port' => $dbPort,
                    'db_database' => $dbDatabase,
                    'db_username' => $dbUsername,
                    'db_password' => $dbPassword,
                ]);

                if (empty($_SESSION['_installer_setup_authorized'])) {
                    if (! is_string($setupToken) || ! $installer->consumeSetupToken($setupToken)) {
                        http_response_code(403);
                        $error = 'Installer setup authorization token could not be verified.';
                    }
                }

                if (! $error) {
                    // Write environment atomically
                    $installer->writeEnvironment([
                        'db_connection' => 'mysql',
                        'db_host' => $dbHost,
                        'db_port' => $dbPort,
                        'db_database' => $dbDatabase,
                        'db_username' => $dbUsername,
                        'db_password' => $dbPassword,
                        'app_name' => $appName,
                        'app_url' => $appUrl,
                    ]);

                    Artisan::call('config:clear');
                    session_regenerate_id(true);
                    unset($_SESSION['_installer_setup_token']);
                    $_SESSION['_installer_db_configured'] = true;
                    $_SESSION['_installer_setup_authorized'] = true;
                    installer_redirect($installerUrl.'?step=migrate');

                    return;
                }
            } catch (Throwable) {
                $error = 'Could not connect to the database with the provided credentials. Check database name, username, password and host in cPanel.';
            }
        }
    } elseif ($step === 'migrate') {
        try {
            $exitCode = Artisan::call('migrate', ['--force' => true]);
            if ($exitCode === 0 && $installer->databaseIsMigrated()) {
                $_SESSION['_installer_migrated'] = true;
                installer_redirect($installerUrl.'?step=admin');

                return;
            } else {
                $error = 'Database migration did not complete successfully. Check server logs.';
            }
        } catch (Throwable) {
            $error = 'Migration error occurred. Ensure your database user has ALL PRIVILEGES in cPanel.';
        }
    } elseif ($step === 'admin') {
        $adminName = trim($_POST['name'] ?? '');
        $adminEmail = trim($_POST['email'] ?? '');
        $adminPassword = (string) ($_POST['password'] ?? '');
        $adminPasswordConfirm = (string) ($_POST['password_confirmation'] ?? '');

        if ($adminName === '') {
            $error = 'Please enter administrator name.';
        } elseif (! filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please provide a valid administrator email address.';
        } elseif (strlen($adminPassword) < 10) {
            $error = 'Password must be at least 10 characters long.';
        } elseif ($adminPassword !== $adminPasswordConfirm) {
            $error = 'Password confirmation does not match.';
        }

        if (! $error) {
            try {
                $installer->createAdminUser($adminName, $adminEmail, $adminPassword);
                $installer->markInstalled();
                $_SESSION = [];
                session_regenerate_id(true);
                $_SESSION['_installer_completed'] = true;
                installer_redirect($installerUrl.'?step=done');

                return;
            } catch (Throwable $e) {
                if (str_contains($e->getMessage(), 'already exists')) {
                    $error = 'An administrator account already exists.';
                } else {
                    $error = 'Could not create administrator account. Please try again.';
                }
            }
        }
    }
}

// ── 6. Step Views ────────────────────────────────────────────────────────────
$overview = $installer->getRequirementsOverview([], $serverState);
$requirements = $overview['requirements'];
$summary = $overview['summary'];
$groups = $overview['groups'];
$allPassed = $overview['allPassed'];
$diagnostics = $installer->detectEnvironmentDiagnostics(null, $serverState);
$currentUrl = $diagnostics['detected_app_url'];
if (! $allPassed) {
    error_log('[UPI Gateway installer] Server readiness checks failed: '.json_encode([
        'failed_checks' => array_values(array_map(
            static fn (array $check): string => $check['name'],
            array_filter($requirements, static fn (array $check): bool => ($check['required'] ?? true) && ! $check['pass'])
        )),
        'php_sapi' => PHP_SAPI,
        'server_type' => $diagnostics['server_type'] ?? 'unknown',
    ], JSON_UNESCAPED_SLASHES));
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Install · UPI Gateway</title>
    <style>
        * { box-sizing: border-box; }
        :root { color-scheme: light; }
        body { font-family: Inter, ui-sans-serif, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background: #f3f5f9; color: #0f172a; margin: 0; padding: 2rem; min-height: 100vh; display: grid; place-items: center; -webkit-font-smoothing: antialiased; }
        body::before { content: ""; position: fixed; inset: 0 0 auto; height: 280px; z-index: -1; background: #0f172a; }
        .wrapper { width: 100%; max-width: 1040px; }
        .installer-shell { display: grid; grid-template-columns: 292px minmax(0, 1fr); min-height: 650px; overflow: hidden; border: 1px solid rgba(255,255,255,.12); border-radius: 24px; background: #fff; box-shadow: 0 32px 80px rgba(15,23,42,.22); }
        .setup-sidebar { display: flex; flex-direction: column; padding: 2rem 1.75rem; color: #cbd5e1; background: #111827; }
        .logo { display: flex; align-items: center; gap: 0.8rem; padding-bottom: 2rem; border-bottom: 1px solid rgba(255,255,255,.1); }
        .logo-icon { width: 44px; height: 44px; flex: 0 0 auto; background: #6366f1; border-radius: 13px; display: grid; place-items: center; color: white; font-size: 1.15rem; font-weight: 800; box-shadow: 0 10px 24px rgba(99,102,241,.3); }
        .logo-title { font-size: 1rem; font-weight: 750; letter-spacing: -.02em; color: #fff; }
        .logo-subtitle { margin-top: .2rem; color: #94a3b8; font-size: .72rem; font-weight: 500; }
        .steps { display: flex; flex: 1; flex-direction: column; gap: 0; margin: 2rem 0; }
        .step-item { position: relative; display: grid; grid-template-columns: 36px 1fr; align-items: center; gap: .8rem; min-height: 68px; }
        .step-item:not(:last-child)::after { content: ""; position: absolute; top: 50px; bottom: -18px; left: 17px; width: 2px; background: #334155; }
        .step-item:has(.step-bubble.passed):not(:last-child)::after { background: #10b981; }
        .step-bubble { position: relative; z-index: 1; width: 36px; height: 36px; border: 1px solid #475569; border-radius: 11px; display: grid; place-items: center; font-size: 0.78rem; font-weight: 700; background: #1e293b; color: #94a3b8; transition: background .18s ease, border-color .18s ease, box-shadow .18s ease; }
        .step-bubble.active { border-color: #818cf8; background: #6366f1; color: white; box-shadow: 0 0 0 5px rgba(99,102,241,.16); }
        .step-bubble.passed { border-color: #10b981; background: #10b981; color: white; }
        .step-bubble.passed::before { content: "✓"; font-size: .9rem; }
        .step-bubble.passed { font-size: 0; }
        .step-label { font-size: 0.84rem; font-weight: 600; color: #94a3b8; }
        .step-item:has(.step-bubble.active) .step-label { color: #fff; }
        .step-item:has(.step-bubble.passed) .step-label { color: #d1fae5; }
        .sidebar-note { margin-top: auto; padding: 1rem; border: 1px solid #334155; border-radius: 14px; background: rgba(30,41,59,.72); color: #94a3b8; font-size: .75rem; line-height: 1.55; }
        .sidebar-note strong { display: block; margin-bottom: .25rem; color: #e2e8f0; font-size: .76rem; }
        .setup-main { display: flex; min-width: 0; flex-direction: column; padding: 2.5rem 3rem; background: #fff; }
        .content-header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 2rem; padding-bottom: 1.25rem; border-bottom: 1px solid #eef2f7; }
        .content-eyebrow { color: #6366f1; font-size: .7rem; font-weight: 750; letter-spacing: .12em; text-transform: uppercase; }
        .content-security { display: inline-flex; align-items: center; gap: .4rem; color: #64748b; font-size: .72rem; font-weight: 600; }
        .content-security::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: #10b981; box-shadow: 0 0 0 3px #d1fae5; }
        .card { width: 100%; max-width: 590px; margin: auto; padding: 0; }
        h2 { font-size: 1.75rem; line-height: 1.2; font-weight: 750; letter-spacing: -.035em; margin: 0 0 0.65rem; color: #0f172a; }
        p.subtitle { color: #64748b; font-size: 0.92rem; margin: 0 0 1.75rem; line-height: 1.65; }
        .form-group { margin-bottom: 1.15rem; }
        label { display: block; font-size: 0.8rem; font-weight: 650; color: #334155; margin-bottom: 0.45rem; }
        input[type="text"], input[type="email"], input[type="password"], input[type="url"], input[type="number"] { width: 100%; min-height: 46px; padding: 0.72rem 0.85rem; border: 1px solid #d7dee8; border-radius: 10px; background: #fff; color: #0f172a; font-size: 0.92rem; font-family: inherit; box-shadow: 0 1px 2px rgba(15,23,42,.03); transition: border-color .15s ease, box-shadow .15s ease; }
        input:hover { border-color: #a8b3c3; }
        input:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 4px rgba(99,102,241,.11); }
        input::placeholder { color: #94a3b8; }
        .help-text { font-size: 0.74rem; color: #64748b; margin-top: 0.4rem; line-height: 1.5; }
        .alert-error { max-width: 590px; width: 100%; margin: 0 auto 1.25rem; padding: 0.85rem 1rem; border: 1px solid #fecaca; border-left: 4px solid #ef4444; border-radius: 10px; background: #fef2f2; color: #991b1b; font-size: 0.84rem; line-height: 1.5; }
        .btn { display: block; width: 100%; min-height: 46px; text-align: center; padding: 0.78rem 1.25rem; background: #4f46e5; color: white; border: 1px solid #4f46e5; border-radius: 10px; box-shadow: 0 6px 16px rgba(79,70,229,.18); font-size: 0.9rem; font-weight: 650; font-family: inherit; cursor: pointer; text-decoration: none; transition: transform .15s ease, background .15s ease, box-shadow .15s ease; }
        .btn:hover { transform: translateY(-1px); background: #4338ca; box-shadow: 0 9px 22px rgba(79,70,229,.23); }
        .btn:focus-visible { outline: 3px solid rgba(99,102,241,.22); outline-offset: 2px; }
        .btn:disabled { transform: none; border-color: #94a3b8; background: #94a3b8; box-shadow: none; cursor: not-allowed; }
        @media (max-width: 760px) {
            body { display: block; padding: 0; background: #fff; }
            body::before { display: none; }
            .wrapper { max-width: none; }
            .installer-shell { display: block; min-height: 100vh; border: 0; border-radius: 0; box-shadow: none; }
            .setup-sidebar { padding: 1.25rem 1rem 1rem; }
            .logo { justify-content: center; padding-bottom: 1.15rem; }
            .logo-icon { width: 38px; height: 38px; border-radius: 11px; }
            .steps { flex-direction: row; align-items: flex-start; justify-content: space-between; margin: .9rem auto 0; max-width: 500px; }
            .step-item { display: flex; min-height: auto; flex-direction: column; gap: .4rem; }
            .step-item:not(:last-child)::after { top: 17px; bottom: auto; left: calc(50% + 18px); width: calc(100vw / 5 - 36px); height: 2px; }
            .step-bubble { width: 34px; height: 34px; border-radius: 10px; }
            .step-label { font-size: .65rem; }
            .sidebar-note { display: none; }
            .setup-main { padding: 1.5rem 1.15rem 2rem; }
            .content-header { margin-bottom: 1.5rem; padding-bottom: 1rem; }
            .content-security { display: none; }
            .card { max-width: 560px; }
            h2 { font-size: 1.5rem; }
        }
        @media (max-width: 410px) {
            .step-label { display: none; }
            .steps { padding-inline: .25rem; }
            .step-item:not(:last-child)::after { width: calc(100vw / 5 - 34px); }
        }
        @media (prefers-reduced-motion: reduce) { .btn, input, .step-bubble { transition: none; } }
    </style>
</head>
<body>
<div class="wrapper">
  <div class="installer-shell">
   <aside class="setup-sidebar">
    <div class="logo">
        <div class="logo-icon">&#x26A1;</div>
        <div><div class="logo-title">UPI Gateway</div><div class="logo-subtitle">Installation wizard</div></div>
    </div>

    <div class="steps" aria-label="Installation progress">
        <div class="step-item">
            <div class="step-bubble <?= $step === 'requirements' ? 'active' : 'passed' ?>">1</div>
            <span class="step-label">Ready</span>
        </div>
        <div class="step-item">
            <div class="step-bubble <?= $step === 'database' ? 'active' : ($step === 'requirements' ? '' : 'passed') ?>">2</div>
            <span class="step-label">Database</span>
        </div>
        <div class="step-item">
            <div class="step-bubble <?= $step === 'migrate' ? 'active' : (in_array($step, ['admin', 'done']) ? 'passed' : '') ?>">3</div>
            <span class="step-label">Set up</span>
        </div>
        <div class="step-item">
            <div class="step-bubble <?= $step === 'admin' ? 'active' : ($step === 'done' ? 'passed' : '') ?>">4</div>
            <span class="step-label">Account</span>
        </div>
        <div class="step-item">
            <div class="step-bubble <?= $step === 'done' ? 'passed' : '' ?>">5</div>
            <span class="step-label">Complete</span>
        </div>
    </div>

    <div class="sidebar-note"><strong>Secure local setup</strong>Your credentials stay on this server throughout installation.</div>
   </aside>

   <main class="setup-main">
    <div class="content-header">
        <span class="content-eyebrow">Application setup</span>
        <span class="content-security">Secure installer</span>
    </div>

    <?php if ($error) { ?>
        <div class="alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php } ?>

    <div class="card">
        <?php if ($step === 'requirements') { ?>
            <h2 style="text-align:center;">Installation readiness</h2>
            <p class="subtitle" style="text-align:center;">Review system requirements and hosting readiness below.</p>
            <?= view('install.hosting-check', ['deploymentUrl' => $currentUrl, 'deploymentCsrfToken' => $csrfToken, 'deploymentDiagnosticToken' => $diagnosticToken, 'serverReady' => $allPassed, 'nativeInstaller' => true])->render() ?>
            <?= view('install.requirements-overview', [
                'overview' => $overview,
                'summary' => $summary,
                'groups' => $groups,
                'requirements' => $requirements,
                'allPassed' => $allPassed,
                'deploymentUrl' => $currentUrl,
                'installerUrl' => $installerUrl,
                'nextUrl' => $installerUrl.'?step=database',
                'recheckUrl' => $installerUrl.'?step=deployment-check',
                'csrfToken' => $csrfToken,
                'nativeInstaller' => true,
            ])->render() ?>

        <?php } elseif (! $error && $step === 'database') { ?>
            <h2>Database Configuration</h2>
            <p class="subtitle">Enter your MySQL database credentials created in cPanel MySQL Databases.</p>
            <form method="POST" action="<?= htmlspecialchars($installerUrl, ENT_QUOTES, 'UTF-8') ?>?step=database">
                <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="setup_token" value="<?= htmlspecialchars($_SESSION['_installer_setup_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <div class="form-group">
                    <label>Application Name</label>
                    <input type="text" name="app_name" value="<?= htmlspecialchars($_POST['app_name'] ?? 'UPI Gateway', ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="form-group">
                    <label>Application URL</label>
                    <input type="url" name="app_url" value="<?= htmlspecialchars($_POST['app_url'] ?? $currentUrl, ENT_QUOTES, 'UTF-8') ?>" required>
                    <div class="help-text">Your domain root with HTTPS (e.g. https://payments.yourbusiness.com).</div>
                </div>
                <div class="form-group">
                    <label>MySQL Host</label>
                    <input type="text" name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? '127.0.0.1', ENT_QUOTES, 'UTF-8') ?>" required>
                    <div class="help-text">Usually 127.0.0.1 or localhost on cPanel.</div>
                </div>
                <div class="form-group">
                    <label>MySQL Port</label>
                    <input type="number" name="db_port" value="<?= htmlspecialchars($_POST['db_port'] ?? '3306', ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="form-group">
                    <label>Database Name</label>
                    <input type="text" name="db_database" value="<?= htmlspecialchars($_POST['db_database'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="cpaneluser_gateway" required>
                    <div class="help-text">Include cPanel prefix if applicable.</div>
                </div>
                <div class="form-group">
                    <label>Database Username</label>
                    <input type="text" name="db_username" value="<?= htmlspecialchars($_POST['db_username'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="cpaneluser_dbuser" required>
                </div>
                <div class="form-group">
                    <label>Database Password</label>
                    <input type="password" name="db_password" value="" autocomplete="new-password">
                </div>
                <button type="submit" class="btn">Test Connection &amp; Save Configuration</button>
            </form>

        <?php } elseif ($step === 'migrate') { ?>
            <h2>Database Migrations</h2>
            <p class="subtitle">The database configuration was saved. Click below to create all required database tables safely in-process.</p>
            <form method="POST" action="<?= htmlspecialchars($installerUrl, ENT_QUOTES, 'UTF-8') ?>?step=migrate">
                <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="btn">Run Database Migrations Now</button>
            </form>

        <?php } elseif ($step === 'admin') { ?>
            <h2>Create Primary Administrator</h2>
            <?= view('install.hosting-check', ['deploymentUrl' => $currentUrl, 'deploymentCsrfToken' => $csrfToken, 'deploymentDiagnosticToken' => $diagnosticToken, 'serverReady' => $allPassed, 'nativeInstaller' => true])->render() ?>
            <p class="subtitle">Set up the master administrator account for dashboard access and API configuration.</p>
            <form method="POST" action="<?= htmlspecialchars($installerUrl, ENT_QUOTES, 'UTF-8') ?>?step=admin">
                <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="form-group">
                    <label>Administrator Name</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Owner / Manager" required>
                </div>
                <div class="form-group">
                    <label>Administrator Email</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="admin@yourbusiness.com" required>
                </div>
                <div class="form-group">
                    <label>Password (min 10 characters)</label>
                    <input type="password" name="password" minlength="10" required>
                </div>
                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" name="password_confirmation" minlength="10" required>
                </div>
                <button hidden data-deployment-submit type="submit" class="btn" disabled>Finalize Installation</button>
            </form>

        <?php } elseif ($step === 'done') { ?>
            <h2 style="text-align:center;">Installation complete</h2>
            <?= view('install.hosting-check', ['deploymentUrl' => $currentUrl, 'deploymentCsrfToken' => $csrfToken, 'deploymentDiagnosticToken' => $diagnosticToken, 'finalCheck' => true, 'serverReady' => $allPassed, 'nativeInstaller' => true])->render() ?>
            <p class="subtitle" style="text-align:center;">Your account and database are ready.</p>
            <a hidden data-deployment-continue aria-disabled="true" data-deployment-href="<?= htmlspecialchars($installerBasePath.'/login', ENT_QUOTES, 'UTF-8') ?>" class="btn" style="margin-top: 1.5rem;">Go to Login &rarr;</a>
        <?php } ?>
    </div>
   </main>
  </div>
</div>
</body>
</html>
