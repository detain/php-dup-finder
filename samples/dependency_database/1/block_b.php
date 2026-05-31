<?php
declare(strict_types=1);

namespace CRM\Organizations;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;

final class OrganizationRepository
{
    public function __construct(
        private readonly EntityManager $entityManager
    ) {}

    public function findById(int $id): ?Organization
    {
        return $this->entityManager->find(Organization::class, $id);
    }

    public function findBySlug(string $slug): ?Organization
    {
        return $this->entityManager
            ->getRepository(Organization::class)
            ->findOneBy(['slug' => $slug]);
    }

    public function findAllWithContacts(): array
    {
        return $this->entityManager
            ->getRepository(Organization::class)
            ->createQueryBuilder('o')
            ->leftJoin('o.contacts', 'c')
            ->addSelect('c')
            ->orderBy('o.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function search(string $query): array
    {
        $qb = $this->entityManager->createQueryBuilder();

        return $qb->select('o')
            ->from(Organization::class, 'o')
            ->where($qb->expr()->orX(
                $qb->expr()->like('o.name', ':query'),
                $qb->expr()->like('o.website', ':query'),
                $qb->expr()->like('o.taxId', ':query')
            ))
            ->setParameter('query', '%' . $query . '%')
            ->getQuery()
            ->getResult();
    }

    public function getWithOpenDeals(): array
    {
        return $this->entityManager
            ->getRepository(Organization::class)
            ->createQueryBuilder('o')
            ->leftJoin('o.deals', 'd')
            ->where('d.status = :status')
            ->setParameter('status', 'open')
            ->getQuery()
            ->getResult();
    }

    public function save(Organization $organization): void
    {
        $this->entityManager->persist($organization);
        $this->entityManager->flush();
    }

    public function delete(int $id): bool
    {
        $organization = $this->findById($id);
        if ($organization === null) {
            return false;
        }

        $this->entityManager->remove($organization);
        $this->entityManager->flush();
        return true;
    }

    public function getStatistics(int $organizationId): array
    {
        $qb = $this->entityManager->createQueryBuilder();

        $contactCount = $this->entityManager
            ->getRepository(Contact::class)
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.organization = :orgId')
            ->setParameter('orgId', $organizationId)
            ->getQuery()
            ->getSingleScalarResult();

        $dealValue = $this->entityManager
            ->getRepository(Deal::class)
            ->createQueryBuilder('d')
            ->select('SUM(d.value)')
            ->where('d.organization = :orgId')
            ->andWhere('d.status = :status')
            ->setParameter('orgId', $organizationId)
            ->setParameter('status', 'won')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'contact_count' => (int) $contactCount,
            'total_deal_value' => (float) ($dealValue ?? 0)
        ];
    }
}
