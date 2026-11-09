<?php

use App\Support\DeploymentUrl;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// ── 1. Early Safety & PHP Version Checks ─────────────────────────────────────
if (version_compare(PHP_VERSION, '8.2.0', '<')) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>PHP Version Error · UPI Gateway</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; color: #1e293b; padding: 2rem; display: flex; align-items: center; justify-content: center; min-height: 80vh; margin: 0; }
            .card { background: white; max-width: 540px; width: 100%; padding: 2rem; border-radius: 1rem; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); }
            h1 { color: #e11d48; font-size: 1.35rem; margin-top: 0; }
            p { color: #475569; font-size: 0.95rem; line-height: 1.5; }
        </style>
    </head>
    <body>
        <div class="card">
            <h1>PHP 8.2 or Higher Required</h1>
            <p>Your server is currently running PHP <strong><?= htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') ?></strong>. Please select PHP 8.2, 8.3, or 8.4 in your hosting control panel.</p>
        </div>
    </body>
    </html>
    <?php
    exit(1);
}

// ── 2. Guard Against Incomplete Deployments (Missing vendor/) ─────────────────
if (! file_exists(__DIR__.'/../vendor/autoload.php')) {
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
    exit(1);
}

require_once __DIR__.'/../app/Support/DeploymentUrl.php';
$deploymentBasePath = DeploymentUrl::basePath($_SERVER);
$deploymentRequestPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (DeploymentUrl::isPrivateRequest(substr($deploymentRequestPath, strlen($deploymentBasePath)))) {
    http_response_code(404);
    header('Cache-Control: no-store');

    return;
}
if (! is_file(__DIR__.'/../storage/installed') && $deploymentRequestPath === $deploymentBasePath.'/__upi_deployment_check') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['application' => 'upi-gateway', 'routing' => true, 'runtime' => 'installer', 'base_path' => $deploymentBasePath, 'php_fpm' => PHP_SAPI === 'fpm-fcgi']);

    return;
}

// ── 3. Uninstalled Application Redirection (Domain Root & Subdirectory) ──────
$installerEndpoint = (bool) preg_match('~/install(\.php)?/?$~i', $deploymentRequestPath);
// With an environment present, Laravel's database/admin installation guard is
// authoritative. A missing marker must not make an installed merchant lose login.
if (! is_file(__DIR__.'/../.env') || (! file_exists(__DIR__.'/../storage/installed') && $installerEndpoint)) {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';

    // If request is already targeting the installer, delegate directly
    if (preg_match('~/install(\.php)?/?$~i', $path)) {
        require __DIR__.'/install.php';

        return;
    }

    // For any other initial request on an uninstalled application, redirect to install.php
    $installUrl = $deploymentBasePath.'/install.php';
    header('Location: '.$installUrl, true, 302);

    return;
}

// ── 4. Standard Laravel Application Bootstrap ────────────────────────────────
// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
