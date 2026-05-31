<?php
declare(strict_types=1);

namespace App\Core\Webhook\Security;

use App\Domain\Entity\User;
use Psr\Log\LoggerInterface;

interface OwnableResourceInterface
{
    public function getId(): string;
    public function getOwnerId(): \App\Domain\ValueObject\Ulid;
}

interface ResourcePermissionStrategy
{
    public function getResourceType(): string;
    public function getPermission(): string;
    public function getOthersPermission(): string;
    public function findResource(string $id): ?OwnableResourceInterface;
}

abstract class BaseResourcePermissionService
{
    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {}

    public function canAccess(User $user, string $resourceId, ResourcePermissionStrategy $strategy): bool
    {
        if ($user === null) {
            $this->logFailure('null user', $strategy);
            return false;
        }

        if (!$user->isActive()) {
            $this->logFailure('inactive user', $strategy, ['user_id' => $user->getId()->toString()]);
            return false;
        }

        $resource = $strategy->findResource($resourceId);
        if ($resource === null) {
            $this->logFailure('resource not found', $strategy, ['resource_id' => $resourceId]);
            return false;
        }

        if ($resource->getOwnerId()->equals($user->getId())) {
            $this->logSuccess($strategy, ['user_id' => $user->getId()->toString(), 'resource_id' => $resourceId]);
            return true;
        }

        if ($user->hasPermission($strategy->getResourceType(), $strategy->getOthersPermission())) {
            $this->logSuccess($strategy, ['user_id' => $user->getId()->toString(), 'resource_id' => $resourceId, 'via' => 'elevated']);
            return true;
        }

        $this->logFailure('not owner or elevated', $strategy, ['user_id' => $user->getId()->toString(), 'resource_id' => $resourceId]);
        return false;
    }

    public function canCreate(User $user, ResourcePermissionStrategy $strategy): bool
    {
        if ($user === null) {
            $this->logFailure('null user', $strategy);
            return false;
        }

        if (!$user->isActive()) {
            $this->logFailure('inactive user', $strategy, ['user_id' => $user->getId()->toString()]);
            return false;
        }

        if (!$user->hasPermission($strategy->getResourceType(), $strategy->getPermission())) {
            $this->logFailure('missing permission', $strategy, ['user_id' => $user->getId()->toString()]);
            return false;
        }

        $this->logSuccess($strategy, ['user_id' => $user->getId()->toString()]);
        return true;
    }

    private function logFailure(string $reason, ResourcePermissionStrategy $strategy, array $context = []): void
    {
        $this->logger->warning("Permission denied: {$reason}", array_merge(
            ['resource_type' => $strategy->getResourceType(), 'permission' => $strategy->getPermission()],
            $context
        ));
    }

    private function logSuccess(ResourcePermissionStrategy $strategy, array $context = []): void
    {
        $this->logger->debug('Permission granted', array_merge(
            ['resource_type' => $strategy->getResourceType(), 'permission' => $strategy->getPermission()],
            $context
        ));
    }
}

final class WebhookPermissionService extends BaseResourcePermissionService {}
final class IntegrationPermissionService extends BaseResourcePermissionService {}
final class ApiKeyPermissionService extends BaseResourcePermissionService {}
