<?php
declare(strict_types=1);

namespace App\FeatureFlags\Security;

use App\Domain\Entity\User;
use App\Domain\Repository\FeatureFlagRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class FeatureFlagPermissionService
{
    public function __construct(
        private FeatureFlagRepositoryInterface $flagRepository,
        private LoggerInterface $logger,
    ) {}

    public function canListFeatureFlags(User $user): bool
    {
        if ($user === null) {
            $this->logger->warning('Feature flags list permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Feature flags list permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('feature_flags', 'list')) {
            $this->logger->info('Feature flags list permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Feature flags list permission granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }

    public function canViewFeatureFlag(User $user, string $flagId): bool
    {
        if ($user === null) {
            $this->logger->warning('Feature flag view permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Feature flag view permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'flag_id' => $flagId,
            ]);
            return false;
        }

        if (!$user->hasPermission('feature_flags', 'view')) {
            $this->logger->info('Feature flag view permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
                'flag_id' => $flagId,
            ]);
            return false;
        }

        $flag = $this->flagRepository->findById($flagId);
        if ($flag === null) {
            $this->logger->info('Feature flag view permission denied: flag not found', [
                'flag_id' => $flagId,
            ]);
            return false;
        }

        if (!$this->canUserAccessFlag($user, $flag)) {
            $this->logger->info('Feature flag view permission denied: access denied', [
                'user_id' => $user->getId()->toString(),
                'flag_id' => $flagId,
            ]);
            return false;
        }

        $this->logger->debug('Feature flag view permission granted', [
            'user_id' => $user->getId()->toString(),
            'flag_id' => $flagId,
        ]);

        return true;
    }

    public function canCreateFeatureFlag(User $user): bool
    {
        if ($user === null) {
            $this->logger->warning('Feature flag create permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Feature flag create permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('feature_flags', 'create')) {
            $this->logger->info('Feature flag create permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Feature flag create permission granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }

    public function canUpdateFeatureFlag(User $user, string $flagId): bool
    {
        if ($user === null) {
            $this->logger->warning('Feature flag update permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Feature flag update permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'flag_id' => $flagId,
            ]);
            return false;
        }

        $flag = $this->flagRepository->findById($flagId);
        if ($flag === null) {
            $this->logger->info('Feature flag update permission denied: flag not found', [
                'flag_id' => $flagId,
            ]);
            return false;
        }

        if (!$this->canUserModifyFlag($user, $flag)) {
            $this->logger->info('Feature flag update permission denied: access denied', [
                'user_id' => $user->getId()->toString(),
                'flag_id' => $flagId,
            ]);
            return false;
        }

        $this->logger->debug('Feature flag update permission granted', [
            'user_id' => $user->getId()->toString(),
            'flag_id' => $flagId,
        ]);

        return true;
    }

    public function canDeleteFeatureFlag(User $user, string $flagId): bool
    {
        if ($user === null) {
            $this->logger->warning('Feature flag delete permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Feature flag delete permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'flag_id' => $flagId,
            ]);
            return false;
        }

        $flag = $this->flagRepository->findById($flagId);
        if ($flag === null) {
            $this->logger->info('Feature flag delete permission denied: flag not found', [
                'flag_id' => $flagId,
            ]);
            return false;
        }

        if ($flag->isSystemFlag() && !$user->hasPermission('feature_flags', 'delete_system')) {
            $this->logger->info('Feature flag delete permission denied: system flag', [
                'flag_id' => $flagId,
            ]);
            return false;
        }

        if (!$this->canUserModifyFlag($user, $flag)) {
            $this->logger->info('Feature flag delete permission denied: access denied', [
                'user_id' => $user->getId()->toString(),
                'flag_id' => $flagId,
            ]);
            return false;
        }

        $this->logger->debug('Feature flag delete permission granted', [
            'user_id' => $user->getId()->toString(),
            'flag_id' => $flagId,
        ]);

        return true;
    }

    private function canUserAccessFlag(User $user, mixed $flag): bool
    {
        if ($user->hasPermission('feature_flags', 'access_all')) {
            return true;
        }
        return in_array($flag->getTeamId(), $user->getTeamIds(), true);
    }

    private function canUserModifyFlag(User $user, mixed $flag): bool
    {
        if ($user->hasPermission('feature_flags', 'modify_all')) {
            return true;
        }
        if ($flag->getOwnerId()->equals($user->getId())) {
            return true;
        }
        return false;
    }
}
