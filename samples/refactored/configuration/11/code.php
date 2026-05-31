<?php

declare(strict_types=1);

namespace App\Infrastructure\Configuration;

use App\Attributes\Configuration;

#[Configuration('redis', 'Cache')]
final class RedisConfiguration
{
    public function __construct(
        public readonly int $timeout = 3600,
        public readonly int $retries = 3,
        public readonly int $retryDelay = 100,
        public readonly int $poolSize = 32,
        public readonly string $prefix = 'cache:',
        public readonly string $compression = 'gzip'
    ) {}
}

#[Configuration('http_client', 'HTTP')]
final class HttpClientConfiguration
{
    public function __construct(
        public readonly int $requestTimeout = 30,
        public readonly int $connectTimeout = 10,
        public readonly int $maxRetries = 3,
        public readonly int $retryDelay = 200,
        public readonly int $poolConnections = 20,
        public readonly int $keepAlive = 60
    ) {}
}

#[Configuration('message_queue', 'Queue')]
final class MessageQueueConfiguration
{
    public function __construct(
        public readonly float $connectionTimeout = 3.0,
        public readonly int $frameSize = 131072,
        public readonly int $heartbeat = 60,
        public readonly int $maxRetries = 3,
        public readonly int $retryDelay = 150,
        public readonly int $prefetchCount = 10,
        public readonly int $poolSize = 8
    ) {}
}

trait RetryableOperation
{
    private int $attempts = 0;

    protected function executeWithRetry(callable $operation, callable $onFailure): mixed
    {
        $config = $this->getRetryConfiguration();

        while ($this->attempts < $config->maxRetries) {
            try {
                $this->attempts++;
                return $operation();
            } catch (\Throwable $e) {
                if ($this->attempts >= $config->maxRetries) {
                    return $onFailure($e);
                }

                usleep($config->retryDelay * 1000 * $this->attempts);
            }
        }

        throw new \RuntimeException('Max retries exceeded');
    }

    abstract protected function getRetryConfiguration(): HttpClientConfiguration|RedisConfiguration|MessageQueueConfiguration;
}
