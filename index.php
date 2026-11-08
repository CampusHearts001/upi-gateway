<?php

/**
 * Root Front Controller & Safe Asset Forwarder for Shared Hosting & Subdirectory Environments.
 *
 * Automatically forwards web traffic to public/index.php when the web server
 * Document Root points directly to the project root directory rather than public/.
 *
 * Also provides a safe static asset fallback for compiled Vite assets (CSS/JS)
 * in hosting environments where Nginx or Apache alias/rewrite rules for public/
 * are not yet active, ensuring the application UI never loads unstyled.
 */

// ── 1. Safe Static Asset Fallback (Strictly Jailed to public/) ────────────────
$parsedPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$publicDir = realpath(__DIR__.'/public');

if ($publicDir && $parsedPath !== '' && $parsedPath !== '/') {
    // Resolve relative path by stripping script directory prefix if in a subdirectory
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptDir = str_ends_with(strtolower($scriptName), '.php') ? dirname($scriptName) : '';

    if ($scriptDir !== '/' && $scriptDir !== '.' && $scriptDir !== '' && str_starts_with($parsedPath, $scriptDir)) {
        $relativePath = substr($parsedPath, strlen($scriptDir));
    } else {
        $relativePath = $parsedPath;
    }

    $relativePath = preg_replace('~^/index\.php~i', '', $relativePath);
    $relativePath = '/'.ltrim((string) $relativePath, '/');

    // Only inspect requests targeting known public asset folders or root files
    if (preg_match('~^/(build/|images/|css/|favicon\.ico|robots\.txt)~i', $relativePath)) {
        $targetFile = realpath($publicDir.$relativePath);

        $allowedExtensions = [
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'mjs' => 'application/javascript; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject',
            'map' => 'application/json',
            'txt' => 'text/plain; charset=utf-8',
        ];

        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

        if (
            $targetFile !== false
            && str_starts_with($targetFile, $publicDir.DIRECTORY_SEPARATOR)
            && is_file($targetFile)
            && isset($allowedExtensions[$extension])
        ) {
            header('Content-Type: '.$allowedExtensions[$extension]);
            header('X-Content-Type-Options: nosniff');
            if (str_starts_with($relativePath, '/build/')) {
                header('Cache-Control: public, max-age=31536000, immutable');
            } else {
                header('Cache-Control: public, max-age=86400');
            }
            header('Content-Length: '.filesize($targetFile));
            readfile($targetFile);
            exit;
        }
    }
}

// ── 2. Forward to Laravel Front Controller ────────────────────────────────────
if (file_exists(__DIR__.'/public/index.php')) {
    require_once __DIR__.'/public/index.php';
} else {
    http_response_code(500);
    echo 'Application public directory is missing.';
}
