<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// A fresh installation has no application key yet. Send the browser wizard
// directly to its standalone entry point until it writes the environment.
if (! is_file(__DIR__.'/../.env')) {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if ($path === '/') {
        header('Location: /install', true, 302);

        return;
    }
    if (preg_match('~^/install/?$~', $path)) {
        require __DIR__.'/install.php';

        return;
    }
}

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
