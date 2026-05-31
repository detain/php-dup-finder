<?php
declare(strict_types=1);

namespace Catalog\Reads;

use Psr\SimpleCache\CacheInterface;
use Psr\Log\LoggerInterface;

final class CacheAside
{
    public function __construct(private CacheInterface $cache, private LoggerInterface $log) {}

    /**
     * @template T
     * @param callable():T $loader
     * @return T
     */
    public function cached(string $key, int $ttlSeconds, callable $loader)
    {
        $hit = $this->cache->get($key);
        if ($hit !== null) {
            $this->log->debug('cache.hit', ['key' => $key]);
            return $hit;
        }
        $value = $loader();
        $this->cache->set($key, $value, $ttlSeconds);
        $this->log->debug('cache.miss.stored', ['key' => $key]);
        return $value;
    }
}

final class ProductReader
{
    public function __construct(private CacheAside $cache, private ProductRepository $repo) {}

    public function find(string $sku): array
    {
        return $this->cache->cached("product:v3:{$sku}", 3600, function () use ($sku) {
            $row = $this->repo->bySku($sku) ?? throw new \DomainException("unknown sku {$sku}");
            return [
                'sku'         => $row['sku'],
                'name'        => $row['name'],
                'brand'       => $row['brand'],
                'description' => $row['description'],
                'attributes'  => json_decode((string) $row['attributes_json'], true) ?? [],
            ];
        });
    }
}
