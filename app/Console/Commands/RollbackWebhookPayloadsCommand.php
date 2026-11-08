<?php

namespace App\Console\Commands;

use App\Services\WebhookRetentionService;
use Illuminate\Console\Command;

class RollbackWebhookPayloadsCommand extends Command
{
    protected $signature = 'webhooks:rollback-payloads {--chunk=100} {--purge-archive}';

    protected $description = 'Portable rollback restoring offloaded payloads from archive back to active webhook_outbox';

    public function handle(WebhookRetentionService $retentionService): int
    {
        $chunk = max(10, min(1000, (int) $this->option('chunk')));
        $purge = (bool) $this->option('purge-archive');

        $this->info('Starting payload restoration...');
        $restored = $retentionService->rollbackPayloads($chunk, $purge);
        $this->info("Restored {$restored} webhook payloads to webhook_outbox.");

        return 0;
    }
}
