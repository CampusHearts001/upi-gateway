<?php

use App\Support\DeploymentFilesystem;
use App\Support\DeploymentUrl;
use App\Support\InstallationManager;
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
DeploymentFilesystem::prepare($baseDir);
$installerBasePath = DeploymentUrl::basePath($_SERVER);
$installerUrl = $installerBasePath.'/install.php';

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

    session_name('upi_setup_'.substr(hash('sha256', $baseDir.$installerBasePath), 0, 16));
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_path', $installerBasePath.'/');
    if ($isHttps) {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
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
$installer->ensureSetupState();

$step = $_GET['step'] ?? 'requirements';
$error = null;
$success = null;

$method = $_SERVER['REQUEST_METHOD'] ?? (function_exists('request') ? request()->method() : 'GET');

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
    if (! $error && (string) ($_POST['diagnostic_report'] ?? '') === '1') {
        $stage = preg_match('/^[a-z_]{1,40}$/', (string) ($_POST['stage'] ?? '')) ? (string) $_POST['stage'] : 'unknown';
        $message = preg_replace('/[\x00-\x1f\x7f]/', ' ', (string) ($_POST['message'] ?? 'Unknown readiness failure'));
        error_log('[UPI Gateway installer] Browser readiness check failed: '.json_encode([
            'stage' => $stage,
            'message' => mb_substr($message, 0, 500),
            'url_prefix' => $installerBasePath,
        ], JSON_UNESCAPED_SLASHES));
        echo json_encode(['logged' => true]);

        return;
    }
    echo json_encode(['session' => ! $error, 'csrf' => ! $error, 'cookie_path' => ini_get('session.cookie_path')]);

    return;
}

if (in_array($step, ['migrate', 'admin'], true) && empty($_SESSION['_installer_setup_authorized'])) {
    if ($method === 'POST') {
        http_response_code(403);
        $error = 'Database setup must be completed before proceeding.';
    } else {
        header('Location: '.$installerUrl.'?step=database');

        return;
    }
}

