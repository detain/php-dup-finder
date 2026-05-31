<?php
declare(strict_types=1);

namespace App\Security\Authorization;

use App\Domain\Entity\User;
use App\Domain\Repository\UserRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class AdminAccessService
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private LoggerInterface $logger,
    ) {}

    public function checkAdmin(User $user): bool
    {
        if ($user === null) {
            $this->logger->warning('Admin access check failed: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Admin access check failed: user not active', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $hasAdminRole = false;
        foreach ($user->getRoles() as $role) {
            if ($role->isAdmin()) {
                $hasAdminRole = true;
                break;
            }
        }

        if (!$hasAdminRole) {
            $this->logger->info('Admin access check failed: no admin role', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Admin access granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }

    public function checkSuperAdmin(User $user): bool
    {
        if ($user === null) {
            $this->logger->warning('SuperAdmin access check failed: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('SuperAdmin access check failed: user not active', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $hasSuperAdminRole = false;
        foreach ($user->getRoles() as $role) {
            if ($role->isSuperAdmin()) {
                $hasSuperAdminRole = true;
                break;
            }
        }

        if (!$hasSuperAdminRole) {
            $this->logger->info('SuperAdmin access check failed: no super admin role', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('SuperAdmin access granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }

    public function checkModerator(User $user): bool
    {
        if ($user === null) {
            $this->logger->warning('Moderator access check failed: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Moderator access check failed: user not active', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $hasModeratorRole = false;
        foreach ($user->getRoles() as $role) {
            if ($role->isModerator()) {
                $hasModeratorRole = true;
                break;
            }
        }

        if (!$hasModeratorRole) {
            $this->logger->info('Moderator access check failed: no moderator role', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Moderator access granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }
}
