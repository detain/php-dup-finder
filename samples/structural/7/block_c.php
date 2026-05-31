<?php

declare(strict_types=1);

namespace Acme\Assets\Query;

use Doctrine\DBAL\Connection;
use Acme\Assets\Dto\AssetDto;

final class AssetSearchQuery
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param array{q?: string, kind?: string, location?: string, limit?: int, offset?: int} $filters
     * @return array<int, AssetDto>
     */
    public function search(array $filters): array
    {
        $qb = $this->db->createQueryBuilder();
        $qb->select('id', 'asset_tag', 'description', 'kind', 'location', 'acquired_at')
            ->from('asset')
            ->orderBy('acquired_at', 'DESC');

        if (!empty($filters['q'])) {
            $qb->andWhere('(description LIKE :q OR asset_tag LIKE :q)')
                ->setParameter('q', '%' . $filters['q'] . '%');
        }
        if (!empty($filters['kind'])) {
            $qb->andWhere('kind = :kind')->setParameter('kind', $filters['kind']);
        }
        if (!empty($filters['location'])) {
            $qb->andWhere('location = :location')->setParameter('location', $filters['location']);
        }

        $qb->setMaxResults($filters['limit'] ?? 50);
        $qb->setFirstResult($filters['offset'] ?? 0);

        $rows = $qb->executeQuery()->fetchAllAssociative();

        $out = [];
        foreach ($rows as $r) {
            $out[] = new AssetDto(
                (int) $r['id'],
                (string) $r['asset_tag'],
                (string) $r['description'],
                (string) $r['kind'],
                (string) $r['location'],
                new \DateTimeImmutable($r['acquired_at']),
            );
        }

        return $out;
    }
}
