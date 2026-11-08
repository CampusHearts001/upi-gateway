<?php

namespace Tests\Feature;

use Tests\TestCase;

class DeveloperDocumentationTest extends TestCase
{
    public function test_documentation_matches_the_public_api_and_security_contract(): void
    {
        $response = $this->get('/docs');

        $response->assertOk()
            ->assertSee('/api/v1/orders', false)
            ->assertSee('/api/v1/orders/{order_id | client_order_id}', false)
            ->assertSee('/api/v1/accounts', false)
            ->assertSee('Live API authentication')
            ->assertSee('Sandbox API authentication')
            ->assertSee('test_key_placeholder')
            ->assertSee('&quot;environment&quot;: &quot;live&quot;', false)
            ->assertSee('&quot;test&quot;: false', false)
            ->assertSee('X-Signature')
            ->assertSee('X-Event-Id')
            ->assertSee('event_sequence')
            ->assertSee('Queue insertion is not merchant receipt')
            ->assertSee('order.under_review')
            ->assertSee('order.timeout')
            ->assertSee('PAYMENT_WEBHOOK_SECRET')
            ->assertSee('https://gateway.example.com/api/v1/orders', false)
            ->assertDontSee('key_xxx')
            ->assertDontSee('sec_xxx')
            ->assertDontSee('cdnjs.cloudflare.com', false);
    }
}
