<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class GitHubWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'gh_secret_xyz';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.github.webhook_secret', $this->secret);
    }

    public function testValidSignatureIsAccepted(): void
    {
        $payload   = json_encode(['ref' => 'refs/heads/main', 'pusher' => ['name' => 'octocat']]);
        $signature = 'sha256=' . hash_hmac('sha256', $payload, $this->secret);

        $response = $this->call(
            'POST',
            '/webhooks/github',
            [],
            [],
            [],
            ['HTTP_X_HUB_SIGNATURE_256' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload
        );

        $response->assertStatus(200);
        $response->assertJson(['received' => true]);
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $payload   = json_encode(['ref' => 'refs/heads/evil']);
        $signature = 'sha256=deadbeef';

        $response = $this->call(
            'POST',
            '/webhooks/github',
            [],
            [],
            [],
            ['HTTP_X_HUB_SIGNATURE_256' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload
        );

        $response->assertStatus(401);
    }
}
