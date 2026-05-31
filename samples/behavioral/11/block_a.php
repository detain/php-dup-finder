<?php
declare(strict_types=1);

namespace App\User\Service;

use App\User\Repository\UserRepository;
use App\User\Entity\User;
use Psr\Log\LoggerInterface;

final class UserActiveStatusService
{
    private UserRepository $userRepository;
    private LoggerInterface $logger;

    public function __construct(
        UserRepository $userRepository,
        LoggerInterface $logger
    ) {
        $this->userRepository = $userRepository;
        $this->logger = $logger;
    }

    public function isUserActive(string $userId): bool
    {
        $user = $this->userRepository->findById($userId);

        if ($user === null) {
            $this->logger->warning('User not found for active status check', ['user_id' => $userId]);
            return false;
        }

        if ($user->getStatus() !== User::STATUS_ACTIVE) {
            $this->logger->debug('User status is not active', [
                'user_id' => $userId,
                'status' => $user->getStatus()
            ]);
            return false;
        }

        if ($user->isSuspended()) {
            return false;
        }

        if ($user->isDeleted()) {
            return false;
        }

        return true;
    }

    public function getActiveUserCount(): int
    {
        return $this->userRepository->countByStatus(User::STATUS_ACTIVE);
    }

    public function getInactiveUsers(int $daysSinceActivity = 30): array
    {
        $cutoffDate = (new \DateTimeImmutable())->modify("-{$daysSinceActivity} days");

        return $this->userRepository->findUsersNotActiveSince($cutoffDate);
    }

    public function canUserAccess(string $userId): AccessResult
    {
        $user = $this->userRepository->findById($userId);

        if ($user === null) {
            return new AccessResult(false, 'User not found');
        }

        if (!$user->isActive()) {
            if ($user->isSuspended()) {
                return new AccessResult(false, 'Account is suspended');
            }

            if ($user->isDeleted()) {
                return new AccessResult(false, 'Account has been deleted');
            }

            return new AccessResult(false, 'Account is not active');
        }

        if ($user->isLocked()) {
            $lockExpiry = $user->getLockedUntil();
            if ($lockExpiry !== null && $lockExpiry > new \DateTimeImmutable()) {
                return new AccessResult(false, 'Account is temporarily locked');
            }
        }

        return new AccessResult(true, 'Access granted');
    }
}
