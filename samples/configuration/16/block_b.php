<?php

declare(strict_types=1);

namespace App\Services\Cache;

use Illuminate\Support\Facades\Redis;
use Psr\Log\LoggerInterface;
use Memcached;

final class DistributedCacheService
{
    private const CACHE_TTL_DEFAULT = 3600;
    private const CACHE_TTL_SHORT = 300;
    private const CACHE_TTL_MEDIUM = 1800;
    private const CACHE_TTL_LONG = 7200;
    private const CACHE_TTL_PERMANENT = 0;
    private const CACHE_PREFIX = 'app:';
    private const CACHE_VERSION = 'v1';
    private const CACHE_LOCK_TIMEOUT = 10;
    private const CACHE_LOCK_WAIT = 500;
    private const CACHE_COMPRESSION = true;
    private const CACHE_COMPRESSION_THRESHOLD = 1024;

    private string $prefix;
    private ?Memcached $memcached = null;

    public function __construct(
        private readonly LoggerInterface $logger,
        string $host = '127.0.0.1',
        int $port = 11211
    ) {
        $this->prefix = self::CACHE_PREFIX . self::CACHE_VERSION . ':';

        $this->memcached = new Memcached();
        $this->memcached->addServer($host, $port);
        $this->memcached->setOption(Memcached::OPT_COMPRESSION, self::CACHE_COMPRESSION);
        $this->memcached->setOption(Memcached::OPT_COMPRESSION_THRESHOLD, self::CACHE_COMPRESSION_THRESHOLD);
        $this->memcached->setOption(Memcached::OPT_SERIALIZER, Memcached::SERIALIZER_JSON);
        $this->memcached->setOption(Memcached::OPT_BINARY_PROTOCOL, true);
        $this->memcached->setOption(Memcached::OPT_TCP_NODELAY, true);
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $fullKey = $this->buildKey($key);

        $cached = $this->get($fullKey);
        if ($cached !== null) {
            $this->logger->debug('Cache hit', [
                'key' => $fullKey,
                'ttl' => $ttl ?? self::CACHE_TTL_DEFAULT,
                'compression' => self::CACHE_COMPRESSION,
            ]);
            return $cached;
        }

        $value = $callback();

        $this->set($fullKey, $value, $ttl ?? self::CACHE_TTL_DEFAULT);

        $this->logger->debug('Cache miss, value stored', [
            'key' => $fullKey,
            'ttl' => $ttl ?? self::CACHE_TTL_DEFAULT,
        ]);

        return $value;
    }

    public function get(string $key): mixed
    {
        $fullKey = $this->buildKey($key);

        try {
            $value = $this->memcached->get($fullKey);

            if ($this->memcached->getResultCode() === Memcached::RES_NOTFOUND) {
                return null;
            }

            return $value;
        } catch (\Exception $e) {
            $this->logger->error('Cache get failed', [
                'key' => $fullKey,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $fullKey = $this->buildKey($key);
        $ttl = $ttl ?? self::CACHE_TTL_DEFAULT;

        if ($ttl === self::CACHE_TTL_PERMANENT) {
            $ttl = 0;
        }

        try {
            $result = $this->memcached->set($fullKey, $value, $ttl);

            $this->logger->debug('Cache set', [
                'key' => $fullKey,
                'ttl' => $ttl,
                'result' => $result,
            ]);

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Cache set failed', [
                'key' => $fullKey,
                'ttl' => $ttl,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function lock(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $fullKey = $this->buildKey($key);
        $lockKey = $fullKey . ':lock';
        $ttl = $ttl ?? self::CACHE_LOCK_TIMEOUT;

        $lockWait = 0;

        while ($this->memcached->add($lockKey, 1, $ttl) === false) {
            if ($lockWait >= self::CACHE_LOCK_WAIT) {
                throw new \RuntimeException('Could not acquire cache lock');
            }

            usleep(50000);
            $lockWait += 50;
        }

        try {
            return $callback();
        } finally {
            $this->memcached->delete($lockKey);
        }
    }

    public function forget(string $key): bool
    {
        $fullKey = $this->buildKey($key);

        try {
            return $this->memcached->delete($fullKey);
        } catch (\Exception $e) {
            $this->logger->error('Cache forget failed', [
                'key' => $fullKey,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function flush(): bool
    {
        try {
            return $this->memcached->flush();
        } catch (\Exception $e) {
            $this->logger->error('Cache flush failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function buildKey(string $key): string
    {
        return $this->prefix . $key;
    }

    public function getTtlShort(): int
    {
        return self::CACHE_TTL_SHORT;
    }

    public function getTtlMedium(): int
    {
        return self::CACHE_TTL_MEDIUM;
    }

    public function getTtlLong(): int
    {
        return self::CACHE_TTL_LONG;
    }
}
