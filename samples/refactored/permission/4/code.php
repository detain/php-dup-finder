<?php
declare(strict_types=1);

namespace App\Core\Document\Security;

use App\Domain\Entity\User;
use Psr\Log\LoggerInterface;

enum DocumentPermission: string
{
    case Read = 'read';
    case Write = 'write';
    case Delete = 'delete';
    case Share = 'share';
}

interface DocumentResourceInterface
{
    public function getId(): string;
    public function getOwnerId(): \App\Domain\ValueObject\Ulid;
}

interface DocumentPermissionStrategy
{
    public function getPermission(): DocumentPermission;
    public function getResourceType(): string;
    public function findResource(string $id): ?DocumentResourceInterface;
    public function hasDirectPermission(User $user, DocumentResourceInterface $resource, DocumentPermission $permission): bool;
    public function hasInheritedPermission(User $user, DocumentResourceInterface $resource, DocumentPermission $permission): bool;
}

abstract class BaseDocumentPermissionService
{
    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {}

    public function check(User $user, string $resourceId, DocumentPermission $permission, DocumentPermissionStrategy $strategy): bool
    {
        if ($user === null) {
            $this->logFailure('null user', $strategy, $permission);
            return false;
        }

        if (!$user->isActive()) {
            $this->logFailure('inactive user', $strategy, $permission, ['user_id' => $user->getId()->toString()]);
            return false;
        }

        $resource = $strategy->findResource($resourceId);
        if ($resource === null) {
            $this->logFailure('resource not found', $strategy, $permission, ['resource_id' => $resourceId]);
            return false;
        }

        if ($resource->getOwnerId()->equals($user->getId())) {
            $this->logSuccess($strategy, $permission, ['user_id' => $user->getId()->toString(), 'resource_id' => $resourceId]);
            return true;
        }

        if ($strategy->hasDirectPermission($user, $resource, $permission)) {
            $this->logSuccess($strategy, $permission, ['user_id' => $user->getId()->toString(), 'resource_id' => $resourceId]);
            return true;
        }

        if ($strategy->hasInheritedPermission($user, $resource, $permission)) {
            $this->logSuccess($strategy, $permission, ['user_id' => $user->getId()->toString(), 'resource_id' => $resourceId]);
            return true;
        }

        $this->logFailure('no access', $strategy, $permission, ['user_id' => $user->getId()->toString(), 'resource_id' => $resourceId]);

        return false;
    }

    private function logFailure(string $reason, DocumentPermissionStrategy $strategy, DocumentPermission $permission, array $context = []): void
    {
        $this->logger->warning("Document permission denied: {$reason}", array_merge(
            ['strategy' => $strategy::class, 'permission' => $permission->value],
            $context
        ));
    }

    private function logSuccess(DocumentPermissionStrategy $strategy, DocumentPermission $permission, array $context = []): void
    {
        $this->logger->debug('Document permission granted', array_merge(
            ['strategy' => $strategy::class, 'permission' => $permission->value],
            $context
        ));
    }
}

final class FilePermissionService extends BaseDocumentPermissionService {}
final class FolderPermissionService extends BaseDocumentPermissionService {}
final class DocumentPermissionService extends BaseDocumentPermissionService {}
