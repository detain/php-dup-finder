<?php
declare(strict_types=1);

namespace Acme\Webhooks\Shopify;

use Acme\Webhooks\IdempotencyStore;
use Acme\Webhooks\EventDispatcher;
use Acme\Webhooks\Exceptions\WebhookRejected;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;

final class ShopifyWebhookController
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
        $sig = $request->getHeaderLine('X-Shopify-Hmac-SHA256');
        if ($sig === '' || !$this->verify($raw, $sig)) {
            $this->log->warning('Shopify signature failed');
            return $this->responses->createResponse(401);
        }

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new WebhookRejected('Invalid JSON', 0, $e);
        }

        $eventId = $request->getHeaderLine('X-Shopify-Webhook-Id');
        if ($eventId === '') {
            return $this->responses->createResponse(400);
        }
        if ($this->store->seen('shopify', $eventId)) {
            $this->log->info("Duplicate shopify event {$eventId}");
            return $this->responses->createResponse(200);
        }
        $this->store->mark('shopify', $eventId);

        $topic = $request->getHeaderLine('X-Shopify-Topic');
        $this->dispatcher->dispatch('shopify', $topic, $payload);
        return $this->responses->createResponse(200);
    }

    private function verify(string $payload, string $header): bool
    {
        $expected = base64_encode(hash_hmac('sha256', $payload, $this->secret, true));
        return hash_equals($expected, $header);
    }
}
