<?php

declare(strict_types=1);

namespace App\Cache;

use Psr\Log\LoggerInterface;

interface CacheBackendInterface
{
    public function get(string $key): mixed;
    public function set(string $key, mixed $value, int $ttl = 3600): void;
    public function delete(string $key): void;
    public function clear(): void;
    public function has(string $key): bool;
    public function prune(): int;
}

abstract class AbstractCacheService implements CacheBackendInterface
{
    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {}

    public function get(string $key): mixed
    {
        if (!$this->has($key)) {
            $this->logger->debug('Cache miss', ['key' => $key]);
            return null;
        }

        $this->logger->debug('Cache hit', ['key' => $key]);
        return $this->doGet($key);
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        $this->doSet($key, $value, $ttl);
        $this->logger->debug('Cache set', ['key' => $key, 'ttl' => $ttl]);
    }

    public function delete(string $key): void
    {
        $this->doDelete($key);
        $this->logger->debug('Cache delete', ['key' => $key]);
    }

    public function has(string $key): bool
    {
        return $this->doHas($key);
    }

    public function prune(): int
    {
        return $this->doPrune();
    }

    abstract protected function doGet(string $key): mixed;
    abstract protected function doSet(string $key, mixed $value, int $ttl): void;
    abstract protected function doDelete(string $key): void;
    abstract protected function doHas(string $key): bool;
    abstract protected function doPrune(): int;
}

final class CacheOrchestrator
{
    /** @var CacheBackendInterface[] */
    private array $backends = [];

    public function registerBackend(CacheBackendInterface $backend): void
    {
        $this->backends[] = $backend;
    }

    public function get(string $key): mixed
    {
        foreach ($this->backends as $backend) {
            $value = $backend->get($key);
            if ($value !== null) {
                return $value;
            }
        }
        return null;
    }
}
