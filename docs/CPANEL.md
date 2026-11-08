# cPanel shared-hosting installation

This guide explains how to prepare cPanel and complete setup directly in your browser. You do **not** need SSH, Composer, Node.js, or npm on the hosting server; the distribution package includes all production PHP dependencies in `vendor/` and pre-built frontend assets in `public/build/`.

The installer writes `ALLOW_REGISTRATION=false`. To intentionally allow public merchant signup later, an administrator can edit the private `.env` file in cPanel File Manager to `ALLOW_REGISTRATION=true`. If Laravel configuration has been cached, refresh that cache using the hosting PHP CLI (`php artisan config:cache`) or ask the host to do so. Set the value back to `false` to close signup. Existing merchant login and administrator merchant controls work while public signup is closed.

---

## Requirements

- **PHP Version**: PHP 8.2 or higher (8.2, 8.3, 8.4).
- **Database**: MySQL 8.x or MariaDB 10.x/11.x with `ALL PRIVILEGES`.
- **PHP Extensions**:
  - `pdo` & `pdo_mysql` (required for database connectivity)
  - `openssl` (required for AES encryption and secure keys)
  - `mbstring` (multibyte string handling)
  - `tokenizer` (PHP code parser)
  - `json` (JSON payload encode/decode)
  - `curl` (HTTP requests to payment providers)
  - `fileinfo` (MIME-type detection)
  - `xml` (XML parsing for providers)
  - `ctype` (character classification)
  - `bcmath` (precision decimal arithmetic)
- **Writable Paths**:
  - `storage/` (including `app/`, `framework/cache/`, `framework/sessions/`, `framework/views/`, `logs/`)
  - `bootstrap/cache/`
  - Application root (during installation only, to create `.env`)
- **Web Server**: Apache or LiteSpeed with `mod_rewrite` enabled.

---

## Step 1 — Prepare hosting in cPanel

1. **PHP Version**: Open **MultiPHP Manager** or **Select PHP Version** in cPanel. Select **PHP 8.2** or higher.
2. **PHP Extensions**: In **Select PHP Version > Extensions**, confirm that `pdo_mysql`, `openssl`, `mbstring`, `curl`, `fileinfo`, `xml`, `ctype`, and `bcmath` are enabled.
3. **SSL Certificate**: Ensure **AutoSSL** or Let's Encrypt has issued an SSL certificate for your domain so you can run the installer over HTTPS.

If the host terminates HTTPS at a reverse proxy, ask for the IP address or CIDR range of the proxy that connects directly to PHP. Set `TRUSTED_PROXIES` to only those addresses in the private `.env` file; leave it empty when PHP receives the request directly. Never set it to `*` or trust a client-supplied forwarded header. Keep `APP_URL` on the public HTTPS domain. If Cloudflare is used, restrict origin access to Cloudflare and maintain its published proxy IP ranges. After installation, verify the real client IP and the `Strict-Transport-Security` header on an HTTPS response.

---

## Step 2 — Upload application

### Obtaining the production package

You can obtain the ready-to-install package using any of the following methods:

#### Option A: GitHub Direct 1-Click Download (Recommended for Beginners)
The `main` branch is configured as a complete distribution containing pre-bundled `vendor/` production dependencies and compiled frontend assets in `public/build/`. Development tools, internal scripts, and test suites are automatically excluded via `.gitattributes`.

