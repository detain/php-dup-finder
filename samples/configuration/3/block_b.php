<?php
declare(strict_types=1);

namespace Acme\Catalog;

use Psr\SimpleCache\CacheInterface;
use Psr\Log\LoggerInterface;

final class CategoryRepository
{
    public function __construct(
        private \PDO $db,
        private CacheInterface $cache,
        private LoggerInterface $log,
    ) {}

    public function find(int $id): ?array
    {
        $key = 'acme:v3:category:' . $id;
        $raw = $this->cache->get($key);

        if ($raw !== null) {
            $this->log->debug('cache.hit', ['key' => $key]);
            return igbinary_unserialize($raw);
        }

        $stmt = $this->db->prepare('SELECT * FROM categories WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $category = [
            'id'     => (int) $row['id'],
            'slug'   => (string) $row['slug'],
            'name'   => (string) $row['name'],
            'parent' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
        ];
        $this->cache->set($key, igbinary_serialize($category), 900);
        $this->log->debug('cache.set', ['key' => $key, 'ttl' => 900]);

        return $category;
    }
}
