<?php

declare(strict_types=1);

namespace Acme\Auth\RateLimit;

use Predis\ClientInterface as Redis;

final class LoginAttemptLimiter
{
    private const CAPACITY = 5.0;
    private const REFILL_PER_SECOND = 0.05;

    public function __construct(private readonly Redis $redis)
    {
    }

    public function allowLoginAttempt(string $username, int $now): bool
    {
        $key = "rl:login:{$username}";
        $raw = $this->redis->hmget($key, ['tokens', 'last']);
        $tokens = isset($raw[0]) ? (float) $raw[0] : self::CAPACITY;
        $lastTs = isset($raw[1]) ? (int) $raw[1] : $now;

        $elapsed = max(0, $now - $lastTs);
        $tokens = min(self::CAPACITY, $tokens + ($elapsed * self::REFILL_PER_SECOND));

        if ($tokens < 1.0) {
            $this->redis->hmset($key, ['tokens' => $tokens, 'last' => $now]);
            $this->redis->expire($key, 1800);

            return false;
        }

        $tokens -= 1.0;
        $this->redis->hmset($key, ['tokens' => $tokens, 'last' => $now]);
        $this->redis->expire($key, 1800);

        return true;
    }
}
