<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'whsec_stripe_test_abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.stripe.webhook_secret', $this->secret);
    }

    public function testValidSignatureIsAccepted(): void
    {
        $payload   = json_encode(['id' => 'evt_1', 'type' => 'payment_intent.succeeded']);
        $timestamp = time();
        $signed    = "{$timestamp}.{$payload}";
        $signature = "t={$timestamp},v1=" . hash_hmac('sha256', $signed, $this->secret);

        $response = $this->call(
            'POST',
            '/webhooks/stripe',
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload
        );

        $response->assertStatus(200);
        $response->assertJson(['received' => true]);
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $payload   = json_encode(['id' => 'evt_x', 'type' => 'payment_intent.succeeded']);
        $signature = 't=1,v1=deadbeef';

        $response = $this->call(
            'POST',
            '/webhooks/stripe',
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload
        );

        $response->assertStatus(401);
    }
}
