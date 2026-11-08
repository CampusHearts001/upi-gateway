# Transactional webhook outbox

Every new order event is recorded with the order state change in the same MySQL transaction. If that transaction rolls back, both changes roll back. Payment verification does not insert queue jobs, so an unavailable `jobs` table or queue connection cannot lose the notification intent or prevent an otherwise valid payment commit. An unavailable order/outbox database must fail the payment transaction; never bypass outbox persistence to force it through.

## Operation

Use `QUEUE_CONNECTION=database` and the shared `CACHE_STORE=database`. The normal scheduler runs `webhooks:dispatch` every minute **before** scheduled provider checks. It processes up to `WEBHOOK_OUTBOX_BATCH_SIZE=100` due records per run (maximum 1000). No Redis, additional daemon, or new cPanel cron entry is required beyond the existing scheduler and queue worker.

For cPanel, replace both the PHP binary and application path with the values verified for your hosting account:

```cron
* * * * * cd /home/ACCOUNT/upi-gateway && /opt/cpanel/ea-php82/root/usr/bin/php artisan schedule:run >> /home/ACCOUNT/upi-gateway/storage/logs/scheduler.log 2>&1
* * * * * cd /home/ACCOUNT/upi-gateway && /opt/cpanel/ea-php82/root/usr/bin/php artisan queue:work database --queue=default --stop-when-empty --tries=6 --timeout=60 --sleep=3 >> /home/ACCOUNT/upi-gateway/storage/logs/queue-worker.log 2>&1
```

See [CPANEL.md](CPANEL.md) for finding the binary and application path. Both entries are needed: the scheduler publishes durable intents into the queue; the worker attempts merchant HTTP delivery. The same Artisan commands work with the queue worker and scheduler configured in [VPS.md](VPS.md).

## States and recovery

| State | Meaning | Recovery |
| --- | --- | --- |
| `pending` | Intent is committed, but queue insertion has not succeeded | Dispatcher retries; publication failures wait 60 seconds |
| `queued` | Queue accepted a job; merchant receipt is not established | Worker retries delivery; undelivered records become eligible for reconciliation after 3 hours |
| `delivered` | Merchant returned HTTP 2xx and success was recorded | No automatic republication |
| `failed` | A delivery job exhausted its existing six attempts | Retained for inspection; dispatcher can queue another job with the same event ID after 1 hour |
| `blocked` | Unsafe URL, missing webhook configuration/secret, or Sandbox/Live URL collision | Correct the configuration, then explicitly retry the event |

Each publication uses a database claim with a unique owner token and a 120-second lease. A crashed publisher's claim becomes eligible again after its lease. Updates require the claim's owner token, preventing a stale publisher from resetting a newer claim. If a publisher crashes after queue insertion but before acknowledging it, reconciliation may publish another job with the **same event ID**, never a new event identity. Delivery uses a shared 60-second cache lock and skips already successful event IDs. Lease overruns and ambiguous network failures can still cause repeated HTTP attempts.

Each queued job also carries its publication generation, taken from the outbox's monotonically increasing `dispatch_attempts`. An exhausted job may mark an event failed only if that generation is still current and no publisher owns a claim. Older jobs cannot erase newer claims or reset newer publication state. Already queued outbox jobs from before generation fencing remain readable and can deliver; their failure callbacks do not change outbox state, so scheduled reconciliation recovers an undelivered event instead.

A successful event-ID delivery log is reconciled before checking current webhook configuration, including for previously blocked events. Removing a URL or secret after a recorded HTTP success cannot turn that confirmed receipt into a blocked delivery. A crash after merchant receipt but before the success log is persisted can still require another HTTP attempt with the same event ID.

The delivery job retains six tries and backoff `[30, 120, 600, 1800, 3600]` seconds. The existing database `retry_after=90` exceeds the worker timeout of 60 seconds and HTTP timeout of 15 seconds. Failed jobs and webhook logs are not automatically deleted. The outbox's later republication is a new job with the same immutable event, not a change to the six-attempt job policy.

After a queue outage, restore the queue table/connection and keep both crons running. After a database outage, restore database availability before resuming work; notifications whose transactions committed remain recoverable. The outbox command's scheduler mutex expires after 5 minutes if its process crashes; individual publication claims expire after 120 seconds. If the scheduler is down or a command lock is stale, inspect logs, ensure no live command owns the lock, then use `php artisan schedule:clear-cache`. Individual publication claims expire without manual deletion, and `webhooks:dispatch` can also be run directly because database claims coordinate concurrent dispatchers.

Commands from the Laravel application directory:

```bash
php artisan webhooks:dispatch
php artisan queue:failed
php artisan queue:retry <failed-job-id>
php artisan webhooks:retry <event-uuid>
```

