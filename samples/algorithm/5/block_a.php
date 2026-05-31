<?php

declare(strict_types=1);

namespace Acme\Catalog\Repository;

use Acme\Catalog\Dto\Product;
use Acme\Catalog\Dto\ProductPage;
use PDO;

final class ProductRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function listAfter(?string $cursor, int $limit = 20): ProductPage
    {
        $lastCreated = '1970-01-01 00:00:00';
        $lastId = 0;

        if ($cursor !== null && $cursor !== '') {
            $decoded = base64_decode($cursor, true);
            if ($decoded === false) {
                throw new \InvalidArgumentException('Invalid cursor');
            }
            [$lastCreated, $lastId] = explode('|', $decoded, 2);
            $lastId = (int) $lastId;
        }

        $sql = 'SELECT id, sku, name, created_at FROM products
                WHERE (created_at, id) > (:created, :id)
                ORDER BY created_at ASC, id ASC
                LIMIT :limit';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':created', $lastCreated);
        $stmt->bindValue(':id', $lastId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $items = array_map(
            static fn(array $r): Product => new Product((int) $r['id'], $r['sku'], $r['name'], $r['created_at']),
            $rows,
        );

        $nextCursor = null;
        if (count($items) === $limit) {
            $tail = end($rows);
            $nextCursor = base64_encode($tail['created_at'] . '|' . $tail['id']);
        }

        return new ProductPage($items, $nextCursor);
    }
}
