<?php

namespace App\Console\Commands;

use App\Services\WebhookRetentionService;
use Illuminate\Console\Command;

class PruneWebhookLogsCommand extends Command
{
    protected $signature = 'webhooks:prune-logs {--chunk=100}';

    protected $description = 'Archives historical webhook_logs for fully settled orders past retention cutoff';

    public function handle(WebhookRetentionService $retentionService): int
    {
        $chunk = max(10, min(1000, (int) $this->option('chunk')));
        $count = $retentionService->pruneEligibleLogs($chunk);

        $this->info("Archived {$count} webhook logs.");

        return 0;
    }
}
