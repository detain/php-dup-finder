<?php
declare(strict_types=1);

namespace Acme\Catalog;

use Psr\SimpleCache\CacheInterface;
use Psr\Log\LoggerInterface;

final class ProductRepository
{
    public function __construct(
        private \PDO $db,
        private CacheInterface $cache,
        private LoggerInterface $log,
    ) {}

    public function find(int $id): ?array
    {
        $key = 'acme:v3:product:' . $id;
        $raw = $this->cache->get($key);

        if ($raw !== null) {
            $this->log->debug('cache.hit', ['key' => $key]);
            return igbinary_unserialize($raw);
        }

        $stmt = $this->db->prepare('SELECT * FROM products WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $product = [
            'id'    => (int) $row['id'],
            'sku'   => (string) $row['sku'],
            'name'  => (string) $row['name'],
            'price' => (float) $row['price'],
        ];
        $this->cache->set($key, igbinary_serialize($product), 900);
        $this->log->debug('cache.set', ['key' => $key, 'ttl' => 900]);

        return $product;
    }
}
