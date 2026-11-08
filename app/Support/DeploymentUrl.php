<?php

namespace App\Support;

use InvalidArgumentException;

/** Native PHP only: also used before Composer/Laravel boots on a fresh install. */
class DeploymentUrl
{
    public static function basePath(array $server): string
    {
        $script = str_replace('\\', '/', (string) ($server['SCRIPT_NAME'] ?? ''));
        if (preg_match('~/(?:index|install)\.php$~i', $script)) {
            $path = dirname($script);
        } else {
            $path = (string) parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
            $path = preg_replace('~/(?:install(?:\.php)?|index\.php)(?:/.*)?$~i', '', $path);
        }

        $path = preg_replace('~/public/?$~i', '', rtrim($path, '/'));

        return $path === '.' || $path === '/' ? '' : rtrim($path, '/');
    }

    public static function normalize(string $url): string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
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
}
