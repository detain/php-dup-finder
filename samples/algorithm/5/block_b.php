<?php

declare(strict_types=1);

namespace Acme\Orders\Repository;

use Acme\Orders\Dto\Order;
use Acme\Orders\Dto\OrderPage;
use PDO;

final class OrderRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function listAfter(?string $cursor, int $limit = 50): OrderPage
    {
        $lastPlaced = '1970-01-01 00:00:00';
        $lastOrderId = 0;

        if ($cursor !== null && $cursor !== '') {
            $decoded = base64_decode($cursor, true);
            if ($decoded === false) {
                throw new \InvalidArgumentException('Bad cursor');
            }
            [$lastPlaced, $lastOrderId] = explode('|', $decoded, 2);
            $lastOrderId = (int) $lastOrderId;
        }

        $sql = 'SELECT id, customer_id, total_cents, placed_at FROM orders
                WHERE (placed_at, id) > (:placed, :id)
                ORDER BY placed_at ASC, id ASC
                LIMIT :limit';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':placed', $lastPlaced);
        $stmt->bindValue(':id', $lastOrderId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $items = array_map(
            static fn(array $r): Order => new Order((int) $r['id'], (int) $r['customer_id'], (int) $r['total_cents'], $r['placed_at']),
            $rows,
        );

        $nextCursor = null;
        if (count($items) === $limit) {
            $tail = end($rows);
            $nextCursor = base64_encode($tail['placed_at'] . '|' . $tail['id']);
        }

        return new OrderPage($items, $nextCursor);
    }
}
