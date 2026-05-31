<?php

declare(strict_types=1);

namespace App\Api\Security;

use App\Entity\ApiClient;
use App\Entity\ApiScope;
use App\Repository\ScopeRepository;
use Psr\Log\LoggerInterface;

final class ApiPermissionService
{
    public function __construct(
        private readonly ScopeRepository $scopeRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function canReadData(ApiClient $client): bool
    {
        if (!$client->isActive()) {
            $this->logger->debug('Permission denied: client inactive', ['client_id' => $client->getId()]);
            return false;
        }

        $scopes = $client->getScopes();
        if ($this->hasScope($scopes, 'admin:all') || $this->hasScope($scopes, 'data:read')) {
            return true;
        }

        $this->logger->debug('Permission denied: missing read scope', ['client_id' => $client->getId()]);
        return false;
    }

    public function canWriteData(ApiClient $client): bool
    {
        if (!$client->isActive()) {
            return false;
        }

        $scopes = $client->getScopes();
        if ($this->hasScope($scopes, 'admin:all') || $this->hasScope($scopes, 'data:write')) {
            return true;
        }

        $this->logger->debug('Permission denied: missing write scope', ['client_id' => $client->getId()]);
        return false;
    }

    public function canDeleteData(ApiClient $client): bool
    {
        if (!$client->isActive()) {
            return false;
        }

        $scopes = $client->getScopes();
        if ($this->hasScope($scopes, 'admin:all')) {
            return true;
        }

        if ($this->hasScope($scopes, 'data:delete')) {
            return true;
        }

        $this->logger->debug('Permission denied: missing delete scope', ['client_id' => $client->getId()]);
        return false;
    }

    public function canAccessBilling(ApiClient $client): bool
    {
        if (!$client->isActive()) {
            return false;
        }

        $scopes = $client->getScopes();
        if ($this->hasScope($scopes, 'admin:all') || $this->hasScope($scopes, 'billing:read')) {
            return true;
        }

        $this->logger->debug('Permission denied: missing billing scope', ['client_id' => $client->getId()]);
        return false;
    }

    public function canManageWebhooks(ApiClient $client): bool
    {
        if (!$client->isActive()) {
            return false;
        }

        $scopes = $client->getScopes();
        if ($this->hasScope($scopes, 'admin:all') || $this->hasScope($scopes, 'webhooks:manage')) {
            return true;
        }

        $this->logger->debug('Permission denied: missing webhooks scope', ['client_id' => $client->getId()]);
        return false;
    }

    private function hasScope(array $scopes, string $requiredScope): bool
    {
        foreach ($scopes as $scope) {
            if ($scope->getName() === $requiredScope) {
                return true;
            }
        }
        return false;
    }
}
