<?php
declare(strict_types=1);

namespace Billing\Core\State;

use Psr\Log\LoggerInterface;

final class DistributedStateManager
{
    private const DEFAULT_TTL = 3600;

    public function __construct(
        private readonly StateStore $database,
        private readonly StateStore $cache,
        private readonly StateStore $cookie,
        private readonly ?LoggerInterface $logger = null
    ) {}

    public function get(string $key): ?array
    {
        // Try each store in order of speed
        $value = $this->cookie->get($key);
        if ($value !== null) {
            return $value;
        }

        $value = $this->cache->get($key);
        if ($value !== null) {
            return $value;
        }

        $value = $this->database->get($key);
        if ($value !== null) {
            // Repopulate faster stores
            $this->repopulateCache($key, $value);
            return $value;
        }

        return null;
    }

    public function set(string $key, array $value, int $ttl = self::DEFAULT_TTL): void
    {
        // Write to all stores
        $this->database->set($key, $value, $ttl);
        $this->cache->set($key, $value, min($ttl, 7200));
        $this->cookie->set($key, $value, $ttl);
    }

    public function invalidate(string $key): void
    {
        $this->database->delete($key);
        $this->cache->delete($key);
        $this->cookie->delete($key);
    }

    private function repopulateCache(string $key, array $value): void
    {
        try {
            $this->cache->set($key, $value, 300);
        } catch (\Exception $e) {
            $this->logger?->warning('Failed to repopulate cache', ['key' => $key]);
        }
    }
}
