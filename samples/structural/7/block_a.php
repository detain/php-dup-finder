<?php

declare(strict_types=1);

namespace Acme\Crm\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Acme\Crm\Dto\CustomerDto;

final class CustomerSearchQuery
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param array{q?: string, status?: string, country?: string, limit?: int, offset?: int} $filters
     * @return array<int, CustomerDto>
     */
    public function search(array $filters): array
    {
        $qb = $this->db->createQueryBuilder();
        $qb->select('id', 'email', 'display_name', 'status', 'country', 'created_at')
            ->from('customer')
            ->orderBy('created_at', 'DESC');

        if (!empty($filters['q'])) {
            $qb->andWhere('(display_name LIKE :q OR email LIKE :q)')
                ->setParameter('q', '%' . $filters['q'] . '%');
        }
        if (!empty($filters['status'])) {
            $qb->andWhere('status = :status')->setParameter('status', $filters['status']);
        }
        if (!empty($filters['country'])) {
            $qb->andWhere('country = :country')->setParameter('country', $filters['country']);
        }

        $qb->setMaxResults($filters['limit'] ?? 50);
        $qb->setFirstResult($filters['offset'] ?? 0);

        $rows = $qb->executeQuery()->fetchAllAssociative();

        $out = [];
        foreach ($rows as $r) {
            $out[] = new CustomerDto(
                (int) $r['id'],
                (string) $r['email'],
                (string) $r['display_name'],
                (string) $r['status'],
                (string) $r['country'],
                new \DateTimeImmutable($r['created_at']),
            );
        }

        return $out;
    }
}
