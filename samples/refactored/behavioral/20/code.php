<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\PrincipalInterface;
use Psr\Log\LoggerInterface;

final class UnifiedPermissionChecker
{
    /** @var array<string, array{roles: string[], scopes: string[], check: callable(PrincipalInterface): bool}> */
    private array $permissionRules = [];

    public function __construct(
        private readonly PermissionRegistry $permissionRegistry,
        private readonly LoggerInterface $logger,
    ) {
        $this->initializeRules();
    }

    private function initializeRules(): void
    {
        $this->permissionRules['users:manage'] = [
            'roles' => ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN'],
            'scopes' => ['users:write', 'users:manage'],
            'check' => fn($p) => $p->isActive(),
        ];

        $this->permissionRules['reports:read'] = [
            'roles' => ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN', 'ROLE_MANAGER'],
            'scopes' => ['reports:read'],
            'check' => fn($p) => $p->isActive(),
        ];

        $this->permissionRules['content:delete'] = [
            'roles' => ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN'],
            'scopes' => ['content:delete:all'],
            'check' => fn($p) => $p->isActive(),
        ];

        $this->permissionRules['data:read'] = [
            'roles' => ['ROLE_SUPER_ADMIN'],
            'scopes' => ['admin:all', 'data:read'],
            'check' => fn($p) => $p->isActive() && ($p instanceof \App\Entity\ApiClient ? $p->isActive() : true),
        ];

        $this->permissionRules['data:write'] = [
            'roles' => ['ROLE_SUPER_ADMIN'],
            'scopes' => ['admin:all', 'data:write'],
            'check' => fn($p) => $p->isActive() && ($p instanceof \App\Entity\ApiClient ? $p->isActive() : true),
        ];

        $this->permissionRules['content:create'] = [
            'roles' => ['administrator', 'editor', 'author'],
            'scopes' => ['content:create'],
            'check' => fn($p) => $p->isActive(),
        ];

        $this->permissionRules['content:publish'] = [
            'roles' => ['administrator', 'editor'],
            'scopes' => ['content:publish'],
            'check' => fn($p) => $p->isActive(),
        ];
    }

    public function can(PrincipalInterface $principal, string $permission, ?int $ownerId = null): bool
    {
        if (!$principal->isActive()) {
            $this->logger->debug('Permission denied: principal inactive', ['type' => get_class($principal)]);
            return false;
        }

        $rule = $this->permissionRules[$permission] ?? null;

        if ($rule === null) {
            $this->logger->warning('Unknown permission', ['permission' => $permission]);
            return false;
        }

        if (!$rule['check']($principal)) {
            return false;
        }

        if ($this->hasRole($principal, $rule['roles'])) {
            if ($this->hasScope($principal, 'admin:all')) {
                return true;
            }
            if ($this->hasScope($principal, $rule['scopes'])) {
                return true;
            }
        }

        if ($ownerId !== null && $principal->getId() === $ownerId) {
            if ($this->hasScope($principal, [$permission . ':own'])) {
                return true;
            }
        }

        $this->logger->debug('Permission denied: no matching role or scope', [
            'permission' => $permission,
            'principal_id' => $principal->getId(),
        ]);

        return false;
    }

    private function hasRole(PrincipalInterface $principal, array $roles): bool
    {
        $principalRoles = $principal->getRoles() ?? [];
        foreach ($roles as $role) {
            if (in_array($role, $principalRoles, true)) {
                return true;
            }
        }
        return false;
    }

    private function hasScope(PrincipalInterface $principal, array $scopes): bool
    {
        if (method_exists($principal, 'getScopes')) {
            $principalScopes = $principal->getScopes();
            foreach ($scopes as $scope) {
                foreach ($principalScopes as $principalScope) {
                    if ($principalScope->getName() === $scope) {
                        return true;
                    }
                }
            }
        }
        return false;
    }
}

final class PermissionRegistry
{
    /** @var array<string, array{roles?: string[], scopes?: string[]}> */
    private array $permissions = [];

    public function register(string $name, array $config): void
    {
        $this->permissions[$name] = $config;
    }

    public function getRoles(string $permission): array
    {
        return $this->permissions[$permission]['roles'] ?? [];
    }

    public function getScopes(string $permission): array
    {
        return $this->permissions[$permission]['scopes'] ?? [];
    }
}
