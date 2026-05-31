<?php
declare(strict_types=1);

namespace Acme\Webhooks\Stripe;

use Acme\Webhooks\IdempotencyStore;
use Acme\Webhooks\EventDispatcher;
use Acme\Webhooks\Exceptions\WebhookRejected;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;

final class StripeWebhookController
{
    public function __construct(
        private readonly IdempotencyStore $store,
        private readonly EventDispatcher $dispatcher,
        private readonly ResponseFactoryInterface $responses,
        private readonly LoggerInterface $log,
        private readonly string $secret
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $raw = (string)$request->getBody();
        $sig = $request->getHeaderLine('Stripe-Signature');
        if ($sig === '' || !$this->verify($raw, $sig)) {
            $this->log->warning('Stripe signature failed');
            return $this->responses->createResponse(401);
        }

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new WebhookRejected('Invalid JSON', 0, $e);
        }

        $eventId = (string)($payload['id'] ?? '');
        if ($eventId === '') {
            return $this->responses->createResponse(400);
        }
        if ($this->store->seen('stripe', $eventId)) {
            $this->log->info("Duplicate stripe event {$eventId}");
            return $this->responses->createResponse(200);
        }
        $this->store->mark('stripe', $eventId);

        $this->dispatcher->dispatch('stripe', (string)$payload['type'], $payload['data'] ?? []);
        return $this->responses->createResponse(200);
    }

    private function verify(string $payload, string $header): bool
    {
        $expected = hash_hmac('sha256', $payload, $this->secret);
        return hash_equals($expected, $header);
    }
}
