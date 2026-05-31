<?php
declare(strict_types=1);

namespace Billing\Core\Database;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;

/**
 * Base repository class providing common database access.
 *
 * All application repositories extend this class to receive
 * EntityManager via autowiring, eliminating constructor bloat.
 *
 * @template T
 * @extends EntityRepository<T>
 */
abstract class AbstractRepository extends EntityRepository
{
    public function save(mixed $entity, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(mixed $entity, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entity);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findById(int $id): ?object
    {
        return $this->find($id);
    }

    public function findByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return $this->createQueryBuilder('e')
            ->where('e.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    protected function searchByFields(array $fields, string $query, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('e');
        $orX = $qb->expr()->orX();

        foreach ($fields as $field) {
            $orX->add($qb->expr()->like("e.{$field}", ':query'));
        }

        return $qb->where($orX)
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}

// Example usage:
// class ContactRepository extends AbstractRepository { ... }
// class OrganizationRepository extends AbstractRepository { ... }
