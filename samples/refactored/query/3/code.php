<?php
declare(strict_types=1);

namespace App\Order\Read;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Query\QueryBuilder;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class OrderJoinQueryFactory
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger
    ) {
    }

    public function baseQuery(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'o.id AS order_id',
                'o.placed_at',
                'o.total_cents',
                'oi.id AS item_id',
                'oi.quantity',
                'p.id AS product_id',
                'p.sku',
                'p.name AS product_name'
            )
            ->from('orders', 'o')
            ->innerJoin('o', 'order_items', 'oi', 'oi.order_id = o.id')
            ->innerJoin('oi', 'products', 'p', 'p.id = oi.product_id')
            ->andWhere('o.deleted_at IS NULL');
    }

    /**
     * @param array<string, scalar|\DateTimeInterface> $params
     * @return array<int, array<string, mixed>>
     */
    public function run(QueryBuilder $qb, array $params, string $context): array
    {
        try {
            return $qb->setParameters($params)->executeQuery()->fetchAllAssociative();
        } catch (DbalException $e) {
            $this->logger->error("{$context} query failed", [
                'params' => $params,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException("Unable to load {$context}", 0, $e);
        }
    }
}
