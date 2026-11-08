<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WebhookArchiveMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_archived_tables_are_created_with_expected_schema(): void
    {
        $this->assertTrue(Schema::hasTable('archived_webhook_logs'));
        $this->assertTrue(Schema::hasColumns('archived_webhook_logs', [
            'id', 'order_id', 'event', 'event_id', 'url', 'payload',
            'response_code', 'response_body', 'success', 'attempt',
            'error_label', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasTable('archived_webhook_payloads'));
        $this->assertTrue(Schema::hasColumns('archived_webhook_payloads', [
            'id', 'event_id', 'order_id', 'payload', 'archived_at',
        ]));
    }
}
