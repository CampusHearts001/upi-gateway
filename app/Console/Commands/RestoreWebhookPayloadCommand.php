<?php

namespace App\Console\Commands;

use App\Services\WebhookRetentionService;
use Illuminate\Console\Command;

class RestoreWebhookPayloadCommand extends Command
{
    protected $signature = 'webhooks:restore-payload {eventId}';

    protected $description = 'Restores an archived payload to active webhook_outbox without modifying status';

    public function handle(WebhookRetentionService $retentionService): int
    {
        $eventId = (string) $this->argument('eventId');
        $success = $retentionService->restorePayload($eventId);

        if (! $success) {
            $this->error("Failed to restore payload for event {$eventId}. Either active payload already exists or archive record was not found.");

            return 1;
        }

        $this->info("Successfully restored payload for event {$eventId}. Canonical status remains unchanged.");

        return 0;
    }
}
