<?php
declare(strict_types=1);

namespace Acme\Catalog;

use Psr\SimpleCache\CacheInterface;
use Psr\Log\LoggerInterface;

final class BrandRepository
{
    public function __construct(
        private \PDO $db,
        private CacheInterface $cache,
        private LoggerInterface $log,
    ) {}

    public function find(int $id): ?array
    {
        $key = 'acme:v3:brand:' . $id;
        $raw = $this->cache->get($key);

        if ($raw !== null) {
            $this->log->debug('cache.hit', ['key' => $key]);
            return igbinary_unserialize($raw);
        }

        $stmt = $this->db->prepare('SELECT * FROM brands WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $brand = [
            'id'      => (int) $row['id'],
            'slug'    => (string) $row['slug'],
            'name'    => (string) $row['name'],
            'website' => (string) $row['website'],
        ];
        $this->cache->set($key, igbinary_serialize($brand), 900);
        $this->log->debug('cache.set', ['key' => $key, 'ttl' => 900]);

        return $brand;
    }
}
