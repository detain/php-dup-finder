<?php
declare(strict_types=1);

namespace CRM\Contacts;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;

final class ContactRepository
{
    public function __construct(
        private readonly EntityManager $entityManager
    ) {}

    public function findById(int $id): ?Contact
    {
        return $this->entityManager->find(Contact::class, $id);
    }

    public function findByEmail(string $email): ?Contact
    {
        return $this->entityManager
            ->getRepository(Contact::class)
            ->findOneBy(['email' => strtolower($email)]);
    }

    public function findByOrganization(int $organizationId): array
    {
        return $this->entityManager
            ->getRepository(Contact::class)
            ->findBy(['organization' => $organizationId], ['lastName' => 'ASC']);
    }

    public function search(string $query): array
    {
        $qb = $this->entityManager->createQueryBuilder();

        return $qb->select('c')
            ->from(Contact::class, 'c')
            ->where($qb->expr()->orX(
                $qb->expr()->like('c.firstName', ':query'),
                $qb->expr()->like('c.lastName', ':query'),
                $qb->expr()->like('c.email', ':query'),
                $qb->expr()->like('c.company', ':query')
            ))
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();
    }

    public function getRecentlyUpdated(int $days = 7): array
    {
        $cutoff = new \DateTimeImmutable("-{$days} days");

        return $this->entityManager
            ->getRepository(Contact::class)
            ->createQueryBuilder('c')
            ->where('c.updatedAt >= :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->orderBy('c.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function save(Contact $contact): void
    {
        $this->entityManager->persist($contact);
        $this->entityManager->flush();
    }

    public function delete(int $id): bool
    {
        $contact = $this->findById($id);
        if ($contact === null) {
            return false;
        }

        $this->entityManager->remove($contact);
        $this->entityManager->flush();
        return true;
    }

    public function countByOrganization(int $organizationId): int
    {
        return (int) $this->entityManager
            ->getRepository(Contact::class)
            ->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.organization = :orgId')
            ->setParameter('orgId', $organizationId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
