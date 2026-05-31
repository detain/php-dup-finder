<?php
declare(strict_types=1);

namespace Catalog\Reads\Product;

use Psr\SimpleCache\CacheInterface;
use Psr\Log\LoggerInterface;

final class ProductReader
{
    public function __construct(
        private CacheInterface $cache,
        private ProductRepository $repo,
        private LoggerInterface $log,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function find(string $sku): array
    {
        $key = "product:v3:{$sku}";
        $cached = $this->cache->get($key);
        if ($cached !== null) {
            $this->log->debug('product.cache.hit', ['sku' => $sku]);
            return $cached;
        }
        $row = $this->repo->bySku($sku);
        if ($row === null) {
            throw new \DomainException("unknown sku {$sku}");
        }
        $payload = [
            'sku'         => $row['sku'],
            'name'        => $row['name'],
            'brand'       => $row['brand'],
            'description' => $row['description'],
            'attributes'  => json_decode((string) $row['attributes_json'], true) ?? [],
        ];
        $this->cache->set($key, $payload, 3600);
        $this->log->debug('product.cache.miss.stored', ['sku' => $sku]);
        return $payload;
    }
}
