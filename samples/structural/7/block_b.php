<?php

declare(strict_types=1);

namespace Acme\Procurement\Query;

use Doctrine\DBAL\Connection;
use Acme\Procurement\Dto\VendorDto;

final class VendorSearchQuery
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param array{q?: string, tier?: string, region?: string, limit?: int, offset?: int} $filters
     * @return array<int, VendorDto>
     */
    public function search(array $filters): array
    {
        $qb = $this->db->createQueryBuilder();
        $qb->select('id', 'tax_id', 'company_name', 'tier', 'region', 'onboarded_at')
            ->from('vendor')
            ->orderBy('onboarded_at', 'DESC');

        if (!empty($filters['q'])) {
            $qb->andWhere('(company_name LIKE :q OR tax_id LIKE :q)')
                ->setParameter('q', '%' . $filters['q'] . '%');
        }
        if (!empty($filters['tier'])) {
            $qb->andWhere('tier = :tier')->setParameter('tier', $filters['tier']);
        }
        if (!empty($filters['region'])) {
            $qb->andWhere('region = :region')->setParameter('region', $filters['region']);
        }

        $qb->setMaxResults($filters['limit'] ?? 50);
        $qb->setFirstResult($filters['offset'] ?? 0);

        $rows = $qb->executeQuery()->fetchAllAssociative();

        $out = [];
        foreach ($rows as $r) {
            $out[] = new VendorDto(
                (int) $r['id'],
                (string) $r['tax_id'],
                (string) $r['company_name'],
                (string) $r['tier'],
                (string) $r['region'],
                new \DateTimeImmutable($r['onboarded_at']),
            );
        }

        return $out;
    }
}
