<?php

namespace App\Support;

class NativeInstallerSession
{
    private static bool $privateStorageUsedThisRequest = false;

    public static function csrfMatches(string $sessionToken, string $submittedToken): bool
    {
        return $sessionToken !== '' && hash_equals($sessionToken, $submittedToken);
    }

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

        // Always use the private application session directory. The hosting
        // PHP default may accept session_start() while pointing at a per-pool
        // or temporary path that does not reliably preserve sessions across
        // requests, which makes every installer POST fail CSRF validation.
        if (session_save_path() !== $resolvedSessionsDirectory) {
            @session_save_path($resolvedSessionsDirectory);
            if (session_save_path() !== $resolvedSessionsDirectory) {
                return false;
            }
        }
        self::$privateStorageUsedThisRequest = true;

        return @session_start();
    }

    public static function logRequestState(): void
    {
        self::logSessionDiagnostics(self::$privateStorageUsedThisRequest);
    }

    public static function logCsrfMismatch(string $sessionToken, string $submittedToken): void
    {
        if (getenv('UPI_INSTALLER_SESSION_DIAGNOSTICS') !== '1') {
            return;
        }

        $cookieParams = session_get_cookie_params();
        $requestCookies = array_values(array_filter(array_map(
            static fn (string $cookie): string => trim(explode('=', $cookie, 2)[0]),
            explode(';', (string) ($_SERVER['HTTP_COOKIE'] ?? ''))
        )));
        $sessionSetCookieValues = self::sessionSetCookieValues();

        error_log('[UPI Gateway installer] CSRF validation mismatch: '.json_encode([
            'session_token_present' => $sessionToken !== '',
            'submitted_token_present' => $submittedToken !== '',
            'session_token_length' => strlen($sessionToken),
            'submitted_token_length' => strlen($submittedToken),
            'token_matches' => self::csrfMatches($sessionToken, $submittedToken),
            'session_id_fingerprint' => self::sessionIdFingerprint(),
            'session_data_has_csrf' => isset($_SESSION['_installer_csrf']),
            'session_data_has_setup_auth' => ! empty($_SESSION['_installer_setup_authorized']),
            'session_name' => session_name(),
            'cookie_name_present' => isset($_COOKIE[session_name()]),
            'cookie_matches_active_session' => isset($_COOKIE[session_name()])
                && hash_equals(session_id(), (string) $_COOKIE[session_name()]),
            'cookie_name_occurrences' => count(array_filter($requestCookies, static fn (string $name): bool => $name === session_name())),
            'response_cookie_name_occurrences' => count($sessionSetCookieValues),
            'response_cookie_matches_active_session' => count($sessionSetCookieValues) > 0
                && count(array_filter($sessionSetCookieValues, static fn (string $value): bool => hash_equals(session_id(), $value))) === count($sessionSetCookieValues),
            'save_handler' => ini_get('session.save_handler'),
            'save_path_fingerprint' => self::pathFingerprint(session_save_path()),
            'session_status' => session_status(),
            'request_content_length' => (int) ($_SERVER['CONTENT_LENGTH'] ?? 0),
            'request_content_type' => (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
            'cookie_path' => $cookieParams['path'] ?? '',
            'cookie_domain_configured' => ($cookieParams['domain'] ?? '') !== '',
            'cookie_secure' => (bool) ($cookieParams['secure'] ?? false),
            'cookie_httponly' => (bool) ($cookieParams['httponly'] ?? false),
            'cookie_samesite' => $cookieParams['samesite'] ?? '',
        ], JSON_UNESCAPED_SLASHES));
    }

    private static function logSessionDiagnostics(bool $privateStorageUsed): void
    {
        if (getenv('UPI_INSTALLER_SESSION_DIAGNOSTICS') !== '1') {
            return;
        }

        $cookieParams = session_get_cookie_params();
        $sessionSetCookieValues = self::sessionSetCookieValues();
        error_log('[UPI Gateway installer] Session initialized: '.json_encode([
            'private_storage_selected' => $privateStorageUsed,
            'session_id_fingerprint' => self::sessionIdFingerprint(),
            'session_name' => session_name(),
            'cookie_name_present' => isset($_COOKIE[session_name()]),
            'cookie_matches_active_session' => isset($_COOKIE[session_name()])
                && hash_equals(session_id(), (string) $_COOKIE[session_name()]),
            'response_cookie_name_occurrences' => count($sessionSetCookieValues),
            'response_cookie_matches_active_session' => count($sessionSetCookieValues) > 0
                && count(array_filter($sessionSetCookieValues, static fn (string $value): bool => hash_equals(session_id(), $value))) === count($sessionSetCookieValues),
            'session_data_has_csrf' => isset($_SESSION['_installer_csrf']),
            'session_data_has_setup_auth' => ! empty($_SESSION['_installer_setup_authorized']),
            'save_handler' => ini_get('session.save_handler'),
            'save_path_fingerprint' => self::pathFingerprint(session_save_path()),
            'session_status' => session_status(),
            'cookie_path' => $cookieParams['path'] ?? '',
            'cookie_domain_configured' => ($cookieParams['domain'] ?? '') !== '',
            'cookie_secure' => (bool) ($cookieParams['secure'] ?? false),
            'cookie_httponly' => (bool) ($cookieParams['httponly'] ?? false),
            'cookie_samesite' => $cookieParams['samesite'] ?? '',
        ], JSON_UNESCAPED_SLASHES));
    }

    private static function sessionIdFingerprint(): string
    {
        $id = session_id();

        return $id === '' ? 'none' : substr(hash('sha256', $id), 0, 12);
    }

    private static function pathFingerprint(string $path): string
    {
        return substr(hash('sha256', $path), 0, 12);
    }

    /** @return list<string> Session cookie values for boolean comparison only; never logged. */
    private static function sessionSetCookieValues(): array
    {
        $values = [];
        $prefix = session_name().'=';
        foreach (headers_list() as $header) {
            if (stripos($header, 'Set-Cookie:') !== 0) {
                continue;
            }

            $cookie = trim(substr($header, strlen('Set-Cookie:')));
            if (str_starts_with($cookie, $prefix)) {
                $values[] = explode(';', substr($cookie, strlen($prefix)), 2)[0];
            }
        }

        return $values;
    }
}