1. Download directly from GitHub:
   - Click the green **Code** button on GitHub and choose **Download ZIP**, or use the direct link: [**Download `main.zip`**](https://github.com/mynkdev-21/upi-gateway/archive/refs/heads/main.zip).
2. Note on extraction: GitHub's native ZIP places files inside a top-level `upi-gateway-main/` folder. When you extract it in File Manager, open `upi-gateway-main/`, select all files, and click **Move** to move them into your target application or website root directory.

#### Option B: Standalone Production Release Package (With SHA-256)
Every push to `main` automatically builds, tests, and publishes a verified, flat production package to the rolling GitHub Release:

1. Download the latest production package directly:
   - **Direct ZIP**: [**Download `upi-gateway-production.zip`**](https://github.com/mynkdev-21/upi-gateway/releases/download/latest-main/upi-gateway-production.zip)
   - **Checksum**: [**`upi-gateway-production.zip.sha256`**](https://github.com/mynkdev-21/upi-gateway/releases/download/latest-main/upi-gateway-production.zip.sha256)
2. (Optional) Verify package integrity:
   ```bash
   sha256sum -c upi-gateway-production.zip.sha256
   # Or on macOS:
   shasum -a 256 -c upi-gateway-production.zip.sha256
   ```
3. Note on extraction: This package is completely flat and extracts directly into your target directory without an extra nested folder.

#### Option C: Download via GitHub Actions Artifacts
You can also download build artifacts from any individual GitHub Actions run:
1. Open the repository on GitHub and navigate to the **Actions** tab &rarr; [Build Production Package](https://github.com/mynkdev-21/upi-gateway/actions/workflows/build-package.yml).
2. Select the latest completed run on `main`.
3. Scroll to the **Artifacts** section at the bottom and click `upi-gateway-main-<commit>` to download.
4. Extract the downloaded artifact bundle on your computer to reveal the inner `upi-gateway-production.zip`.

#### Option D: Build locally from source
If you have Git, PHP 8.2+, Composer, and Node.js installed on your machine:
```bash
./scripts/build-release.sh production HEAD releases
```

### Dedicated application directory (cPanel / aaPanel)
1. **cPanel**:
   - In cPanel **File Manager**, create a directory outside `public_html`, e.g., `/home/ACCOUNT/upi-gateway`.
   - Upload the downloaded ZIP (`main.zip` or `upi-gateway-production.zip`) and extract it directly into that directory. If using `main.zip`, move the contents out of the extracted `upi-gateway-main/` subfolder into the application directory.
   - Set your domain's Document Root to `/home/ACCOUNT/upi-gateway/public` in **Domains** or **Subdomains**.
2. **aaPanel / Baota (Nginx)**:
   - In aaPanel **File Manager**, navigate to your website root (e.g. `/www/wwwroot/yourdomain.com`).
   - Upload the downloaded ZIP and click **Uncompress**. If using `main.zip`, move the contents out of `upi-gateway-main/` directly into the website root. Ensure all files reside in the website root (do not keep them inside a subfolder).
   - In aaPanel **Website** &rarr; Click your domain &rarr; **Site directory**:
     - Set **Site directory** to your application folder.
     - Set **Running directory** to `/public` and click **Save**. *(MANDATORY FOR SECURITY)*
   - In **URL rewrite**, select `laravel5` and click **Save**.
   - In aaPanel **App Store** &rarr; **PHP (8.2 / 8.3 / 8.4)** &rarr; **Install extensions**: Ensure `fileinfo`, `pdo_mysql`, and `bcmath` are installed.
3. Set directory permissions on `storage/` and `bootstrap/cache/` to `775` (or writable by the web server user). Never leave `777` permanently.

> [!CAUTION]
> **Critical Nginx / aaPanel Security Notice:**
> Unlike Apache and LiteSpeed, **Nginx completely ignores `.htaccess` rewrite and deny rules**. The root `index.php` forwarder exists solely for convenience in Apache environments; **it does NOT protect sensitive files from public download under Nginx**.
> You **MUST** set the aaPanel **Running directory** to `/public`. If the document root is pointed to the project root on Nginx, attackers can download your `.env` credentials, database passwords, API secrets, and application logs directly over HTTP.
>
> As an additional layer of defense-in-depth, you can add the following rules to your Nginx site configuration in aaPanel (**Website** &rarr; Click domain &rarr; **Config**):
> ```nginx
> # Strictly deny direct public HTTP access to sensitive files and directories
> location ~ /\.(env|git|ht) {
>     deny all;
>     return 404;
> }
>
> location ~ ^/(app|bootstrap|config|database|resources|routes|storage|vendor)/ {
>     deny all;
>     return 404;
> }
>
> location ~ ^/(artisan|composer\.(json|lock)|package(-lock)?\.(json|lock)|phpunit\.xml) {
>     deny all;
>     return 404;
> }
> ```

If your hosting plan cannot point the domain's document root at `public/`, ask the host to configure that mapping before installing. Keep application source, dependencies, `.env`, and storage outside the public web root.

---

## Step 3 — Create MySQL database in cPanel

1. In cPanel, click **MySQL Database Wizard** (or **MySQL Databases**).
2. Create a new database, e.g., `acme_upigateway`.
3. Create a new database user, e.g., `acme_dbuser`, with a strong generated password.
4. Add the user to the database, select **ALL PRIVILEGES**, and click **Make Changes**.
5. Note down:
   - Database Name (e.g. `acme_upigateway`)
   - Database Username (e.g. `acme_dbuser`)
   - Database Password
   - Database Host (`127.0.0.1` or `localhost` on most cPanel hosts)
   - Database Port (`3306`)

---

## Step 4 — Open the Browser Installation Wizard

Navigate to `https://yourdomain.com/install` or `https://yourdomain.com/install.php` in your browser. With the document root pointed at `public/`, the latter uses `public/install.php`.

On the first installer visit, open `storage/installer-setup-token` in cPanel File Manager under the private application directory. Enter the 64 characters before the colon in the database setup form. Reopen the file after each attempt because the token rotates; an expired token is refreshed when you revisit the installer. Do not place the file in `public/` or share it; the installer deletes it after successful setup.

The wizard runs through 5 automated steps:

### 1. Requirements Check
The installer inspects PHP version, all 11 required extensions, and writable storage paths. If any check fails, resolve it in cPanel and click refresh.

### 2. Database & Application Configuration
Enter:
- **Application Name**: e.g., `Acme UPI Gateway`
- **Application URL**: `https://yourdomain.com` (must match your actual HTTPS domain)
- **Database Host & Port**: `127.0.0.1` and `3306`
- **Database Name & User**: Full prefixed values from Step 3
- **Database Password**: Database user password
Click **Test Connection & Save Configuration**. The installer validates the connection via PDO and atomically writes `.env` with a cryptographically secure 256-bit `APP_KEY` and production defaults (`APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=error`, `SESSION_SECURE_COOKIE=true`).

### 3. Run Database Migrations
Click **Run Database Migrations Now**. The installer runs migrations in-process via Laravel's Migrator and verifies all required core tables exist without requiring SSH or `proc_open`.

### 4. Create Primary Administrator Account
Enter your Full Name, Email, and Password (min 10 characters). This creates the master administrator dashboard login and inserts a database lock in `installation_guards`.

### 5. Installation Complete & Permanent Lock
The installer creates the `storage/installed` marker (`chmod 0600`). Later visits to `install.php` return `403 Forbidden`; the Laravel `/install` route redirects to the home page. Neither permits re-entry.

---

## Step 5 — Configure cPanel Cron Jobs

The application requires two one-minute cron jobs in cPanel **Cron Jobs** to process webhooks and payment verifications without SSH or daemon supervisor processes:

### 1. Scheduler Cron (Every minute: `* * * * *`)
```cron
* * * * * cd /home/ACCOUNT/upi-gateway && /usr/local/bin/php artisan schedule:run >> /home/ACCOUNT/upi-gateway/storage/logs/scheduler.log 2>&1
```
*Purpose*: Dispatches pending webhook outbox events, checks pending order provider statuses, expires unpaid orders, and recovers late payments.

### 2. Queue Worker Cron (Every minute: `* * * * *`)
```cron
* * * * * cd /home/ACCOUNT/upi-gateway && /usr/local/bin/php artisan queue:work database --queue=default --stop-when-empty --max-time=50 --tries=6 --timeout=60 --memory=128 --sleep=3 >> /home/ACCOUNT/upi-gateway/storage/logs/queue.log 2>&1
```
*Purpose*: Executes queued webhook delivery jobs to merchant webhook endpoints with backoff retries.

> [!TIP]
> Find your exact cPanel PHP CLI binary path using `command -v php` in cPanel Terminal or ask your host (often `/opt/cpanel/ea-php82/root/usr/bin/php` or `/usr/local/bin/php`).

---

## Real cPanel Hosting Verification Checklist

Use this checklist to verify a live deployment on real cPanel shared hosting:

- [ ] **1. Hosting Environment**:
  - PHP version is 8.2 or higher in MultiPHP Manager.
  - PDO MySQL, OpenSSL, cURL, Mbstring, Fileinfo, and BCMath are enabled in PHP Extensions.
  - SSL certificate is active (HTTPS green padlock).
- [ ] **2. File Placement & Permissions**:
  - `vendor/` and `public/build/` are present directly in the application folder.
  - `storage/` and `bootstrap/cache/` permissions are `775` (writable by PHP).
  - Root and public `.htaccess` files are present.
- [ ] **3. Security Gate Verification**:
  - Attempt accessing `https://yourdomain.com/.env` in browser &rarr; verifies HTTP 403 Forbidden.
  - Attempt accessing `https://yourdomain.com/storage/logs/laravel.log` &rarr; verifies HTTP 403 Forbidden.
- [ ] **4. Browser Wizard Execution**:
  - Open `https://yourdomain.com/install.php`.
  - Verify all requirement badges show green **Pass**.
  - Submit database credentials and confirm `.env` generation.
  - Execute migrations and verify success.
  - Create admin account and proceed to `/login`.
- [ ] **5. Re-entry Lock Verification**:
  - Re-open `https://yourdomain.com/install.php` &rarr; verifies HTTP 403 Forbidden "Application Already Installed".
  - Re-open `https://yourdomain.com/install` &rarr; verifies redirect to `/` after a completed Laravel installation.
- [ ] **6. Dashboard & Payment Flow**:
  - Log in with administrator credentials.
  - Configure a merchant UPI payment account (Paytm Business / PG).
  - Create a test order and verify QR code and intent links render.
- [ ] **7. Background Tasks**:
  - Add both Cron Jobs in cPanel Cron Jobs interface.
  - Verify `storage/logs/scheduler.log` records execution entries every minute.
  - Verify webhook outbox deliveries record successful HTTP responses.

## Step 5 — Installation complete

Choose **Go to Login** and sign in at `/login`. The installation marker locks the wizard after success. Do not delete or bypass it; it protects the existing database and administrator.

## Queue worker and scheduler

Webhook events now use a [transactional outbox](WEBHOOK-OUTBOX.md). The scheduler runs `webhooks:dispatch` before provider checks each minute; payment transactions record events without accessing the queue. Keep the two cron entries below. A queued event is not delivered until the worker records a successful merchant HTTP response. The outbox guide covers stale claims, failed jobs, outage recovery and event-ID deduplication.

Both cron entries below are required:

- The scheduler checks pending orders, performs the final provider check, expires unpaid orders, and checks eligible expired Live orders for late payments during the configured recovery window.
- The queue worker delivers merchant webhooks after payment, expiry, UTR review, or Sandbox events.

If the scheduler is missing, pending orders can remain pending past their expiry time. If the worker is missing, payment state can still be saved but webhook jobs remain in MySQL and merchants do not receive notifications.

### Find the PHP binary

In cPanel, confirm PHP 8.2+ in **MultiPHP Manager** or **Select PHP Version**. The PHP version selected for the domain and the PHP version used by Cron must match. If cPanel Terminal is available, run:

```bash
command -v php
php -v
```

On many cPanel hosts the binary is versioned, for example:

```text
/opt/cpanel/ea-php82/root/usr/bin/php
```

Use the path supplied by your host rather than assuming `/usr/local/bin/php`.

### Find the application path

In **File Manager**, open the directory containing `artisan`, `app/`, `public/`, and `vendor/`. That directory is the Laravel application path, for example:

```text
/home/ACCOUNT/upi-gateway
```

Do not use the `public/` subdirectory as the working directory for Artisan commands. The domain document root is `/home/ACCOUNT/upi-gateway/public`, but the cron working directory is `/home/ACCOUNT/upi-gateway`.

### Add the scheduler cron

In **Cron Jobs**, add a job that runs every minute. Replace `ACCOUNT`, the application path, and the PHP binary with your actual values:

```cron
* * * * * cd /home/ACCOUNT/upi-gateway && /opt/cpanel/ea-php82/root/usr/bin/php artisan schedule:run >> /home/ACCOUNT/upi-gateway/storage/logs/scheduler.log 2>&1
```

The scheduled `orders:check-pending` command operates with independent, bounded dual-phase execution protected by command-level distributed locking (`Cache::lock('scheduler:orders:check-pending')`) and `withoutOverlapping()`:
- **Phase 1 (Pending Verification)**: Checks up to `GATEWAY_PENDING_VERIFICATION_BATCH_SIZE` (default: 100, clamped 1–1000) due pending orders per cycle.
- **Phase 2 (Late-Payment Recovery)**: Independently checks up to `GATEWAY_LATE_PAYMENT_BATCH_SIZE` (default: 100, clamped 1–1000) due expired orders per cycle, with a 300-second recheck interval (`GATEWAY_LATE_PAYMENT_CHECK_INTERVAL_SECONDS`, min 120s) and a configurable recovery window (`GATEWAY_LATE_PAYMENT_GRACE_HOURS`, default 24h) measured from `expires_at`.

Both phases are isolated with dedicated error boundaries so pending exceptions or a large backlog of pending orders cannot starve late-payment recovery. Orders are selected fairly via round-robin ordering (`last_checked_at ASC NULLS FIRST, id ASC`), rotating failing or checked orders to the back of the queue. Distributed command locking (`GATEWAY_SCHEDULER_LOCK_SECONDS=300`) prevents concurrent direct CLI or cPanel cron executions from colliding. Keep `CACHE_STORE=database` and the migrated `cache_locks` table available. See [late-payment recovery configuration and limits](INSTALLATION.md#payments-received-after-expiry). No automatic recovery is promised after the configured deadline.

### Add the database queue cron

Add a second cron job to process background webhook deliveries reliably without Supervisor or long-running daemon processes:

```cron
* * * * * cd /home/ACCOUNT/upi-gateway && /opt/cpanel/ea-php82/root/usr/bin/php artisan queue:work database --queue=default --stop-when-empty --max-time=50 --tries=6 --timeout=60 --memory=128 --sleep=3 >> /home/ACCOUNT/upi-gateway/storage/logs/queue.log 2>&1
```

**Options explained for cPanel shared hosting**:
- `--stop-when-empty`: The worker stops immediately as soon as all queued jobs have finished, releasing memory and CPU back to the server.
- `--max-time=50`: The worker stops after 50 seconds even if a large backlog of jobs exists. This guarantees that the process terminates cleanly before the next minute's cron job launches, preventing process accumulation and hitting cPanel max-concurrent-process limits.
- `--timeout=60`: Each individual job has a hard 60-second execution limit. This is strictly below the database queue `retry_after` setting (90 seconds), preventing another worker from reclaiming an active job.
- `--memory=128`: Halts the worker if memory consumption exceeds 128 MB, preventing out-of-memory crashes on resource-constrained shared accounts.
- `--tries=6`: Retries failed jobs up to 6 times according to the backoff schedule (`30s, 2m, 10m, 30m, 1h`).

### How to inspect logs

Shared-hosting cron jobs output to dedicated files in `storage/logs/`:

1. **Queue Worker Activity**:
   ```bash
   cat /home/ACCOUNT/upi-gateway/storage/logs/queue.log
   # Or monitor live output if terminal is available:
   tail -n 50 -f /home/ACCOUNT/upi-gateway/storage/logs/queue.log
   ```
2. **Scheduler Execution**:
   ```bash
   cat /home/ACCOUNT/upi-gateway/storage/logs/scheduler.log
   tail -n 50 -f /home/ACCOUNT/upi-gateway/storage/logs/scheduler.log
   ```
3. **Structured Application & Queue Events**:
   Structured log entries for queue worker lifecycle events (`started processing job`, `completed job successfully`, `encountered exception`, and `failed permanently`) are automatically written to `storage/logs/laravel.log` without exposing merchant secrets, API keys, or payment credentials.

### How to recover failed jobs

When a webhook job exhausts all 6 delivery attempts, it is moved to the `failed_jobs` table and logged in `storage/logs/laravel.log`:

```bash
# 1. View all failed queue jobs
/opt/cpanel/ea-php82/root/usr/bin/php artisan queue:failed

# 2. Retry all failed queue jobs
/opt/cpanel/ea-php82/root/usr/bin/php artisan queue:retry all

# 3. Retry a specific job by ID or UUID
/opt/cpanel/ea-php82/root/usr/bin/php artisan queue:retry <failed-job-id>

# 4. Discard an unrecoverable failed job
/opt/cpanel/ea-php82/root/usr/bin/php artisan queue:forget <failed-job-id>

# 5. Retry a specific transactional outbox webhook event
/opt/cpanel/ea-php82/root/usr/bin/php artisan webhooks:retry <event-id>
```

### How to disable cron jobs safely

If maintenance or database migration is required:
1. Open cPanel **Cron Jobs**.
2. Edit or comment out the two cron commands (or prepend `#`).
3. Running worker processes will finish their active job within 50–60 seconds and exit.
4. While cron is disabled, orders and webhook events are safely recorded in the database `webhook_outbox` and `jobs` tables without data loss.
5. Re-enable the cron jobs when maintenance is complete; the workers will process pending jobs in order.

### Log rotation and disk usage considerations

Appending cron output (`>> storage/logs/queue.log 2>&1`) will cause log files to grow over time on busy production systems. To prevent filling up your cPanel disk quota:
1. Configure cPanel log rotation or add a weekly maintenance cron:
   ```cron
   0 0 * * 0 truncate -s 0 /home/ACCOUNT/upi-gateway/storage/logs/queue.log
   0 0 * * 0 truncate -s 0 /home/ACCOUNT/upi-gateway/storage/logs/scheduler.log
   ```
2. Or use daily log rotation for Laravel logs by setting `LOG_STACK=daily` or `LOG_DAILY_DAYS=14` in `.env`.

### Recovering a stale scheduler lock

If the scheduler stops running because a previous process left a lock behind, clear only Laravel's scheduler cache from the application directory:

```bash
/opt/cpanel/ea-php82/root/usr/bin/php artisan schedule:clear-cache
```

Then confirm the scheduler cron is enabled and inspect `storage/logs/scheduler.log`. Do not delete application tables or cache files manually as a first response.

### Scaling note

Pending-order provider checks currently run synchronously inside `orders:check-pending`. This is simple and safe for smaller installations, but a large number of pending orders or slow provider responses can make one-minute scheduler runs take longer. Monitor scheduler duration and database load; do not change queue architecture or retry settings without measuring the workload first.

### Provider verification ownership and recovery

Set `GATEWAY_VERIFICATION_NODE_ID` to one stable, unique, non-placeholder value for each server or container that can run PHP, cron, or queue commands. Production refuses to verify payments when this value is missing or still set to the `.env.example` placeholder. Do not reuse a value across VPS nodes. The first run also creates `storage/framework/verification-node-installation-id`; do not copy that runtime file between servers. The database node registry rejects two independent installations using the same node ID. A provider check records the registered node instance, PID, and process-start identity. An expired time lease alone never proves that its HTTP request stopped: same-node takeover happens only after the exact process is confirmed dead or its PID has been reused. Permission errors, missing process-inspection functions, and ownership on another node fail closed.

On shared hosting, confirm that `proc_open` and either `/proc` or `ps` are available. If they are disabled, normal checks still release their leases, but an abruptly terminated check may require manual recovery. For a remote-node or unknown-liveness lease, first stop the recorded PHP process or the entire recorded node and verify it cannot still make the provider request. Then obtain the exact `verification_token` for that order from the database and run:

```bash
/opt/cpanel/ea-php82/root/usr/bin/php artisan orders:recover-verification-lease ORDER_ID EXECUTION_TOKEN GENERATION OPERATOR "RECOVERY REASON" --confirm-stopped
```

The command requires the numeric order database ID, exact expired execution token and generation, operator identity, and a non-empty reason. It rejects terminal orders and owners that are positively alive. For a remote node or unknown liveness, independently stop the node and also pass `--confirm-node-shutdown`. Successful recovery creates immutable database audit history. `--confirm-stopped` is an operator safety assertion, not an automatic process kill. Never use it merely because 300 seconds elapsed. If process termination is not certain, leave the lease in place and investigate; this deliberately favors preventing overlapping provider calls over availability.

## Session Security & Key Rotation on cPanel

### Session Encryption at Rest
New installations generated from the `.env.example` template have `SESSION_ENCRYPT=true` enabled by default. Session payloads stored in the database `sessions` table are encrypted with AES-256-CBC using your unique `APP_KEY`. This guards against session hijacking if database backups (`.sql` dumps) or phpMyAdmin exports are accessed.

### Enabling Encryption on an Existing cPanel Site
If upgrading an existing cPanel installation that had `SESSION_ENCRYPT=false`:
1. Note that enabling encryption invalidates active sessions on their next request. Merchants and administrators currently logged in will be prompted to re-enter their credentials. The application handles this gracefully without HTTP 500 errors.
2. In cPanel **File Manager**, edit `/home/USERNAME/upi_gateway/.env` (enable "Show Hidden Files" in Settings).
3. Set `SESSION_ENCRYPT=true`.
4. In cPanel Terminal (or via SSH), clear and rebuild cache:
   ```bash
   php artisan config:cache
   ```
5. All newly written sessions are encrypted at rest immediately.

### Key Rotation (`APP_PREVIOUS_KEYS`)
If you need to rotate `APP_KEY` due to key compromise or periodic security rotation:
1. Run `php artisan key:generate --show` in Terminal (or SSH) to generate a new key.
2. In `.env`, assign the old key to `APP_PREVIOUS_KEYS` and the new key to `APP_KEY`:
   ```env
   APP_KEY=base64:NEW_KEY...
   APP_PREVIOUS_KEYS=base64:OLD_KEY...
   ```
3. Run `php artisan config:cache`.
4. Active sessions decrypt seamlessly via `APP_PREVIOUS_KEYS` and are rewritten under `APP_KEY`. After 2 hours (`SESSION_LIFETIME=120`), you can safely remove `APP_PREVIOUS_KEYS`.

### Multi-Server / Cluster Consistency
If you distribute traffic across multiple cPanel or VPS nodes sharing a central MySQL database, all servers must have the identical `APP_KEY` and cipher configuration.

## Security after installation

Use HTTPS, keep `APP_DEBUG=false`, never share `.env` or database/API credentials, and keep `storage/` and `bootstrap/cache/` writable only as required. Do not use `777` permanently. Update PHP, MySQL, dependencies, and the OS. Back up MySQL before migrations. Configure a queue worker for queued webhook delivery and cPanel Cron for `php artisan schedule:run` every minute.

See [INSTALLATION.md](INSTALLATION.md) for the common wizard flow and screenshots.
