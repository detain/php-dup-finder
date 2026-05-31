<?php
declare(strict_types=1);

namespace App\FeatureFlags\Security;

use App\Domain\Entity\User;
use Psr\Log\LoggerInterface;

final readonly class FeatureFlagTargetingPermissionService
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function canUpdateTargetingRules(User $user, string $flagId): bool
    {
        if ($user === null) {
            $this->logger->warning('Targeting rules update permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Targeting rules update permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'flag_id' => $flagId,
            ]);
            return false;
        }

        if (!$user->hasPermission('feature_flags', 'targeting')) {
            $this->logger->info('Targeting rules update permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
                'flag_id' => $flagId,
            ]);
            return false;
        }

        $this->logger->debug('Targeting rules update permission granted', [
            'user_id' => $user->getId()->toString(),
            'flag_id' => $flagId,
        ]);

        return true;
    }

    public function canUpdatePercentageRollout(User $user, string $flagId): bool
    {
        if ($user === null) {
            $this->logger->warning('Percentage rollout update permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Percentage rollout update permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'flag_id' => $flagId,
            ]);
            return false;
        }

        if (!$user->hasPermission('feature_flags', 'rollout')) {
            $this->logger->info('Percentage rollout update permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
                'flag_id' => $flagId,
            ]);
            return false;
        }

        $this->logger->debug('Percentage rollout update permission granted', [
            'user_id' => $user->getId()->toString(),
            'flag_id' => $flagId,
        ]);

        return true;
    }

    public function canUpdateUserTargets(User $user, string $flagId): bool
    {
        if ($user === null) {
            $this->logger->warning('User targets update permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('User targets update permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'flag_id' => $flagId,
            ]);
            return false;
        }

        if (!$user->hasPermission('feature_flags', 'target_users')) {
            $this->logger->info('User targets update permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
                'flag_id' => $flagId,
            ]);
            return false;
        }

        $this->logger->debug('User targets update permission granted', [
            'user_id' => $user->getId()->toString(),
            'flag_id' => $flagId,
        ]);

        return true;
    }

    public function canUpdateEnvironmentTargets(User $user, string $flagId): bool
    {
        if ($user === null) {
            $this->logger->warning('Environment targets update permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Environment targets update permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'flag_id' => $flagId,
            ]);
            return false;
        }

        if (!$user->hasPermission('feature_flags', 'target_environment')) {
            $this->logger->info('Environment targets update permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
                'flag_id' => $flagId,
            ]);
            return false;
        }

        $this->logger->debug('Environment targets update permission granted', [
            'user_id' => $user->getId()->toString(),
            'flag_id' => $flagId,
        ]);

        return true;
    }

    public function canSyncEnvironmentOverrides(User $user, string $flagId): bool
    {
        if ($user === null) {
            $this->logger->warning('Environment overrides sync permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Environment overrides sync permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'flag_id' => $flagId,
            ]);
            return false;
        }

        if (!$user->hasPermission('feature_flags', 'sync_overrides')) {
            $this->logger->info('Environment overrides sync permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
                'flag_id' => $flagId,
            ]);
            return false;
        }

        $this->logger->debug('Environment overrides sync permission granted', [
            'user_id' => $user->getId()->toString(),
            'flag_id' => $flagId,
        ]);

        return true;
    }
}
