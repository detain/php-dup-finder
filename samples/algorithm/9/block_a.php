<?php

declare(strict_types=1);

namespace Acme\PublicApi\RateLimit;

use Predis\ClientInterface as Redis;

final class ApiRequestLimiter
{
    private const CAPACITY = 60.0;
    private const REFILL_PER_SECOND = 1.0;

    public function __construct(private readonly Redis $redis)
    {
    }

    public function admit(string $apiKey, int $now): bool
    {
        $key = "rl:api:{$apiKey}";
        $raw = $this->redis->hmget($key, ['tokens', 'last']);
        $tokens = isset($raw[0]) ? (float) $raw[0] : self::CAPACITY;
        $lastTs = isset($raw[1]) ? (int) $raw[1] : $now;

        $elapsed = max(0, $now - $lastTs);
        $tokens = min(self::CAPACITY, $tokens + ($elapsed * self::REFILL_PER_SECOND));

        if ($tokens < 1.0) {
            $this->redis->hmset($key, ['tokens' => $tokens, 'last' => $now]);
            $this->redis->expire($key, 3600);

            return false;
        }

        $tokens -= 1.0;
        $this->redis->hmset($key, ['tokens' => $tokens, 'last' => $now]);
        $this->redis->expire($key, 3600);

        return true;
    }
}
