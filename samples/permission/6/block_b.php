<?php
declare(strict_types=1);

namespace App\Webhook\Security;

use App\Domain\Entity\User;
use App\Domain\Repository\IntegrationRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class IntegrationPermissionService
{
    public function __construct(
        private IntegrationRepositoryInterface $integrationRepository,
        private LoggerInterface $logger,
    ) {}

    public function canCreateIntegration(User $user, string $integrationType): bool
    {
        if ($user === null) {
            $this->logger->warning('Integration create permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Integration create permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'integration_type' => $integrationType,
            ]);
            return false;
        }

        if (!$user->hasPermission('integration', 'create')) {
            $this->logger->info('Integration create permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$this->isIntegrationTypeAllowed($user, $integrationType)) {
            $this->logger->info('Integration create permission denied: type not allowed', [
                'user_id' => $user->getId()->toString(),
                'integration_type' => $integrationType,
            ]);
            return false;
        }

        $this->logger->debug('Integration create permission granted', [
            'user_id' => $user->getId()->toString(),
            'integration_type' => $integrationType,
        ]);

        return true;
    }

    public function canUpdateIntegration(User $user, string $integrationId): bool
    {
        if ($user === null) {
            $this->logger->warning('Integration update permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Integration update permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'integration_id' => $integrationId,
            ]);
            return false;
        }

        $integration = $this->integrationRepository->findById($integrationId);
        if ($integration === null) {
            $this->logger->info('Integration update permission denied: integration not found', [
                'integration_id' => $integrationId,
            ]);
            return false;
        }

        if (!$integration->getOwnerId()->equals($user->getId())) {
            if (!$user->hasPermission('integration', 'update_others')) {
                $this->logger->info('Integration update permission denied: not owner', [
                    'user_id' => $user->getId()->toString(),
                    'integration_id' => $integrationId,
                ]);
                return false;
            }
        }

        $this->logger->debug('Integration update permission granted', [
            'user_id' => $user->getId()->toString(),
            'integration_id' => $integrationId,
        ]);

        return true;
    }

    public function canDeleteIntegration(User $user, string $integrationId): bool
    {
        if ($user === null) {
            $this->logger->warning('Integration delete permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Integration delete permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'integration_id' => $integrationId,
            ]);
            return false;
        }

        $integration = $this->integrationRepository->findById($integrationId);
        if ($integration === null) {
            $this->logger->info('Integration delete permission denied: integration not found', [
                'integration_id' => $integrationId,
            ]);
            return false;
        }

        if (!$integration->getOwnerId()->equals($user->getId())) {
            if (!$user->hasPermission('integration', 'delete_others')) {
                $this->logger->info('Integration delete permission denied: not owner', [
                    'user_id' => $user->getId()->toString(),
                    'integration_id' => $integrationId,
                ]);
                return false;
            }
        }

        $this->logger->debug('Integration delete permission granted', [
            'user_id' => $user->getId()->toString(),
            'integration_id' => $integrationId,
        ]);

        return true;
    }

    public function canViewIntegrationLogs(User $user, string $integrationId): bool
    {
        if ($user === null) {
            $this->logger->warning('Integration logs view permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Integration logs view permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'integration_id' => $integrationId,
            ]);
            return false;
        }

        $integration = $this->integrationRepository->findById($integrationId);
        if ($integration === null) {
            $this->logger->info('Integration logs view permission denied: integration not found', [
                'integration_id' => $integrationId,
            ]);
            return false;
        }

        if (!$integration->getOwnerId()->equals($user->getId())) {
            if (!$user->hasPermission('integration', 'view_others_logs')) {
                $this->logger->info('Integration logs view permission denied: not owner', [
                    'user_id' => $user->getId()->toString(),
                    'integration_id' => $integrationId,
                ]);
                return false;
            }
        }

        $this->logger->debug('Integration logs view permission granted', [
            'user_id' => $user->getId()->toString(),
            'integration_id' => $integrationId,
        ]);

        return true;
    }

    private function isIntegrationTypeAllowed(User $user, string $integrationType): bool
    {
        $allowedTypes = $user->getAllowedIntegrationTypes();
        return in_array($integrationType, $allowedTypes, true);
    }
}
