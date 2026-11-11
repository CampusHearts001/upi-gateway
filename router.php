<?php

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$public = __DIR__ . '/public';
$file = realpath($public . $path);

// Serve existing files inside public/ directly.
if (
    $path !== '/' &&
    $file !== false &&
    is_file($file) &&
    str_starts_with($file, realpath($public) . DIRECTORY_SEPARATOR)
) {
    return false;
}

// Keep the standalone installer accessible.
if ($path === '/install.php') {
    require __DIR__ . '/install.php';
    return true;
}

// Route application requests through Laravel.
require $public . '/index.php';