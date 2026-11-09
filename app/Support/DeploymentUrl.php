<?php

namespace App\Support;

use InvalidArgumentException;

/** Native PHP only: also used before Composer/Laravel boots on a fresh install. */
class DeploymentUrl
{
    public static function basePath(array $server): string
    {
        $requestPath = (string) parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $installerPath = null;
        if (preg_match('~/(?:install(?:\\.php)?(?:/(?:database|migrate|admin|done))?|index\\.php)/?$~i', $requestPath)) {
            $installerPath = preg_replace('~/(?:install(?:\\.php)?(?:/(?:database|migrate|admin|done))?|index\\.php)/?$~i', '', $requestPath);
        }
        $mount = null;
        foreach (['SCRIPT_NAME', 'ORIG_SCRIPT_NAME'] as $key) {
            $script = str_replace('\\', '/', (string) ($server[$key] ?? ''));
            if (preg_match('~/(?:index|install)\\.php(?:/.*)?$~i', $script)) {
                $mount = preg_replace('~/(?:index|install)\\.php(?:/.*)?$~i', '', $script);
                break;
            }
        }
        $clean = static function (string $path): string {
            $path = preg_replace('~/public/?$~i', '', rtrim($path, '/'));

            return $path === '.' || $path === '/' ? '' : rtrim($path, '/');
        };
        $mount = $mount !== null ? $clean($mount) : null;
        // A panel may report /index.php despite a prefixed installer URL. Use
        // the explicit installer path as corroboration, never a forwarded prefix.
        if ($installerPath !== null && ($mount === null || $mount === '' || ! str_starts_with($requestPath, $mount.'/'))) {
            return $clean($installerPath);
        }

        return $mount ?? $clean($requestPath);
    }

    public static function normalize(string $url): string
    {
        $parts = parse_url(trim($url));
        if (filter_var(trim($url), FILTER_VALIDATE_URL) === false || ! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\\\\]/', $url)) {
            throw new InvalidArgumentException('Use an HTTP or HTTPS application URL without credentials, query strings or fragments.');
        }
        $path = rtrim($parts['path'] ?? '', '/');
        $path = preg_replace('~/(?:public|index\.php|install\.php)$~i', '', $path);
        if (preg_match('~(?:^|/)(?:\.|\.\.)(?:/|$)~', rawurldecode($path))) {
            throw new InvalidArgumentException('The application URL contains an invalid path.');
        }

        return strtolower($parts['scheme']).'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').$path;
    }

    public static function cookiePath(string $url): string
    {
        return rtrim((string) parse_url(self::normalize($url), PHP_URL_PATH), '/').'/';
    }

    public static function cookieName(string $url): string
    {
        return 'upi_gateway_'.substr(hash('sha256', self::normalize($url)), 0, 16).'_session';
    }

    public static function isPrivateRequest(string $relativePath): bool
    {
        return (bool) preg_match('~^/(?:\.|deployment-private-check\.txt(?:/|$)|(?:app|bootstrap|config|database|resources|routes|vendor|tests|scripts)(?:/|$)|storage/(?:app|framework|logs)(?:/|$)|docs/.+)~i', rawurldecode($relativePath));
    }
}
