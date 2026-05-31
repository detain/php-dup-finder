<?php

declare(strict_types=1);

namespace Acme\Common\RateLimit;

use Predis\ClientInterface as Redis;

final class TokenBucketLimiter
{
    public function __construct(
        private readonly Redis $redis,
        private readonly string $keyPrefix,
        private readonly float $capacity,
        private readonly float $refillPerSecond,
        private readonly int $ttlSeconds,
    ) {
    }

    public function admit(string $identifier, int $now): bool
    {
        $key = $this->keyPrefix . $identifier;
        $raw = $this->redis->hmget($key, ['tokens', 'last']);
        $tokens = isset($raw[0]) ? (float) $raw[0] : $this->capacity;
        $lastTs = isset($raw[1]) ? (int) $raw[1] : $now;

        $elapsed = max(0, $now - $lastTs);
        $tokens = min($this->capacity, $tokens + ($elapsed * $this->refillPerSecond));

        $granted = $tokens >= 1.0;
        if ($granted) {
            $tokens -= 1.0;
        }

        $this->redis->hmset($key, ['tokens' => $tokens, 'last' => $now]);
        $this->redis->expire($key, $this->ttlSeconds);

        return $granted;
    }
}
