<?php

namespace App\Support;

class NativeInstallerSession
{
    public static function start(string $baseDir, string $basePath, bool $secure): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }

        $sessionBasePath = $basePath;
        $cookieBasePath = '/'.trim($basePath, '/');
        $cookiePath = $cookieBasePath === '/' ? '/' : $cookieBasePath.'/';
        session_name('upi_setup_'.substr(hash('sha256', $baseDir.$sessionBasePath), 0, 16));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $cookiePath,
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');

        if (@session_start()) {
            return true;
        }

        $root = realpath($baseDir);
        $sessionsDirectory = $baseDir.'/storage/framework/sessions';
        $resolvedSessionsDirectory = realpath($sessionsDirectory);

        // The fallback must stay inside the private application tree. Never
        // follow a symlink to a public or otherwise unexpected session path.
        if ($root === false
            || is_link($sessionsDirectory)
            || $resolvedSessionsDirectory === false
            || ! str_starts_with($resolvedSessionsDirectory, $root.DIRECTORY_SEPARATOR)
            || ! is_dir($resolvedSessionsDirectory)
            || ! is_writable($resolvedSessionsDirectory)) {
            return false;
        }

        if (! session_save_path($resolvedSessionsDirectory)) {
            return false;
        }

        return @session_start();
    }
}
