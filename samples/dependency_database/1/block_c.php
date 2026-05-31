<?php
declare(strict_types=1);

namespace CRM\Deals;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;

final class DealRepository
{
    public function __construct(
        private readonly EntityManager $entityManager
    ) {}

    public function findById(int $id): ?Deal
    {
        return $this->entityManager->find(Deal::class, $id);
    }

    public function findByOrganization(int $organizationId, string $status = null): array
    {
        $qb = $this->entityManager
            ->getRepository(Deal::class)
            ->createQueryBuilder('d')
            ->where('d.organization = :orgId')
            ->setParameter('orgId', $organizationId)
            ->orderBy('d.createdAt', 'DESC');

        if ($status !== null) {
            $qb->andWhere('d.status = :status')
                ->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    public function findStaleDeals(int $days = 30): array
    {
        $cutoff = new \DateTimeImmutable("-{$days} days");

        return $this->entityManager
            ->getRepository(Deal::class)
            ->createQueryBuilder('d')
            ->where('d.lastActivityAt < :cutoff')
            ->andWhere('d.status = :status')
            ->setParameter('cutoff', $cutoff)
            ->setParameter('status', 'open')
            ->getQuery()
            ->getResult();
    }

    public function findWonByDateRange(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        return $this->entityManager
            ->getRepository(Deal::class)
            ->createQueryBuilder('d')
            ->where('d.status = :status')
            ->andWhere('d.wonAt >= :start')
            ->andWhere('d.wonAt <= :end')
            ->setParameter('status', 'won')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('d.wonAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function getTotalPipelineValue(): int
    {
        return (int) $this->entityManager
            ->getRepository(Deal::class)
            ->createQueryBuilder('d')
            ->select('SUM(d.value)')
            ->where('d.status = :status')
            ->setParameter('status', 'open')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function getSalespersonPerformance(int $userId, int $quarter): array
    {
        $startDate = new \DateTimeImmutable("{$quarter}-01-01");
        $endDate = $startDate->modify('+3 months');

        $deals = $this->entityManager
            ->getRepository(Deal::class)
            ->createQueryBuilder('d')
            ->where('d.ownerUser = :userId')
            ->andWhere('d.status = :status')
            ->andWhere('d.wonAt >= :start')
            ->andWhere('d.wonAt < :end')
            ->setParameter('userId', $userId)
            ->setParameter('status', 'won')
            ->setParameter('start', $startDate)
            ->setParameter('end', $endDate)
            ->getQuery()
            ->getResult();

        $totalValue = 0;
        $count = count($deals);

        foreach ($deals as $deal) {
            $totalValue += $deal->getValue();
        }

        return [
            'deals_won' => $count,
            'total_value' => $totalValue,
            'average_value' => $count > 0 ? $totalValue / $count : 0
        ];
    }

    public function save(Deal $deal): void
    {
        $this->entityManager->persist($deal);
        $this->entityManager->flush();
    }

    public function delete(int $id): bool
    {
        $deal = $this->findById($id);
        if ($deal === null) {
            return false;
        }

        $this->entityManager->remove($deal);
        $this->entityManager->flush();
        return true;
    }

    public function closeAsWon(int $dealId): bool
    {
        $deal = $this->findById($dealId);
        if ($deal === null) {
            return false;
        }

        $deal->setStatus('won');
        $deal->setWonAt(new \DateTimeImmutable());
        $deal->setLastActivityAt(new \DateTimeImmutable());

        $this->entityManager->flush();
        return true;
    }

    public function closeAsLost(int $dealId, string $lostReason): bool
    {
        $deal = $this->findById($dealId);
        if ($deal === null) {
            return false;
        }

        $deal->setStatus('lost');
        $deal->setLostReason($lostReason);
        $deal->setLostAt(new \DateTimeImmutable());
        $deal->setLastActivityAt(new \DateTimeImmutable());

        $this->entityManager->flush();
        return true;
    }
}
