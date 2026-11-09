<?php

namespace App\Support;

use InvalidArgumentException;

class DeploymentConfiguration
{
    public static function apache(string $url, string $publicPath): string
    {
        $prefix = rtrim((string) parse_url(DeploymentUrl::normalize($url), PHP_URL_PATH), '/');
        $path = str_replace(['\\', '"', "\n", "\r"], ['/', '\\"', '', ''], rtrim($publicPath, '/'));
        $mount = $prefix === '' ? 'DocumentRoot "'.$path.'"' : 'Alias "'.$prefix.'/" "'.$path.'/"';

        return <<<APACHE
# Inside the virtual host, preserving its existing PHP handler.
# For a subdirectory, the parent DocumentRoot is unchanged.
{$mount}
<Directory "{$path}">
    Options +FollowSymLinks -Indexes
    AllowOverride All
    Require all granted
    DirectoryIndex index.php
</Directory>
APACHE;
    }

    public static function nginx(string $url, string $publicPath, string $phpHandler = 'unix:/REPLACE_WITH_PANEL_PHP_FPM_SOCKET'): string
    {
        $prefix = rtrim((string) parse_url(DeploymentUrl::normalize($url), PHP_URL_PATH), '/');
        if (! preg_match('~^(?:unix:/[A-Za-z0-9_./-]+|(?:127\.0\.0\.1|localhost):[0-9]+)$~', $phpHandler)) {
            throw new InvalidArgumentException('Use the PHP-FPM socket or local TCP address from the active hosting panel PHP handler.');
        }
        $publicPath = rtrim(str_replace('\\', '/', $publicPath), '/');
        $quote = static fn (string $value): string => '"'.str_replace(['\\', '"', '$', "\n", "\r"], ['\\\\', '\\"', '\\$', '', ''], $value).'"';
        $mount = $quote($prefix.'/');
        $directory = $quote($publicPath.'/');
        $name = '@upi_'.substr(hash('sha256', $prefix.$publicPath), 0, 12);
        $redirectTarget = substr($quote($prefix.'/'), 0, -1).'$is_args$args"';
        $redirect = $prefix === '' ? '' : 'location = '.$quote($prefix).' { return 308 '.$redirectTarget.'; }'."\n";
        $php = static function (string $script) use ($prefix, $publicPath, $quote, $phpHandler): string {
            $filename = $quote($publicPath.'/'.$script);
            $scriptName = $quote($prefix.'/'.$script);
            $documentRoot = $quote($publicPath);

            return <<<NGINX
    fastcgi_pass {$phpHandler};
    fastcgi_param SCRIPT_FILENAME {$filename};
    fastcgi_param SCRIPT_NAME {$scriptName};
    fastcgi_param DOCUMENT_ROOT {$documentRoot};
    fastcgi_param DOCUMENT_URI {$scriptName};
    fastcgi_param REQUEST_URI \$request_uri;
    fastcgi_param QUERY_STRING \$query_string;
    fastcgi_param REQUEST_METHOD \$request_method;
    fastcgi_param CONTENT_TYPE \$content_type;
    fastcgi_param CONTENT_LENGTH \$content_length;
    fastcgi_param SERVER_PROTOCOL \$server_protocol;
    fastcgi_param REQUEST_SCHEME \$scheme;
    fastcgi_param HTTPS \$https if_not_empty;
    fastcgi_param GATEWAY_INTERFACE CGI/1.1;
    fastcgi_param SERVER_SOFTWARE nginx/\$nginx_version;
    fastcgi_param REMOTE_ADDR \$remote_addr;
    fastcgi_param REMOTE_PORT \$remote_port;
    fastcgi_param SERVER_ADDR \$server_addr;
    fastcgi_param SERVER_PORT \$server_port;
    fastcgi_param SERVER_NAME \$server_name;
    fastcgi_param REDIRECT_STATUS 200;
    fastcgi_param HTTP_PROXY "";
NGINX;
        };
        $index = $php('index.php');
        $installer = $php('install.php');
        $indexLocation = $quote($prefix.'/index.php');
        $installLocation = $quote($prefix.'/install.php');

        return <<<NGINX
# Inside the existing server block. Keep the parent website root unchanged.
# Replace the PHP-FPM socket with the value from the panel's ACTIVE PHP handler.
# Remove earlier conflicting locations for this application prefix only.
{$redirect}location ^~ {$mount} {
    alias {$directory};
    autoindex off;
    # Nested rules remain active while ^~ prevents the parent PHP/static regexes taking over.
    location ~ /\. { return 404; }
    location ~* \.(?:php[0-9]?|phtml|phar)(?:/|\$) { return 404; }
    location ~* \.(?:sqlite[0-9]*|sql|log|bak|zip|tar|gz)(?:/|\$) { return 404; }
    # Only public files are candidates; private directories such as docs cannot collide.
    try_files \$uri {$name};
}
location = {$indexLocation} {
{$index}
}
location = {$installLocation} {
{$installer}
}
location {$name} {
{$index}
}
NGINX;
    }
}
