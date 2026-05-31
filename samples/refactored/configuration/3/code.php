<?php
declare(strict_types=1);

namespace Acme\Cache;

use Psr\SimpleCache\CacheInterface;

final class CacheProfile
{
    public const KEY_PREFIX = 'acme:v3:';
    public const TTL        = 900;

    public function __construct(private CacheInterface $cache) {}

    public function remember(string $bucket, int|string $id, callable $loader): mixed
    {
        $key = self::KEY_PREFIX . $bucket . ':' . $id;
        $raw = $this->cache->get($key);

        if ($raw !== null) {
            return igbinary_unserialize($raw);
        }

        $value = $loader();
        if ($value !== null) {
            $this->cache->set($key, igbinary_serialize($value), self::TTL);
        }

        return $value;
    }
}

// Usage inside ProductRepository::find($id):
// return $this->profile->remember('product', $id, function () use ($id): ?array {
//     $stmt = $this->db->prepare('SELECT * FROM products WHERE id = :id');
//     $stmt->execute(['id' => $id]);
//     $row = $stmt->fetch(\PDO::FETCH_ASSOC);
//     return $row === false ? null : [...];
// });
