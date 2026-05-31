<?php
declare(strict_types=1);

namespace App\Services\Cache;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use Redis;

final class ApiResponseCache
{
    private Redis $redis;
    private LoggerInterface $logger;
    private int $defaultTtl;
    private string $keyPrefix;

    public function __construct(
        string $keyPrefix,
        ConfigManager $config,
        LoggerInterface $logger,
        int $defaultTtl = 3600
    ) {
        $this->keyPrefix = $keyPrefix;
        $this->defaultTtl = $defaultTtl;
        $this->logger = $logger;
        
        $this->redis = new Redis();
        $this->redis->connect($config->get('redis.host'), $config->get('redis.port'));
    }

    public function get(string $key): ?array
    {
        $cacheKey = $this->buildKey($key);
        
        try {
            $cached = $this->redis->get($cacheKey);
            
            if ($cached === false) {
                $this->logger->debug('Cache miss', [
                    'prefix' => $this->keyPrefix,
                    'key' => $key,
                ]);
                return null;
            }
            
            $this->logger->debug('Cache hit', [
                'prefix' => $this->keyPrefix,
                'key' => $key,
            ]);
            return json_decode($cached, true);
            
        } catch (\Exception $e) {
            $this->logger->error('Cache get error', [
                'prefix' => $this->keyPrefix,
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function set(string $key, array $data, ?int $ttl = null): bool
    {
        $cacheKey = $this->buildKey($key);
        $ttl = $ttl ?? $this->defaultTtl;
        
        try {
            $serialized = json_encode($data);
            $result = $this->redis->setex($cacheKey, $ttl, $serialized);
            
            $this->logger->debug('Cache set', [
                'prefix' => $this->keyPrefix,
                'key' => $key,
                'ttl' => $ttl,
            ]);
            
            return $result === true;
            
        } catch (\Exception $e) {
            $this->logger->error('Cache set error', [
                'prefix' => $this->keyPrefix,
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function invalidate(string $key): bool
    {
        $cacheKey = $this->buildKey($key);
        
        try {
            $result = $this->redis->del($cacheKey);
            
            $this->logger->info('Cache invalidated', [
                'prefix' => $this->keyPrefix,
                'key' => $key,
            ]);
            
            return $result > 0;
            
        } catch (\Exception $e) {
            $this->logger->error('Cache invalidation error', [
                'prefix' => $this->keyPrefix,
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function invalidatePattern(string $pattern): int
    {
        $searchPattern = $this->keyPrefix . $pattern;
        
        try {
            $keys = $this->redis->keys($searchPattern);
            
            if (empty($keys)) {
                return 0;
            }
            
            $count = $this->redis->del($keys);
            
            $this->logger->info('Cache pattern invalidated', [
                'prefix' => $this->keyPrefix,
                'pattern' => $pattern,
                'count' => $count,
            ]);
            
            return $count;
            
        } catch (\Exception $e) {
            $this->logger->error('Cache pattern invalidation error', [
                'prefix' => $this->keyPrefix,
                'pattern' => $pattern,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    private function buildKey(string $key): string
    {
        return $this->keyPrefix . $key;
    }
}
