<?php
declare(strict_types=1);

namespace Acme\Webhooks;

use Acme\Webhooks\Exceptions\WebhookRejected;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;

interface WebhookProvider
{
    public function name(): string;
    public function extractSignature(ServerRequestInterface $request): string;
    public function verify(string $payload, string $signature): bool;
    /** @param array<string,mixed> $payload */
    public function eventId(ServerRequestInterface $request, array $payload): string;
    /** @param array<string,mixed> $payload */
    public function topic(ServerRequestInterface $request, array $payload): string;
}

final class WebhookReceiver
{
    public function __construct(
        private readonly IdempotencyStore $store,
        private readonly EventDispatcher $dispatcher,
        private readonly ResponseFactoryInterface $responses,
        private readonly LoggerInterface $log
    ) {
    }

    public function handle(ServerRequestInterface $request, WebhookProvider $provider): ResponseInterface
    {
        $raw = (string)$request->getBody();
        $sig = $provider->extractSignature($request);
        if ($sig === '' || !$provider->verify($raw, $sig)) {
            $this->log->warning("{$provider->name()} signature failed");
            return $this->responses->createResponse(401);
        }
        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new WebhookRejected('Invalid JSON', 0, $e);
        }

        $eventId = $provider->eventId($request, $payload);
        if ($eventId === '') {
            return $this->responses->createResponse(400);
        }
        if ($this->store->seen($provider->name(), $eventId)) {
            $this->log->info("Duplicate {$provider->name()} event {$eventId}");
            return $this->responses->createResponse(200);
        }
        $this->store->mark($provider->name(), $eventId);
        $this->dispatcher->dispatch($provider->name(), $provider->topic($request, $payload), $payload);
        return $this->responses->createResponse(200);
    }
}