`webhooks:retry` makes an undelivered event due again without changing its ID or payload. It refuses delivered records and active publication claims. Do not routinely run both failed-job retry and outbox retry for the same event; duplicates share an identity but still create unnecessary work. Monitor old `pending`/`queued` records, `failed`/`blocked` records and `last_error`. Logs use event/outbox IDs and exception types, not exception messages, credentials or merchant response bodies.

## Immutable events and consumer responsibilities

New webhook JSON retains `event` and `data` and adds:

```json
{
  "event_id": "00000000-0000-4000-8000-000000000001",
  "event_sequence": 2,
  "occurred_at": "2026-11-07T12:00:00+05:30",
  "event": "order.paid",
  "data": {"order_id": "ORD_EXAMPLE", "status": "paid", "environment": "live"}
}
```

The UUID is also sent in `X-Event-Id`. This is an abbreviated example; `data` contains the existing API order fields. `X-Signature` remains HMAC-SHA256 of the **exact raw JSON request body**, using the merchant secret for the event's recorded Live/Sandbox environment. Verify the signature before trusting the event ID, order state, or sequence. Secrets stay in the existing merchant configuration and are never copied into the outbox. Destination URLs and signing secrets are resolved from the current merchant configuration for that recorded environment.

Event identities, payloads, order associations, environment and occurrence timestamps cannot be edited through the outbox model. Database administrators remain trusted: direct SQL can bypass model safeguards. Payload snapshots include customer fields already present in the API, so protect database access and backups accordingly.

Sequence numbers increase under an order row lock and are unique per order. They describe **committed event creation order, not delivery order**. Retries, separate workers and queue reconciliation can deliver later events first. An expiry event always retains its expired snapshot even after late recovery, including when it arrives after the paid event. Consumers must deduplicate processed `event_id` values transactionally, fulfill an order once, and use per-order sequence/state rules to prevent an older event from reverting a newer state. Delivery is **at least once**, never a promise of exactly-once receipt. Queue insertion alone is not merchant receipt, and HTTP 2xx confirms acknowledgement rather than the merchant's internal fulfillment outcome.

## Migration and upgrade

The migration adds `webhook_outbox`, a zero-initialized internal `orders.webhook_event_sequence` counter, and a nullable indexed `event_id` on `webhook_logs`. It does not backfill events, change payment states/amounts, or delete existing payments/logs. The outbox foreign key prevents deleting an order that has durable events. Back up MySQL and apply migrations before running code that records events; a missing outbox must never silently drop notification intent.

Before upgrading, stop payment writes and drain existing webhook jobs with the old code, then migrate and start the new scheduler/worker. Old serialized jobs have no historic payload snapshot. They cannot be reconstructed reliably from a later order state; if any remain, the new worker fails them for operator review rather than inventing history. Existing paid orders and previously lost notifications are not automatically backfilled.

Do not roll back the outbox schema on a running payment system. Its `down()` **refuses rollback while any retained events exist**, including delivered history. Stop writers/workers, back up/export and reconcile events under an approved archival/recovery policy before retrying. Only an empty outbox can be dropped along with the internal order counter and event-ID log column. There is no automatic retention or pruning command.

### Interrupted MySQL DDL

MySQL commits schema statements independently. If installation or upgrade stops partway through this outbox migration, keep payment writes, scheduler and workers stopped, restore database availability/permissions, and run from the application directory:

```bash
php artisan migrate:status
php artisan migrate --force
php artisan migrate:status
```

The migration resumes missing steps: the order counter, outbox table, each unique/index constraint, restrictive order foreign key, and webhook-log event column/index. Existing counters, event IDs, snapshots, payment fields and logs are retained. This also covers interruption after the last schema statement but before Laravel records migration completion. Do not use `migrate:fresh`, drop tables, reset counters, or delete events to repair a production upgrade.

Missing expected outbox columns or conflicting named index/foreign-key definitions stop with an actionable error. Back up and reconcile that schema with this migration before retrying. Automatic recovery covers this migration's interrupted DDL steps; it does not repair arbitrary schema corruption. Once migration status confirms completion, resume the scheduler and workers and monitor retained pending events.

### Historical administrator identity

The separate `2026_11_12_000001_preserve_admin_audit_actor_identifier` migration adds `admin_audit_logs.actor_admin_id`, a historical local administrator ID without a foreign key. New audit entries capture it server-side; the nullable `admin_id` relationship may become NULL when an administrator is deleted without erasing this identifier. Audit entries reject model updates and deletion. Database administrators and direct SQL remain trusted.

The migration fills missing historical identifiers only where the original `admin_id` is still known, preserving other audit fields. IDs erased before this migration cannot be reliably reconstructed and remain NULL. Stop writers and administrator deletions while migrating. Rollback refuses to drop retained historical identifiers; export/reconcile under an approved archival policy first. No audit history is automatically deleted.
