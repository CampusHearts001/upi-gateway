# Ubuntu VPS Installation

Ubuntu VPS deployment uses Nginx, PHP-FPM, and MySQL. These commands prepare the server; the final application setup is completed through the same browser wizard described in [INSTALLATION.md](INSTALLATION.md).

The installer disables public registration by default. To intentionally open merchant signup, set `ALLOW_REGISTRATION=true` in the private `.env` file and run `php artisan config:cache` if configuration is cached. Set it back to `false` to close signup; existing merchant login and administrator merchant controls remain available.

## 1. Install server packages

```bash
sudo apt update
sudo apt install nginx mysql-server php8.2-fpm php8.2-cli php8.2-mysql \
  php8.2-mbstring php8.2-xml php8.2-curl php8.2-zip php8.2-bcmath unzip
```

Verify PHP and required modules:

```bash
php -v
php -m | grep -E 'PDO|pdo_mysql|openssl|mbstring|tokenizer|json|curl|fileinfo|xml'
```

## 2. Create the MySQL database

```bash
sudo mysql
```

```sql
CREATE DATABASE upi_gateway CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'upi_gateway'@'localhost' IDENTIFIED BY 'replace-with-a-strong-password';
GRANT ALL PRIVILEGES ON upi_gateway.* TO 'upi_gateway'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

## 3. Place the application

Upload or clone the release into `/var/www/upi-gateway`. If dependencies are not bundled, run `composer install --no-dev --prefer-dist --optimize-autoloader` before changing ownership. The deployed project must contain `vendor/autoload.php` and `public/build`.

```bash
sudo chown -R www-data:www-data /var/www/upi-gateway
sudo chmod -R ug+rwX /var/www/upi-gateway/storage /var/www/upi-gateway/bootstrap/cache
```

## 4. Configure Nginx and PHP-FPM

Create an Nginx site whose root is `/var/www/upi-gateway/public` and whose PHP handler points to the installed PHP-FPM socket, for example:

```nginx
server {
    listen 80;
    server_name your-domain.example;
    root /var/www/upi-gateway/public;

    index index.php;
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
    location ~ /\. { deny all; }
}
```

```bash
sudo nginx -t
sudo systemctl reload nginx
```

Issue a TLS certificate with the approved certificate tooling before production use. Set the document root to `public/`; never point Nginx at the project root.

For a TLS-terminating reverse proxy, set `APP_URL=https://your-domain.example` and `TRUSTED_PROXIES` to the comma-separated IP addresses or CIDR ranges of proxies that connect directly to PHP (for example, `10.0.0.5`). Never use `*`. At the public edge, replace client-supplied `X-Forwarded-For` and `X-Forwarded-Proto` with the verified client address and scheme; pass those headers only through trusted internal proxies. Restrict origin access to those proxies. For Cloudflare, use its current published proxy IP ranges and keep them updated; do not trust `CF-Connecting-IP` or client-supplied forwarded headers directly. Production payment URLs use the canonical `APP_URL` host and HTTPS. Verify HSTS on a public HTTPS response after deployment.

The browser installation wizard is passwordless, secure, and straightforward. On first access, inspect requirements and proceed directly to database setup. The database setup screen requires only your standard MySQL credentials without manual tokens or passwords. For security, database connections must be local (`127.0.0.1` or `localhost`). Successful installation automatically locks the installer.

## 5. Run the web wizard

Open `https://your-domain.example/`. The wizard checks PHP/PDO MySQL and writable paths, tests the MySQL credentials, writes `.env` and `APP_KEY`, runs migrations, creates the first administrator, and locks the installer. Follow [INSTALLATION.md](INSTALLATION.md) for each screen.

## 6. Queue and scheduler

Run the queue worker under Supervisor or systemd, and schedule Laravel's scheduler once per minute:

```cron
* * * * * cd /var/www/upi-gateway && php artisan schedule:run >> /dev/null 2>&1
```

Keep `APP_DEBUG=false`, use HTTPS, restrict SSH and MySQL exposure, and back up the MySQL database before migrations.

## 7. Session Security, Clustering, and Key Rotation

### Session Encryption at Rest
Production VPS installations should store sessions encrypted at rest:
```env
SESSION_DRIVER=database
SESSION_ENCRYPT=true
```
When `SESSION_ENCRYPT=true`, session contents written to the `sessions` database table are encrypted using AES-256-CBC via Laravel's encrypter. This prevents plain session attributes, CSRF tokens, and credentials from appearing in database backups, replication streams, or query logs.

### Multi-Node VPS Clusters
If you scale horizontally with multiple VPS instances behind a load balancer (such as HAProxy, AWS ALB, or Nginx):
- All VPS nodes **must** share the identical `APP_KEY` in their `.env` files.
- If keys differ between nodes, requests routed to another node will fail to decrypt session cookies or database records, repeatedly logging users out.

### Key Rotation with `APP_PREVIOUS_KEYS`
To rotate `APP_KEY` without terminating active merchant sessions:
1. Generate a new key with `php artisan key:generate --show`.
2. Move the old key to `APP_PREVIOUS_KEYS` in `.env` and set the new key:
   ```env
   APP_KEY=base64:NEW_KEY_HERE...
   APP_PREVIOUS_KEYS=base64:OLD_KEY_HERE...
   ```
   (For multiple previous keys, separate with commas: `key1,key2`).
3. Re-cache configuration: `php artisan config:cache`.
4. Laravel will decrypt existing sessions using `APP_PREVIOUS_KEYS` and automatically re-encrypt them with the new `APP_KEY` as requests occur.
5. After `SESSION_LIFETIME` minutes have elapsed (default 120 minutes), all active sessions have rotated to the new key, and `APP_PREVIOUS_KEYS` can be safely cleared.

### Safe Migration Procedure for Existing Installations
If migrating an existing production VPS from `SESSION_ENCRYPT=false` to `SESSION_ENCRYPT=true`:
1. Inform users of a brief session reset, or perform the migration during low-traffic maintenance windows.
2. In `/var/www/upi-gateway/.env`, set `SESSION_ENCRYPT=true`.
3. Refresh the configuration cache: `php artisan config:cache`.
4. Existing plaintext database sessions cannot be decrypted under encryption mode. Laravel catches decryption exceptions gracefully, discards the unencrypted payload without HTTP 500 errors, and redirects the user to log in again.
5. All subsequent sessions are stored encrypted.
