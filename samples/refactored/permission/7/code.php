<?php
declare(strict_types=1);

namespace App\Core\Shipping\Authorization;

use App\Domain\Entity\User;
use Psr\Log\LoggerInterface;

interface ShippableResourceInterface
{
    public function getId(): string;
    public function getOwnerId(): \App\Domain\ValueObject\Ulid;
    public function isModifiable(): bool;
    public function isCancellable(): bool;
}

interface ShippingPermissionStrategy
{
    public function getResourceType(): string;
    public function getBasePermission(): string;
    public function getOthersPermission(): string;
    public function findResource(string $id): ?ShippableResourceInterface;
}

abstract class BaseShippingPermissionService
{
    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {}

    public function canPerform(User $user, string $resourceId, string $action, ShippingPermissionStrategy $strategy): bool
    {
        if ($user === null) {
            $this->logFailure('null user', $strategy, $action);
            return false;
        }

        if (!$user->isActive()) {
            $this->logFailure('inactive user', $strategy, $action, ['user_id' => $user->getId()->toString()]);
            return false;
        }

        $resource = $strategy->findResource($resourceId);
        if ($resource !== null) {
            if (!$resource->getOwnerId()->equals($user->getId())) {
                $othersPermission = $strategy->getOthersPermission();
                if (!$user->hasPermission($strategy->getResourceType(), $othersPermission)) {
                    $this->logFailure('not owner', $strategy, $action, ['user_id' => $user->getId()->toString()]);
                    return false;
                }
            }

            if ($action === 'modify' && !$resource->isModifiable()) {
                $this->logFailure('not modifiable', $strategy, $action);
                return false;
            }
            if ($action === 'cancel' && !$resource->isCancellable()) {
                $this->logFailure('not cancellable', $strategy, $action);
                return false;
            }
        } else {
            if (!$user->hasPermission($strategy->getResourceType(), $strategy->getBasePermission())) {
                $this->logFailure('missing permission', $strategy, $action, ['user_id' => $user->getId()->toString()]);
                return false;
            }
        }

        $this->logSuccess($strategy, $action, ['user_id' => $user->getId()->toString()]);
        return true;
    }

    private function logFailure(string $reason, ShippingPermissionStrategy $strategy, string $action, array $context = []): void
    {
        $this->logger->warning("Shipping permission denied: {$reason}", array_merge(
            ['resource_type' => $strategy->getResourceType(), 'action' => $action],
            $context
        ));
    }

    private function logSuccess(ShippingPermissionStrategy $strategy, string $action, array $context = []): void
    {
        $this->logger->debug('Shipping permission granted', array_merge(
            ['resource_type' => $strategy->getResourceType(), 'action' => $action],
            $context
        ));
    }
}

final class ShippingPermissionService extends BaseShippingPermissionService {}
final class ReturnPermissionService extends BaseShippingPermissionService {}
final class CarrierAccountPermissionService extends BaseShippingPermissionService {}
