<?php
declare(strict_types=1);

namespace App\Core\FeatureFlags\Security;

use App\Domain\Entity\User;
use Psr\Log\LoggerInterface;

enum FeatureFlagAction: string
{
    case List = 'list';
    case View = 'view';
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case Target = 'targeting';
    case Audit = 'view_audit';
}

interface FeatureFlagResourceInterface
{
    public function getId(): string;
    public function getOwnerId(): \App\Domain\ValueObject\Ulid;
    public function getTeamId(): string;
    public function isSystemFlag(): bool;
}

interface FeatureFlagPermissionStrategy
{
    public function getAction(): FeatureFlagAction;
    public function getPermission(): string;
    public function findResource(string $id): ?FeatureFlagResourceInterface;
}

abstract class BaseFeatureFlagPermissionService
{
    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {}

    public function canPerform(User $user, string $resourceId, FeatureFlagPermissionStrategy $strategy): bool
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
        if ($resource === null && $strategy->getAction() !== FeatureFlagAction::Create) {
            $this->logFailure('resource not found', $strategy, ['resource_id' => $resourceId]);
            return false;
        }

        if ($resource !== null) {
            if (!$this->canUserAccessResource($user, $resource, $strategy)) {
                $this->logFailure('access denied', $strategy, ['user_id' => $user->getId()->toString()]);
                return false;
            }
        } else {
            if (!$user->hasPermission('feature_flags', $strategy->getPermission())) {
                $this->logFailure('missing permission', $strategy, ['user_id' => $user->getId()->toString()]);
                return false;
            }
        }

        $this->logSuccess($strategy, ['user_id' => $user->getId()->toString()]);
        return true;
    }

    protected function canUserAccessResource(User $user, FeatureFlagResourceInterface $resource, FeatureFlagPermissionStrategy $strategy): bool
    {
        return $user->hasPermission('feature_flags', 'access_all') ||
               in_array($resource->getTeamId(), $user->getTeamIds(), true);
    }

    private function logFailure(string $reason, FeatureFlagPermissionStrategy $strategy, array $context = []): void
    {
        $this->logger->warning("Feature flag permission denied: {$reason}", array_merge(
            ['action' => $strategy->getAction()->value],
            $context
        ));
    }

    private function logSuccess(FeatureFlagPermissionStrategy $strategy, array $context = []): void
    {
        $this->logger->debug('Feature flag permission granted', array_merge(
            ['action' => $strategy->getAction()->value],
            $context
        ));
    }
}

final class FeatureFlagPermissionService extends BaseFeatureFlagPermissionService {}
final class FeatureFlagTargetingPermissionService extends BaseFeatureFlagPermissionService {}
final class FeatureFlagAuditPermissionService extends BaseFeatureFlagPermissionService {}
