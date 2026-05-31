<?php
declare(strict_types=1);

namespace App\Services\Cache;

use App\Logging\LoggerInterface;
use App\Configuration\ConfigManager;
use Redis;

final class ProductCatalogCache
{
    private Redis $redis;
    private LoggerInterface $logger;
    private int $defaultTtl = 3600;
    private string $keyPrefix = 'cache:product:';

    public function __construct(
        ConfigManager $config,
        LoggerInterface $logger
    ) {
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
                $this->logger->debug('Product cache miss', ['key' => $key]);
                return null;
            }
            
            $this->logger->debug('Product cache hit', ['key' => $key]);
            return json_decode($cached, true);
            
        } catch (\Exception $e) {
            $this->logger->error('Product cache get error', [
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
            
            $this->logger->debug('Product cache set', [
                'key' => $key,
                'ttl' => $ttl,
            ]);
            
            return $result === true;
            
        } catch (\Exception $e) {
            $this->logger->error('Product cache set error', [
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
            
            $this->logger->info('Product cache invalidated', ['key' => $key]);
            
            return $result > 0;
            
        } catch (\Exception $e) {
            $this->logger->error('Product cache invalidation error', [
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
            
            $this->logger->info('Product cache pattern invalidated', [
                'pattern' => $pattern,
                'count' => $count,
            ]);
            
            return $count;
            
        } catch (\Exception $e) {
            $this->logger->error('Product cache pattern invalidation error', [
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
