<?php
declare(strict_types=1);

namespace Acme\Webhooks\Quickbooks;

use Acme\Webhooks\IdempotencyStore;
use Acme\Webhooks\EventDispatcher;
use Acme\Webhooks\Exceptions\WebhookRejected;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;

final class QuickbooksWebhookController
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
        $sig = $request->getHeaderLine('intuit-signature');
        if ($sig === '' || !$this->verify($raw, $sig)) {
            $this->log->warning('QuickBooks signature failed');
            return $this->responses->createResponse(401);
        }

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new WebhookRejected('Invalid JSON', 0, $e);
        }

        $eventId = (string)($payload['eventNotifications'][0]['realmId'] ?? '') . ':' . ($payload['eventNotifications'][0]['timestamp'] ?? '');
        if ($eventId === ':') {
            return $this->responses->createResponse(400);
        }
        if ($this->store->seen('quickbooks', $eventId)) {
            $this->log->info("Duplicate qb event {$eventId}");
            return $this->responses->createResponse(200);
        }
        $this->store->mark('quickbooks', $eventId);

        $this->dispatcher->dispatch('quickbooks', 'data-change', $payload['eventNotifications'] ?? []);
        return $this->responses->createResponse(200);
    }

    private function verify(string $payload, string $header): bool
    {
        $expected = base64_encode(hash_hmac('sha256', $payload, $this->secret, true));
        return hash_equals($expected, $header);
    }
}
