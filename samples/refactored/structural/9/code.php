<?php

declare(strict_types=1);

namespace Acme\Common\Dispatch;

use Acme\Metrics\Counter;
use Psr\Log\LoggerInterface;

/**
 * @template TInput
 * @template TOutput
 */
final class MeteredDispatcher
{
    /**
     * @param array<string, callable(TInput): TOutput> $handlers
     * @param callable(string): TOutput $notFound
     * @param callable(\Throwable, string): TOutput $errorResponse
     */
    public function __construct(
        private readonly array $handlers,
        private readonly Counter $counter,
        private readonly LoggerInterface $logger,
        private readonly string $metricNamespace,
        /** @var callable(string): TOutput */
        private $notFound,
        /** @var callable(\Throwable, string): TOutput */
        private $errorResponse,
    ) {
    }

    /**
     * @param TInput $input
     * @return TOutput
     */
    public function dispatch(string $key, mixed $input): mixed
    {
        $handler = $this->handlers[$key] ?? null;
        if ($handler === null) {
            $this->counter->inc("{$this->metricNamespace}.unknown", ['key' => $key]);
            return ($this->notFound)($key);
        }

        $start = microtime(true);
        try {
            $out = $handler($input);
            $ms = (int) ((microtime(true) - $start) * 1000);
            $this->counter->inc("{$this->metricNamespace}.success", ['key' => $key]);
            $this->counter->observe("{$this->metricNamespace}.duration_ms", $ms, ['key' => $key]);
            $this->logger->info("{$this->metricNamespace} handled", ['key' => $key, 'ms' => $ms]);
            return $out;
        } catch (\Throwable $e) {
            $this->counter->inc("{$this->metricNamespace}.error", ['key' => $key]);
            $this->logger->error("{$this->metricNamespace} error", ['key' => $key, 'error' => $e->getMessage()]);
            return ($this->errorResponse)($e, $key);
        }
    }
}
