# Web Installation Wizard

Every supported deployment method ends in this browser flow. cPanel and Ubuntu VPS instructions prepare PHP, MySQL, filesystem permissions, and the web server; the wizard then performs application setup.

The wizard writes `ALLOW_REGISTRATION=false` to `.env`; public `/register` GET and POST requests return 404 and the login page hides the signup link. An administrator may intentionally open public merchant signup by setting `ALLOW_REGISTRATION=true` in the server's private `.env` file and refreshing cached Laravel configuration with `php artisan config:cache` if applicable. Existing merchant accounts and administrator controls remain available with signup disabled.

## Before opening `/install`

Verify PHP 8.2+ and PDO, PDO MySQL, OpenSSL, mbstring, tokenizer, JSON, cURL, fileinfo, and XML. Create an empty MySQL 8.x or MariaDB 10.x/11.x database and a user able to create and alter tables. The prepared package must contain `vendor/autoload.php` and `public/build/`.

The web root must be Laravel's `public/` directory. `storage/` and `bootstrap/cache/` must be writable by PHP, and the application must be able to create `.env`. Use HTTPS in production. SQLite is not supported at runtime.

## Step-by-step wizard

1. Open `https://yourdomain.com/install`.
2. On **Server Requirements**, confirm green checks for PHP, PDO, PDO MySQL, OpenSSL, mbstring, tokenizer, JSON, cURL, writable `storage/`, and writable `.env` handling. Fix missing extensions or permissions and refresh.
3. On **Database Setup**, enter App Name and HTTPS App URL. MySQL is the only database type. Enter host, port (`3306` unless your host says otherwise), full database name, full username, and password, then select **Test connection and continue**.
4. After connection success, the wizard writes `.env` atomically, generates `APP_KEY` when needed, applies production-safe settings, and continues.
5. On **Run Migrations**, start migration to create the required tables. Failures show a generic message; fix database permissions/settings and retry.
6. On **Create Admin Account**, enter Full Name, Email Address, Password, and Confirm Password. The password must be at least 10 characters.
7. On **Installation Complete**, choose **Go to Login**. The installation marker locks the wizard. The API is unavailable before installation and the wizard cannot be reopened after success.

## Screenshots

The following screenshots show the actual Installation Wizard flow:

### Database setup

![UPI Gateway MySQL database setup](images/installation/database-setup.png)

### Successful migrations

![UPI Gateway successful migrations](images/installation/migrations-success.png)

### Create administrator

![UPI Gateway administrator account setup](images/installation/admin-account.png)

## After installation

Configure merchant accounts, payment accounts, webhooks, queue processing, scheduled expiry processing, HTTPS, restricted permissions, and MySQL backups. See [CPANEL.md](CPANEL.md) or [VPS.md](VPS.md).

## Payment verification note

For manual UPI accounts, a customer-submitted UTR places the order in **Review**. A matching UTR entered by a merchant is not proof of payment and does not mark a Live order as paid. Live payment completion requires trusted provider verification. A separately protected administrator override, when used, requires an exact amount confirmation, an independent verification reference, a written reason, and an append-only audit record.

## Payments received after expiry

All new order notifications use the [transactional webhook outbox](WEBHOOK-OUTBOX.md). Run migrations before starting the updated application, and keep both scheduler and queue worker running. Late `order.paid` and earlier `order.expired` events retain separate immutable snapshots and stable IDs; consumers must deduplicate by event ID and handle out-of-order delivery using the per-order sequence.

A saved UPI QR or intent can remain payable after its gateway order expires. Live orders using Paytm Business, Paytm PG, or PhonePe PG status verification can be recovered automatically for **24 hours after `expires_at`**. An expired order becomes paid only after the server checks the provider's status API and validates the order, amount, INR currency, reference, provider account, and Live environment. A pending, failed, invalid, or mismatched response leaves it expired. Frontend callbacks and submitted UTRs cannot authorize recovery. Internal Sandbox simulations and manual UPI accounts are excluded.

Configure the window and scheduler workload in `.env`:

```dotenv
GATEWAY_LATE_PAYMENT_GRACE_HOURS=24
GATEWAY_LATE_PAYMENT_CHECK_INTERVAL_SECONDS=300
GATEWAY_LATE_PAYMENT_BATCH_SIZE=100
```

Setting the grace period to `0` disables automatic late recovery. Expired orders are checked at most once per interval (minimum 120 seconds), including browser/API polling and forced provider returns. Each `orders:check-pending` scheduler run processes at most the configured number of due expired orders (1–1000), oldest checked first. Keep the scheduler and webhook queue worker running as described in [CPANEL.md](CPANEL.md) or [VPS.md](VPS.md). After changing configuration, rebuild any deployed configuration cache with `php artisan config:cache`.

Provider checks use a 120-second per-order cache lock, released on completion and automatically expiring after a crashed process. Use the existing shared `database` cache store, including the `cache_locks` table, on all application instances. Do not switch production to a process-local array/null store. No SQL row locks are held by the check service during provider HTTP; the final payment transaction locks and revalidates the order and payment account. Provider request timeouts are unchanged.

Recovery depends on the provider retaining a queryable status. Paytm Business uses the application's existing unofficial status endpoint, whose availability is not guaranteed. The grace period is a verification window, not a promise that a transfer will be detected or settled. Keep your merchant integration able to accept an authenticated `order.paid` webhook after an earlier `order.expired` event, and fulfill each order only once.

**After the configured deadline, no automatic provider checks or recovery are performed.** The order remains expired even if the bank later receives money. Do not ask the customer to pay again without checking. Investigate with the bank/provider and use the separately authorized, audited administrator review process or arrange a refund through the appropriate provider/bank. Do not treat the customer's UTR alone as proof. No automatic refund is initiated.

Late checks log order/account IDs, environments, provider, expiry, and a safe outcome or validation-field list. Provider payloads, credentials, UTRs, and secrets are not included. Invalid legacy account assignments are skipped so other orders can be processed; investigate those records rather than changing their environment or payment history silently.

Provider checks also use database ownership fencing. Set a stable, unique, non-placeholder `GATEWAY_VERIFICATION_NODE_ID` on every production server or container that can run the application; never share the value between nodes. Production rejects missing or placeholder identities. Each installation creates a local runtime installation ID and registers it in MySQL, allowing duplicate active node names to fail closed. Do not copy `storage/framework/verification-node-installation-id` between servers. A time limit alone does not transfer ownership while the recorded process may still be making an HTTP request. Same-node crash recovery requires confirmed process death and matching process-start identity. Unknown or remote-node liveness fails closed. See the [cPanel recovery procedure](CPANEL.md#provider-verification-ownership-and-recovery) for the audited operator workflow; do not clear a lease until its recorded execution is conclusively stopped.
