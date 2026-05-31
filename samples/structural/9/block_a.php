<?php

declare(strict_types=1);

namespace Acme\Webhooks\Dispatch;

use Acme\Webhooks\HandlerInterface;
use Acme\Webhooks\WebhookResponse;
use Acme\Metrics\Counter;
use Psr\Log\LoggerInterface;

final class WebhookDispatcher
{
    /** @param array<string, HandlerInterface> $handlers */
    public function __construct(
        private readonly array $handlers,
        private readonly Counter $counter,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function dispatch(string $eventType, array $payload): WebhookResponse
    {
        $handler = $this->handlers[$eventType] ?? null;
        if ($handler === null) {
            $this->counter->inc('webhook.unknown', ['type' => $eventType]);
            return new WebhookResponse(404, ['error' => "no handler for {$eventType}"]);
        }

        $start = microtime(true);
        try {
            $result = $handler->handle($payload);
            $elapsedMs = (int) ((microtime(true) - $start) * 1000);
            $this->counter->inc('webhook.success', ['type' => $eventType]);
            $this->counter->observe('webhook.duration_ms', $elapsedMs, ['type' => $eventType]);
            $this->logger->info('webhook handled', ['type' => $eventType, 'ms' => $elapsedMs]);

            return new WebhookResponse(200, $result);
        } catch (\Throwable $e) {
            $this->counter->inc('webhook.error', ['type' => $eventType]);
            $this->logger->error('webhook error', ['type' => $eventType, 'error' => $e->getMessage()]);
            return new WebhookResponse(500, ['error' => $e->getMessage()]);
        }
    }
}
