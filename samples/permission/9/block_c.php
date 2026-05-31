<?php
declare(strict_types=1);

namespace App\FeatureFlags\Security;

use App\Domain\Entity\User;
use Psr\Log\LoggerInterface;

final readonly class FeatureFlagAuditPermissionService
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function canViewFlagAuditLog(User $user, string $flagId): bool
    {
        if ($user === null) {
            $this->logger->warning('Flag audit log view permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Flag audit log view permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'flag_id' => $flagId,
            ]);
            return false;
        }

        if (!$user->hasPermission('feature_flags', 'view_audit')) {
            $this->logger->info('Flag audit log view permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Flag audit log view permission granted', [
            'user_id' => $user->getId()->toString(),
            'flag_id' => $flagId,
        ]);

        return true;
    }

    public function canViewFlagEvaluationLogs(User $user, string $flagId): bool
    {
        if ($user === null) {
            $this->logger->warning('Flag evaluation logs view permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Flag evaluation logs view permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'flag_id' => $flagId,
            ]);
            return false;
        }

        if (!$user->hasPermission('feature_flags', 'view_evaluations')) {
            $this->logger->info('Flag evaluation logs view permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Flag evaluation logs view permission granted', [
            'user_id' => $user->getId()->toString(),
            'flag_id' => $flagId,
        ]);

        return true;
    }

    public function canExportFlagMetrics(User $user, string $flagId): bool
    {
        if ($user === null) {
            $this->logger->warning('Flag metrics export permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Flag metrics export permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'flag_id' => $flagId,
            ]);
            return false;
        }

        if (!$user->hasPermission('feature_flags', 'export_metrics')) {
            $this->logger->info('Flag metrics export permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Flag metrics export permission granted', [
            'user_id' => $user->getId()->toString(),
            'flag_id' => $flagId,
        ]);

        return true;
    }

    public function canViewFlagDependencies(User $user, string $flagId): bool
    {
        if ($user === null) {
            $this->logger->warning('Flag dependencies view permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Flag dependencies view permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'flag_id' => $flagId,
            ]);
            return false;
        }

        if (!$user->hasPermission('feature_flags', 'view_dependencies')) {
            $this->logger->info('Flag dependencies view permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Flag dependencies view permission granted', [
            'user_id' => $user->getId()->toString(),
            'flag_id' => $flagId,
        ]);

        return true;
    }

    public function canViewFlagSchedules(User $user, string $flagId): bool
    {
        if ($user === null) {
            $this->logger->warning('Flag schedules view permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Flag schedules view permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'flag_id' => $flagId,
            ]);
            return false;
        }

        if (!$user->hasPermission('feature_flags', 'view_schedules')) {
            $this->logger->info('Flag schedules view permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Flag schedules view permission granted', [
            'user_id' => $user->getId()->toString(),
            'flag_id' => $flagId,
        ]);

        return true;
    }
}
