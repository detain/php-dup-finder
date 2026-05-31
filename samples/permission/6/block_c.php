<?php
declare(strict_types=1);

namespace App\Webhook\Security;

use App\Domain\Entity\User;
use App\Domain\Repository\ApiKeyRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class ApiKeyPermissionService
{
    public function __construct(
        private ApiKeyRepositoryInterface $apiKeyRepository,
        private LoggerInterface $logger,
    ) {}

    public function canCreateApiKey(User $user): bool
    {
        if ($user === null) {
            $this->logger->warning('API key create permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('API key create permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('api_key', 'create')) {
            $this->logger->info('API key create permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $existingKeyCount = $this->apiKeyRepository->countByUser($user->getId());
        if ($existingKeyCount >= $user->getMaxApiKeys()) {
            $this->logger->info('API key create permission denied: max keys reached', [
                'user_id' => $user->getId()->toString(),
                'existing_count' => $existingKeyCount,
            ]);
            return false;
        }

        $this->logger->debug('API key create permission granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }

    public function canRevokeApiKey(User $user, string $apiKeyId): bool
    {
        if ($user === null) {
            $this->logger->warning('API key revoke permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('API key revoke permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'api_key_id' => $apiKeyId,
            ]);
            return false;
        }

        $apiKey = $this->apiKeyRepository->findById($apiKeyId);
        if ($apiKey === null) {
            $this->logger->info('API key revoke permission denied: key not found', [
                'api_key_id' => $apiKeyId,
            ]);
            return false;
        }

        if (!$apiKey->getOwnerId()->equals($user->getId())) {
            if (!$user->hasPermission('api_key', 'revoke_others')) {
                $this->logger->info('API key revoke permission denied: not owner', [
                    'user_id' => $user->getId()->toString(),
                    'api_key_id' => $apiKeyId,
                ]);
                return false;
            }
        }

        $this->logger->debug('API key revoke permission granted', [
            'user_id' => $user->getId()->toString(),
            'api_key_id' => $apiKeyId,
        ]);

        return true;
    }

    public function canRotateApiKey(User $user, string $apiKeyId): bool
    {
        if ($user === null) {
            $this->logger->warning('API key rotate permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('API key rotate permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'api_key_id' => $apiKeyId,
            ]);
            return false;
        }

        $apiKey = $this->apiKeyRepository->findById($apiKeyId);
        if ($apiKey === null) {
            $this->logger->info('API key rotate permission denied: key not found', [
                'api_key_id' => $apiKeyId,
            ]);
            return false;
        }

        if (!$apiKey->getOwnerId()->equals($user->getId())) {
            if (!$user->hasPermission('api_key', 'rotate_others')) {
                $this->logger->info('API key rotate permission denied: not owner', [
                    'user_id' => $user->getId()->toString(),
                    'api_key_id' => $apiKeyId,
                ]);
                return false;
            }
        }

        $this->logger->debug('API key rotate permission granted', [
            'user_id' => $user->getId()->toString(),
            'api_key_id' => $apiKeyId,
        ]);

        return true;
    }

    public function canViewApiKeyLogs(User $user, string $apiKeyId): bool
    {
        if ($user === null) {
            $this->logger->warning('API key logs view permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('API key logs view permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'api_key_id' => $apiKeyId,
            ]);
            return false;
        }

        $apiKey = $this->apiKeyRepository->findById($apiKeyId);
        if ($apiKey === null) {
            $this->logger->info('API key logs view permission denied: key not found', [
                'api_key_id' => $apiKeyId,
            ]);
            return false;
        }

        if (!$apiKey->getOwnerId()->equals($user->getId())) {
            if (!$user->hasPermission('api_key', 'view_others_logs')) {
                $this->logger->info('API key logs view permission denied: not owner', [
                    'user_id' => $user->getId()->toString(),
                    'api_key_id' => $apiKeyId,
                ]);
                return false;
            }
        }

        $this->logger->debug('API key logs view permission granted', [
            'user_id' => $user->getId()->toString(),
            'api_key_id' => $apiKeyId,
        ]);

        return true;
    }
}
