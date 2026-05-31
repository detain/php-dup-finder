<?php

declare(strict_types=1);

namespace Acme\Integrations\Retry;

use Psr\Log\LoggerInterface;
use Throwable;

final class ExponentialBackoffRunner
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int $maxAttempts,
        private readonly int $baseDelayMs,
        private readonly float $factor,
        private readonly float $jitterRatio,
        private readonly string $retryableException,
        private readonly string $channel,
    ) {
    }

    /**
     * @template T
     * @param callable():T $operation
     * @return T
     */
    public function run(callable $operation): mixed
    {
        $attempt = 0;
        while (true) {
            try {
                return $operation();
            } catch (Throwable $e) {
                if (!$e instanceof $this->retryableException) {
                    throw $e;
                }

                $attempt++;
                if ($attempt >= $this->maxAttempts) {
                    $this->logger->error("{$this->channel}.gave_up", ['attempts' => $attempt]);
                    throw $e;
                }

                $delay = (int) ($this->baseDelayMs * ($this->factor ** ($attempt - 1)));
                $jitter = random_int(0, (int) ($delay * $this->jitterRatio));
                $sleepMs = $delay + $jitter;

                $this->logger->warning("{$this->channel}.backoff", [
                    'attempt' => $attempt,
                    'sleep_ms' => $sleepMs,
                ]);

                usleep($sleepMs * 1000);
            }
        }
    }
}
