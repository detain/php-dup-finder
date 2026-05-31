<?php
declare(strict_types=1);

namespace Catalog\Reads\Inventory;

use Psr\SimpleCache\CacheInterface;
use Psr\Log\LoggerInterface;

final class InventoryReader
{
    public function __construct(
        private CacheInterface $cache,
        private InventoryRepository $repo,
        private WarehouseRouter $router,
        private LoggerInterface $log,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function availability(string $sku, string $region): array
    {
        $key = "stock:v4:{$sku}:{$region}";
        $cached = $this->cache->get($key);
        if ($cached !== null) {
            $this->log->debug('stock.cache.hit', ['sku' => $sku, 'region' => $region]);
            return $cached;
        }
        $warehouses = $this->router->warehousesFor($region);
        if ($warehouses === []) {
            throw new \DomainException("no warehouse for {$region}");
        }
        $total = 0;
        foreach ($warehouses as $w) {
            $total += $this->repo->stock($sku, $w);
        }
        $payload = [
            'sku'        => $sku,
            'region'     => $region,
            'available'  => $total,
            'in_stock'   => $total > 0,
            'observed_at'=> date(DATE_ATOM),
        ];
        $this->cache->set($key, $payload, 120);
        $this->log->debug('stock.cache.miss.stored', ['sku' => $sku, 'region' => $region]);
        return $payload;
    }
}
