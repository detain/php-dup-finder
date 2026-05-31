<?php

declare(strict_types=1);

namespace App\Services\Throttling;

use Illuminate\Support\Facades\Cache;

abstract class AbstractRateLimiter
{
    protected const WINDOW_SECONDS = 60;
    protected const CACHE_PREFIX = 'rate_limit:';

    protected abstract function getDefaultLimit(): int;
    protected abstract function getTierLimits(): array;
    protected abstract function shouldBypass(string $identifier): bool;

    public function check(string $identifier, string $resource = ''): array
    {
        $limit = $this->getLimitFor($identifier);
        $key = $this->buildKey($identifier, $resource);

        $current = (int) Cache::get($key, 0);
        $remaining = max(0, $limit - $current);

        return [
            'allowed' => $current < $limit,
            'limit' => $limit,
            'remaining' => $remaining,
            'reset' => time() + self::WINDOW_SECONDS,
        ];
    }

    public function attempt(string $identifier, string $resource, callable $callback): mixed
    {
        $result = $this->check($identifier, $resource);

        if (!$result['allowed']) {
            return null;
        }

        $this->hit($identifier, $resource);
        return $callback();
    }

    protected function hit(string $identifier, string $resource): void
    {
        $key = $this->buildKey($identifier, $resource);
        $exists = Cache::has($key);

        if ($exists) {
            Cache::increment($key);
        } else {
            Cache::put($key, 1, self::WINDOW_SECONDS);
        }
    }

    protected function buildKey(string $identifier, string $resource): string
    {
        return self::CACHE_PREFIX . $identifier . ':' . md5($resource);
    }

    protected function getLimitFor(string $identifier): int
    {
        if ($this->shouldBypass($identifier)) {
            return PHP_INT_MAX;
        }

        $tier = $this->determineTier($identifier);
        return $this->getTierLimits()[$tier] ?? $this->getDefaultLimit();
    }

    abstract protected function determineTier(string $identifier): int;
}
