<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Hydrator;

use App\Domain\Entity\User;
use Doctrine\DBAL\Result;
use App\Infrastructure\Persistence\Doctrine\Types\UlidType;

final readonly class UserHydrator
{
    public function __construct(
        private UserFactory $factory,
    ) {}

    public function hydrateOne(Result $result): ?User
    {
        $row = $result->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return $this->hydrateRow($row);
    }

    public function hydrateAll(Result $result): array
    {
        $users = [];
        while (($row = $result->fetchAssociative()) !== false) {
            $users[] = $this->hydrateRow($row);
        }

        return $users;
    }

    private function hydrateRow(array $row): User
    {
        $user = $this->factory->create();
        $user->setId($this->factory->createUlid($row['id']));
        $user->setEmail($row['email']);
        $user->setPasswordHash($row['password_hash']);
        $user->setFirstName($row['first_name']);
        $user->setLastName($row['last_name']);
        $user->setDisplayName($row['display_name']);
        $user->setPhone($row['phone']);
        $user->setAvatarUrl($row['avatar_url']);
        $user->setStatus($row['status']);
        $user->setEmailVerified((bool)$row['email_verified']);
        $user->setTwoFactorEnabled((bool)$row['two_factor_enabled']);
        $user->setTwoFactorSecret($row['two_factor_secret']);
        $user->setCreatedAt(new \DateTimeImmutable($row['created_at']));
        $user->setUpdatedAt(new \DateTimeImmutable($row['updated_at']));
        $user->setLastLoginAt($row['last_login_at'] ? new \DateTimeImmutable($row['last_login_at']) : null);
        $user->setDeletedAt($row['deleted_at'] ? new \DateTimeImmutable($row['deleted_at']) : null);

        if (isset($row['metadata'])) {
            $user->setMetadata(json_decode($row['metadata'], true));
        }

        return $user;
    }
}
