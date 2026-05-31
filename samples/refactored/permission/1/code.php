<?php
declare(strict_types=1);

namespace App\Core\Security\Authorization;

use App\Domain\Entity\User;
use Psr\Log\LoggerInterface;

interface RoleCheckerInterface
{
    public function getRequiredRole(): ?string;
    public function getRequiredPermission(): ?array;
    public function getAdditionalCheck(User $user): bool;
}

final readonly class UnifiedPermissionService
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function check(User $user, RoleCheckerInterface $checker): bool
    {
        if ($user === null) {
            $this->logFailure('null user', $checker);
            return false;
        }

        if (!$this->isUserActive($user)) {
            $this->logFailure('user not active', $checker, ['user_id' => $user->getId()->toString()]);
            return false;
        }

        if ($checker->getRequiredRole() !== null) {
            if (!$this->hasRole($user, $checker->getRequiredRole())) {
                $this->logFailure('missing required role', $checker, ['user_id' => $user->getId()->toString()]);
                return false;
            }
        }

        if ($checker->getRequiredPermission() !== null) {
            [$resource, $action] = $checker->getRequiredPermission();
            if (!$this->hasPermission($user, $resource, $action)) {
                $this->logFailure('missing required permission', $checker, ['user_id' => $user->getId()->toString()]);
                return false;
            }
        }

        if (!$checker->getAdditionalCheck($user)) {
            $this->logFailure('additional check failed', $checker, ['user_id' => $user->getId()->toString()]);
            return false;
        }

        $this->logSuccess($checker, ['user_id' => $user->getId()->toString()]);

        return true;
    }

    private function isUserActive(User $user): bool
    {
        return $user->isActive();
    }

    private function hasRole(User $user, string $roleName): bool
    {
        foreach ($user->getRoles() as $role) {
            if ($role->getName() === $roleName) {
                return true;
            }
        }
        return false;
    }

    private function hasPermission(User $user, string $resource, string $action): bool
    {
        foreach ($user->getRoles() as $role) {
            if ($role->hasPermission($resource, $action)) {
                return true;
            }
        }
        return false;
    }

    private function logFailure(string $reason, RoleCheckerInterface $checker, array $context = []): void
    {
        $this->logger->warning("Access check failed: {$reason}", array_merge(
            ['checker' => $checker::class],
            $context
        ));
    }

    private function logSuccess(RoleCheckerInterface $checker, array $context = []): void
    {
        $this->logger->debug('Access check succeeded', array_merge(
            ['checker' => $checker::class],
            $context
        ));
    }
}
