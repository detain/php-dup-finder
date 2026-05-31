<?php

declare(strict_types=1);

namespace App\Cache\Memory;

use App\Service\CacheBackendInterface;
use Psr\Log\LoggerInterface;

final class MemoryCacheService implements CacheBackendInterface
{
    private array $cache = [];
    private array $expiry = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function get(string $key): mixed
    {
        if (!isset($this->cache[$key])) {
            $this->logger->debug('Memory cache miss', ['key' => $key]);
            return null;
        }

        if (isset($this->expiry[$key]) && $this->expiry[$key] < time()) {
            unset($this->cache[$key], $this->expiry[$key]);
            $this->logger->debug('Memory cache expired', ['key' => $key]);
            return null;
        }

        $this->logger->debug('Memory cache hit', ['key' => $key]);
        return $this->cache[$key];
    }

    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        $this->cache[$key] = $value;
        $this->expiry[$key] = time() + $ttl;

        $this->logger->debug('Memory cache set', [
            'key' => $key,
            'ttl' => $ttl,
        ]);
    }

    public function delete(string $key): void
    {
        unset($this->cache[$key], $this->expiry[$key]);
        $this->logger->debug('Memory cache delete', ['key' => $key]);
    }

    public function clear(): void
    {
        $this->cache = [];
        $this->expiry = [];
        $this->logger->info('Memory cache cleared');
    }

    public function has(string $key): bool
    {
        if (!isset($this->cache[$key])) {
            return false;
        }

        if (isset($this->expiry[$key]) && $this->expiry[$key] < time()) {
            unset($this->cache[$key], $this->expiry[$key]);
            return false;
        }

        return true;
    }

    public function prune(): int
    {
        $now = time();
        $pruned = 0;

        foreach ($this->expiry as $key => $expiryTime) {
            if ($expiryTime < $now) {
                unset($this->cache[$key], $this->expiry[$key]);
                $pruned++;
            }
        }

        $this->logger->debug('Memory cache pruned', ['count' => $pruned]);

        return $pruned;
    }
}