if ($method === 'POST' && ! $error) {
    if ($step === 'database') {
        $dbHost = trim($_POST['db_host'] ?? '');
        $dbPort = (int) ($_POST['db_port'] ?? 3306);
        $dbDatabase = trim($_POST['db_database'] ?? '');
        $dbUsername = trim($_POST['db_username'] ?? '');
        $dbPassword = (string) ($_POST['db_password'] ?? '');
        $appName = trim($_POST['app_name'] ?? 'UPI Gateway');
        $appUrl = rtrim(trim($_POST['app_url'] ?? ''), '/');

        // Validation
        if ($dbHost === '' || preg_match('/[\r\n]/', $dbHost)) {
            $error = 'Invalid database host.';
        } elseif (! $installer->isAllowedDatabaseHost($dbHost)) {
            $error = 'Database host must be local (127.0.0.1 or localhost) for security during web installation.';
        } elseif ($dbPort < 1 || $dbPort > 65535) {
            $error = 'Database port must be between 1 and 65535.';
        } elseif ($dbDatabase === '' || ! preg_match('/^[A-Za-z0-9_$-]+$/', $dbDatabase)) {
            $error = 'Database name contains invalid characters.';
        } elseif ($dbUsername === '' || preg_match('/[\r\n]/', $dbUsername)) {
            $error = 'Invalid database username.';
        } elseif (preg_match('/[\r\n]/', $dbPassword)) {
            $error = 'Database password cannot contain newlines.';
        } elseif ($appName === '' || preg_match('/[\r\n]/', $appName)) {
            $error = 'Invalid application name.';
        } elseif ($appUrl === '' || ! filter_var($appUrl, FILTER_VALIDATE_URL)) {
            $error = 'Please enter a valid application URL (e.g. https://yourdomain.com).';
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
                $_SESSION['_installer_db_configured'] = true;
                $_SESSION['_installer_setup_authorized'] = true;
                header('Location: '.$installerUrl.'?step=migrate');

                return;
            } catch (Throwable) {
                $error = 'Could not connect to the database with the provided credentials. Check database name, username, password and host in cPanel.';
            }
        }
    } elseif ($step === 'migrate') {
        try {
            $exitCode = Artisan::call('migrate', ['--force' => true]);
            if ($exitCode === 0 && $installer->databaseIsMigrated()) {
                $_SESSION['_installer_migrated'] = true;
                header('Location: '.$installerUrl.'?step=admin');

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
                header('Location: '.$installerUrl.'?step=done');

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
$requirements = $installer->checkRequirements();
$allPassed = $installer->allRequirementsPassed();
$diagnostics = $installer->detectEnvironmentDiagnostics(null, $_SERVER);
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
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f8fafc; color: #0f172a; margin: 0; padding: 2rem 1rem; min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; }
        .wrapper { width: 100%; max-width: 580px; }
        .logo { display: flex; align-items: center; justify-content: center; gap: 0.75rem; margin-bottom: 2rem; }
        .logo-icon { width: 40px; height: 40px; background: #4f46e5; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; }
        .logo-title { font-size: 1.35rem; font-weight: 800; color: #0f172a; }
        .steps { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.75rem; position: relative; }
        .step-item { display: flex; flex-direction: column; align-items: center; gap: 0.35rem; z-index: 1; }
        .step-bubble { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: bold; background: #e2e8f0; color: #64748b; }
        .step-bubble.active { background: #4f46e5; color: white; }
        .step-bubble.passed { background: #10b981; color: white; }
        .step-label { font-size: 0.75rem; font-weight: 600; color: #64748b; }
        .card { background: white; border-radius: 1rem; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.05); padding: 2rem; margin-bottom: 1.5rem; }
        h2 { font-size: 1.25rem; font-weight: 700; margin-top: 0; margin-bottom: 0.5rem; color: #0f172a; }
        p.subtitle { color: #64748b; font-size: 0.9rem; margin-top: 0; margin-bottom: 1.5rem; line-height: 1.4; }
        .form-group { margin-bottom: 1.2rem; }
        label { display: block; font-size: 0.85rem; font-weight: 600; color: #334155; margin-bottom: 0.4rem; }
        input[type="text"], input[type="email"], input[type="password"], input[type="url"], input[type="number"] { width: 100%; padding: 0.65rem 0.85rem; border: 1px solid #cbd5e1; border-radius: 0.5rem; font-size: 0.95rem; font-family: inherit; }
        input:focus { outline: none; border-color: #4f46e5; ring: 2px solid #c7d2fe; }
        .help-text { font-size: 0.75rem; color: #64748b; margin-top: 0.3rem; }
        .alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 0.75rem 1rem; border-radius: 0.5rem; font-size: 0.85rem; margin-bottom: 1.25rem; }
        .btn { display: block; width: 100%; text-align: center; padding: 0.75rem 1.25rem; background: #4f46e5; color: white; border: none; border-radius: 0.5rem; font-size: 0.95rem; font-weight: 600; cursor: pointer; text-decoration: none; }
        .btn:hover { background: #4338ca; }
        .btn:disabled { background: #94a3b8; cursor: not-allowed; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="logo">
        <div class="logo-icon">&#x26A1;</div>
        <div class="logo-title">UPI Gateway Installation</div>
    </div>

    <div class="steps">
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

    <?php if ($error) { ?>
        <div class="alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php } ?>

    <div class="card">
        <?php if ($step === 'requirements') { ?>
            <h2 style="text-align:center;">Installation readiness</h2>
            <p class="subtitle" style="text-align:center;">This will only take a moment.</p>
            <?= view('install.hosting-check', ['deploymentUrl' => $currentUrl, 'deploymentCsrfToken' => $csrfToken, 'serverReady' => $allPassed, 'nativeInstaller' => true])->render() ?>
            <?php if ($allPassed) { ?>
                <a hidden data-deployment-continue aria-disabled="true" data-deployment-href="<?= htmlspecialchars($installerUrl, ENT_QUOTES, 'UTF-8') ?>?step=database" class="btn">Next &rarr;</a>
            <?php } ?>

        <?php } elseif ($step === 'database') { ?>
            <h2>Database Configuration</h2>
            <p class="subtitle">Enter your MySQL database credentials created in cPanel MySQL Databases.</p>
            <form method="POST" action="<?= htmlspecialchars($installerUrl, ENT_QUOTES, 'UTF-8') ?>?step=database">
                <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
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
            <?= view('install.hosting-check', ['deploymentUrl' => $currentUrl, 'deploymentCsrfToken' => $csrfToken, 'serverReady' => $allPassed, 'nativeInstaller' => true])->render() ?>
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
            <?= view('install.hosting-check', ['deploymentUrl' => $currentUrl, 'finalCheck' => true, 'serverReady' => $allPassed, 'nativeInstaller' => true])->render() ?>
            <p class="subtitle" style="text-align:center;">Your account and database are ready.</p>
            <a hidden data-deployment-continue aria-disabled="true" data-deployment-href="<?= htmlspecialchars($installerBasePath.'/login', ENT_QUOTES, 'UTF-8') ?>" class="btn" style="margin-top: 1.5rem;">Go to Login &rarr;</a>
        <?php } ?>
    </div>
</div>
</body>
</html>
