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

## 5. Run the web wizard

Open `https://your-domain.example/`. The wizard checks PHP/PDO MySQL and writable paths, tests the MySQL credentials, writes `.env` and `APP_KEY`, runs migrations, creates the first administrator, and locks the installer. Follow [INSTALLATION.md](INSTALLATION.md) for each screen.

## 6. Queue and scheduler

Run the queue worker under Supervisor or systemd, and schedule Laravel's scheduler once per minute:

```cron
* * * * * cd /var/www/upi-gateway && php artisan schedule:run >> /dev/null 2>&1
```

Keep `APP_DEBUG=false`, use HTTPS, restrict SSH and MySQL exposure, and back up the MySQL database before migrations.
