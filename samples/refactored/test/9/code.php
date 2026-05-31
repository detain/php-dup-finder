<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class AbstractWebhookTestCase extends TestCase
{
    use RefreshDatabase;

    abstract protected function endpoint(): string;
    abstract protected function configKey(): string;
    abstract protected function headerName(): string;
    abstract protected function secret(): string;
    abstract protected function signPayload(string $payload): string;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set($this->configKey(), $this->secret());
    }

    protected function postWebhook(string $payload, string $signature): TestResponse
    {
        return $this->call(
            'POST',
            $this->endpoint(),
            [],
            [],
            [],
            [$this->headerName() => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload
        );
    }

    public function testValidSignatureIsAccepted(): void
    {
        $payload = json_encode(['evt' => 'ok', 'ts' => time()]);
        $this->postWebhook($payload, $this->signPayload($payload))
            ->assertStatus(200)
            ->assertJson(['received' => true]);
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $payload = json_encode(['evt' => 'evil']);
        $this->postWebhook($payload, 'tampered-signature')->assertStatus(401);
    }
}
