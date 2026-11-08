<?php

namespace App\Console\Commands;

use App\Services\WebhookRetentionService;
use Illuminate\Console\Command;

class OffloadWebhookPayloadsCommand extends Command
{
    protected $signature = 'webhooks:offload-payloads {--chunk=100}';

    protected $description = 'Offloads delivered webhook outbox payloads to archive table, preserving canonical skeletons';

    public function handle(WebhookRetentionService $retentionService): int
    {
        $chunk = max(10, min(1000, (int) $this->option('chunk')));
        $count = $retentionService->offloadDeliveredPayloads($chunk);

        $this->info("Offloaded {$count} outbox payloads.");

        return 0;
    }
}
