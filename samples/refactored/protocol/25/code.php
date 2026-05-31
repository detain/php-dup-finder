<?php
declare(strict_types=1);

namespace App\Services\Security;

use App\Configuration\ConfigManager;
use App\Logging\LoggerInterface;
use Redis;

final class RateLimiter
{
    private Redis $redis;
    private LoggerInterface $logger;
    private int $maxAttempts;
    private int $windowSeconds;
    private string $prefix;

    public function __construct(
        string $prefix,
        int $maxAttempts,
        int $windowSeconds,
        ConfigManager $config,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->prefix = $prefix;
        $this->maxAttempts = $maxAttempts;
        $this->windowSeconds = $windowSeconds;
        
        $this->redis = new Redis();
        $this->redis->connect($config->get('redis.host'), $config->get('redis.port'));
    }

    public function isAllowed(string $identifier): bool
    {
        $key = $this->buildKey($identifier);
        
        try {
            $currentCount = (int)$this->redis->get($key);
            
            if ($currentCount >= $this->maxAttempts) {
                $this->logger->warning("{$this->prefix} rate limit exceeded", [
                    'identifier' => $identifier,
                    'count' => $currentCount,
                    'max' => $this->maxAttempts,
                ]);
                return false;
            }
            
            $this->redis->incr($key);
            
            if ($currentCount === 0) {
                $this->redis->expire($key, $this->windowSeconds);
            }
            
            $this->logger->debug("{$this->prefix} rate limit check passed", [
                'identifier' => $identifier,
                'count' => $currentCount + 1,
                'max' => $this->maxAttempts,
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error('Rate limit check failed', [
                'error' => $e->getMessage(),
            ]);
            return true;
        }
    }

    public function getRemainingAttempts(string $identifier): int
    {
        $key = $this->buildKey($identifier);
        
        try {
            $currentCount = (int)$this->redis->get($key);
            return max(0, $this->maxAttempts - $currentCount);
        } catch (\Exception $e) {
            return $this->maxAttempts;
        }
    }

    public function getResetTime(string $identifier): int
    {
        $key = $this->buildKey($identifier);
        
        try {
            $ttl = $this->redis->ttl($key);
            return $ttl > 0 ? time() + $ttl : 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    public function reset(string $identifier): void
    {
        $key = $this->buildKey($identifier);
        
        try {
            $this->redis->del($key);
            $this->logger->info('Rate limit reset', ['identifier' => $identifier]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to reset rate limit', [
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildKey(string $identifier): string
    {
        return "rate_limit:{$this->prefix}:{$identifier}";
    }
}
