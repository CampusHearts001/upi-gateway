<?php

return [
    'outbox_batch_size' => max(1, min(1000, (int) env('WEBHOOK_OUTBOX_BATCH_SIZE', 100))),
    'claim_seconds' => 120,
    'dispatch_retry_seconds' => 60,
    // Longer than the existing six-attempt job's cumulative backoff budget.
    'reconcile_seconds' => 10800,
    'failed_retry_seconds' => 3600,
    'max_dispatch_generations' => max(1, (int) env('WEBHOOK_MAX_DISPATCH_GENERATIONS', 10)),
    'delivery_lock_seconds' => max(1, (int) env('WEBHOOK_DELIVERY_LOCK_SECONDS', 60)),
    'retention_days' => max(1, (int) env('WEBHOOK_RETENTION_DAYS', 30)),
];
