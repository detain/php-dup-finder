<?php

declare(strict_types=1);

namespace App\Infrastructure\Configuration;

use App\Attributes\Configuration;

#[Configuration('connection_pool')]
final class ConnectionPoolConfig
{
    public function __construct(
        public readonly int $minSize = 5,
        public readonly int $maxSize = 20,
        public readonly int $acquireTimeout = 5,
        public readonly int $idleTimeout = 600,
        public readonly int $connectionTimeout = 10,
        public readonly int $commandTimeout = 30,
        public readonly int $retryAttempts = 3,
        public readonly int $retryDelay = 100,
    ) {}
}

#[Configuration('http_client')]
final class HttpClientConfig
{
    public function __construct(
        public readonly int $clientTimeout = 30,
        public readonly int $connectTimeout = 10,
        public readonly int $maxRetries = 3,
        public readonly int $retryDelay = 200,
        public readonly int $poolSize = 10,
        public readonly int $keepAlive = 60,
    ) {}
}

trait HasConnectionPooling
{
    protected abstract function getPoolConfig(): ConnectionPoolConfig|HttpClientConfig;

    protected function executeWithPoolRetry(callable $operation): mixed
    {
        $config = $this->getPoolConfig();
        $attempts = 0;

        while ($attempts < $config->retryAttempts) {
            try {
                return $operation();
            } catch (\Throwable $e) {
                $attempts++;
                if ($attempts >= $config->retryAttempts) {
                    throw $e;
                }
                usleep($config->retryDelay * 1000 * $attempts);
            }
        }
    }
}
