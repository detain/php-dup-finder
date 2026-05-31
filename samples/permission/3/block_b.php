<?php
declare(strict_types=1);

namespace App\Api\OAuth\Validation;

use App\Domain\Entity\ApiClient;
use App\Domain\Entity\User;
use Psr\Log\LoggerInterface;

final readonly class ClientScopeValidationService
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function validateReadScopeForClient(User $user, ApiClient $client, string $requiredScope): bool
    {
        if ($user === null) {
            $this->logger->warning('Client read scope validation failed: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Client read scope validation failed: user not active', [
                'user_id' => $user->getId()->toString(),
                'client_id' => $client->getId(),
                'required_scope' => $requiredScope,
            ]);
            return false;
        }

        if (!$this->clientHasScope($client, $requiredScope, 'read')) {
            $this->logger->info('Client read scope validation failed: client missing scope', [
                'user_id' => $user->getId()->toString(),
                'client_id' => $client->getId(),
                'required_scope' => $requiredScope,
            ]);
            return false;
        }

        if (!$this->userHasScope($user, $requiredScope, 'read')) {
            $this->logger->info('Client read scope validation failed: user missing scope', [
                'user_id' => $user->getId()->toString(),
                'client_id' => $client->getId(),
                'required_scope' => $requiredScope,
            ]);
            return false;
        }

        $this->logger->debug('Client read scope validation passed', [
            'user_id' => $user->getId()->toString(),
            'client_id' => $client->getId(),
            'required_scope' => $requiredScope,
        ]);

        return true;
    }

    public function validateWriteScopeForClient(User $user, ApiClient $client, string $requiredScope): bool
    {
        if ($user === null) {
            $this->logger->warning('Client write scope validation failed: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Client write scope validation failed: user not active', [
                'user_id' => $user->getId()->toString(),
                'client_id' => $client->getId(),
                'required_scope' => $requiredScope,
            ]);
            return false;
        }

        if (!$this->clientHasScope($client, $requiredScope, 'write')) {
            $this->logger->info('Client write scope validation failed: client missing scope', [
                'user_id' => $user->getId()->toString(),
                'client_id' => $client->getId(),
                'required_scope' => $requiredScope,
            ]);
            return false;
        }

        if (!$this->userHasScope($user, $requiredScope, 'write')) {
            $this->logger->info('Client write scope validation failed: user missing scope', [
                'user_id' => $user->getId()->toString(),
                'client_id' => $client->getId(),
                'required_scope' => $requiredScope,
            ]);
            return false;
        }

        $this->logger->debug('Client write scope validation passed', [
            'user_id' => $user->getId()->toString(),
            'client_id' => $client->getId(),
            'required_scope' => $requiredScope,
        ]);

        return true;
    }

    public function validateAdminScopeForClient(User $user, ApiClient $client, string $requiredScope): bool
    {
        if ($user === null) {
            $this->logger->warning('Client admin scope validation failed: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Client admin scope validation failed: user not active', [
                'user_id' => $user->getId()->toString(),
                'client_id' => $client->getId(),
                'required_scope' => $requiredScope,
            ]);
            return false;
        }

        if (!$this->clientHasScope($client, $requiredScope, 'admin')) {
            $this->logger->info('Client admin scope validation failed: client missing scope', [
                'user_id' => $user->getId()->toString(),
                'client_id' => $client->getId(),
                'required_scope' => $requiredScope,
            ]);
            return false;
        }

        if (!$this->userHasScope($user, $requiredScope, 'admin')) {
            $this->logger->info('Client admin scope validation failed: user missing scope', [
                'user_id' => $user->getId()->toString(),
                'client_id' => $client->getId(),
                'required_scope' => $requiredScope,
            ]);
            return false;
        }

        $this->logger->debug('Client admin scope validation passed', [
            'user_id' => $user->getId()->toString(),
            'client_id' => $client->getId(),
            'required_scope' => $requiredScope,
        ]);

        return true;
    }

    private function clientHasScope(ApiClient $client, string $scope, string $action): bool
    {
        $fullScope = "{$scope}:{$action}";
        foreach ($client->getScopes() as $clientScope) {
            if ($clientScope === $fullScope || $clientScope === "{$scope}:*") {
                return true;
            }
        }
        return false;
    }

    private function userHasScope(User $user, string $scope, string $action): bool
    {
        $fullScope = "{$scope}:{$action}";
        foreach ($user->getApiScopes() as $userScope) {
            if ($userScope === $fullScope || $userScope === "{$scope}:*") {
                return true;
            }
        }
        return false;
    }
}
