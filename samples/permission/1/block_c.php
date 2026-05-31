<?php
declare(strict_types=1);

namespace App\Security\Authorization;

use App\Domain\Entity\User;
use App\Domain\Repository\TeamRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class FeatureAccessService
{
    public function __construct(
        private TeamRepositoryInterface $teamRepository,
        private LoggerInterface $logger,
    ) {}

    public function canAccessAnalytics(User $user): bool
    {
        if ($user === null) {
            $this->logger->warning('Analytics access check failed: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Analytics access check failed: user not active', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $hasAnalyticsPermission = false;
        foreach ($user->getRoles() as $role) {
            if ($role->hasPermission('analytics', 'read')) {
                $hasAnalyticsPermission = true;
                break;
            }
        }

        if (!$hasAnalyticsPermission) {
            $this->logger->info('Analytics access check failed: no analytics permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Analytics access granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }

    public function canExportData(User $user): bool
    {
        if ($user === null) {
            $this->logger->warning('Export access check failed: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Export access check failed: user not active', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $hasExportPermission = false;
        foreach ($user->getRoles() as $role) {
            if ($role->hasPermission('data', 'export')) {
                $hasExportPermission = true;
                break;
            }
        }

        if (!$hasExportPermission) {
            $this->logger->info('Export access check failed: no export permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Export access granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }

    public function canManageTeam(User $user): bool
    {
        if ($user === null) {
            $this->logger->warning('Team management access check failed: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Team management access check failed: user not active', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $hasTeamPermission = false;
        foreach ($user->getRoles() as $role) {
            if ($role->hasPermission('team', 'manage')) {
                $hasTeamPermission = true;
                break;
            }
        }

        if (!$hasTeamPermission) {
            $this->logger->info('Team management access check failed: no team permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Team management access granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }
}
