<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Repository;

use App\Domain\Entity\User;
use App\Domain\Repository\UserRepositoryInterface;
use App\Infrastructure\Persistence\Doctrine\Mapper\UserMapper;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
final class DoctrineUserRepository extends ServiceEntityRepository implements UserRepositoryInterface
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly UserMapper $userMapper,
    ) {
        parent::__construct($registry, User::class);
    }

    public function findById(string $id): ?User
    {
        $this->logQuery(__METHOD__, ['id' => $id]);
        $result = $this->createQueryBuilder('u')
            ->andWhere('u.id = :id')
            ->andWhere('u.deletedAt IS NULL')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if ($result === null) {
            $this->logNotFound('user', $id);
            return null;
        }

        return $this->userMapper->toDomain($result);
    }

    public function findByEmail(string $email): ?User
    {
        $this->logQuery(__METHOD__, ['email' => $email]);
        $result = $this->createQueryBuilder('u')
            ->andWhere('u.email = :email')
            ->andWhere('u.deletedAt IS NULL')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();

        if ($result === null) {
            $this->logNotFound('user', $email);
            return null;
        }

        return $this->userMapper->toDomain($result);
    }

    public function save(User $user): void
    {
        $this->logQuery(__METHOD__, ['userId' => $user->getId()]);
        $entity = $this->userMapper->toEntity($user);
        $this->getEntityManager()->persist($entity);
        $this->getEntityManager()->flush();
        $this->invalidateCache($user->getId());
    }

    private function logQuery(string $method, array $params): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            "INSERT INTO query_log (method, params, executed_at) VALUES (?, ?, NOW())",
            [json_encode($params), $method]
        );
    }

    private function logNotFound(string $entity, string $identifier): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            "INSERT INTO not_found_log (entity_type, identifier, searched_at) VALUES (?, ?, NOW())",
            [$entity, $identifier]
        );
    }

    private function invalidateCache(string $userId): void
    {
        $this->getEntityManager()->getCache()->evict("user_{$userId}");
    }
}
