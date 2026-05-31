<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class ShopifyWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'shopify_secret_123';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.shopify.webhook_secret', $this->secret);
    }

    public function testValidSignatureIsAccepted(): void
    {
        $payload   = json_encode(['order_id' => 9000, 'topic' => 'orders/create']);
        $signature = base64_encode(hash_hmac('sha256', $payload, $this->secret, true));

        $response = $this->call(
            'POST',
            '/webhooks/shopify',
            [],
            [],
            [],
            ['HTTP_X_SHOPIFY_HMAC_SHA256' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload
        );

        $response->assertStatus(200);
        $response->assertJson(['received' => true]);
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $payload   = json_encode(['order_id' => 1, 'topic' => 'orders/evil']);
        $signature = base64_encode('not-a-real-signature');

        $response = $this->call(
            'POST',
            '/webhooks/shopify',
            [],
            [],
            [],
            ['HTTP_X_SHOPIFY_HMAC_SHA256' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload
        );

        $response->assertStatus(401);
    }
}
