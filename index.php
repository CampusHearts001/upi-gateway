<?php

/**
 * Root Front Controller Forwarder for Shared Hosting & Subdirectory Environments.
 *
 * Automatically forwards web traffic to public/index.php when the web server
 * Document Root points directly to the project root directory rather than public/.
 *
 * SECURITY NOTICE (Nginx / aaPanel):
 * On Nginx, .htaccess rewrite and deny rules are NOT processed. Setting your web
 * server Running Directory / Document Root to /public is MANDATORY to prevent direct
 * public HTTP access to .env, storage/, vendor/, and application source files.
 */
if (file_exists(__DIR__.'/public/index.php')) {
    require_once __DIR__.'/public/index.php';
} else {
    http_response_code(500);
    echo 'Application public directory is missing.';
}
