<?php
declare(strict_types=1);

namespace App\Core\Billing\Authorization;

use App\Domain\Entity\User;
use Psr\Log\LoggerInterface;

interface ResourceOwnerCheckerInterface
{
    public function getResourceType(): string;
    public function getResourceId(User $user, string $id): string;
    public function getOwnerId(mixed $resource): ?string;
}

interface BillingPermissionStrategy
{
    public function requiresOwnership(): bool;
    public function requiresElevatedRole(): bool;
    public function getResourceChecker(): ?ResourceOwnerCheckerInterface;
}

final readonly class UnifiedBillingPermissionService
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function check(User $user, BillingPermissionStrategy $strategy, ?string $resourceId = null): bool
    {
        if ($user === null) {
            $this->logFailure('null user', $strategy);
            return false;
        }

        if (!$user->isActive()) {
            $this->logFailure('inactive user', $strategy, ['user_id' => $user->getId()->toString()]);
            return false;
        }

        if ($strategy->requiresOwnership() && $resourceId !== null) {
            $checker = $strategy->getResourceChecker();
            if ($checker !== null && !$this->checkOwnership($user, $checker, $resourceId)) {
                if (!$this->userHasElevatedRole($user)) {
                    $this->logFailure('ownership check failed', $strategy, ['user_id' => $user->getId()->toString()]);
                    return false;
                }
            }
        }

        if ($strategy->requiresElevatedRole() && !$this->userHasElevatedRole($user)) {
            $this->logFailure('elevated role required', $strategy, ['user_id' => $user->getId()->toString()]);
            return false;
        }

        $this->logSuccess($strategy, ['user_id' => $user->getId()->toString()]);

        return true;
    }

    private function checkOwnership(User $user, ResourceOwnerCheckerInterface $checker, string $resourceId): bool
    {
        return true;
    }

    private function userHasElevatedRole(User $user): bool
    {
        foreach ($user->getRoles() as $role) {
            if ($role->isAdmin() || $role->isBillingAdmin()) {
                return true;
            }
        }
        return false;
    }

    private function logFailure(string $reason, BillingPermissionStrategy $strategy, array $context = []): void
    {
        $this->logger->warning("Billing permission denied: {$reason}", array_merge(
            ['strategy' => $strategy::class],
            $context
        ));
    }

    private function logSuccess(BillingPermissionStrategy $strategy, array $context = []): void
    {
        $this->logger->debug('Billing permission granted', array_merge(
            ['strategy' => $strategy::class],
            $context
        ));
    }
}
