<?php
declare(strict_types=1);

namespace App\Core\Audit\Security;

use App\Domain\Entity\User;
use Psr\Log\LoggerInterface;

enum AuditPermission: string
{
    case View = 'view';
    case ViewDetails = 'view_details';
    case ViewEntity = 'view_entity';
    case Search = 'search';
    case Export = 'export';
    case ViewStatistics = 'view_statistics';
    case ConfigureRetention = 'configure_retention';
    case ViewRetention = 'view_retention';
    case Delete = 'delete';
    case Archive = 'archive';
    case ConfigureAlerts = 'configure_alerts';
    case ViewAlerts = 'view_alerts';
    case DeleteAlerts = 'delete_alerts';
    case ViewAlertHistory = 'view_alert_history';
    case AcknowledgeAlerts = 'acknowledge_alerts';
}

interface AuditLogInterface
{
    public function getId(): string;
    public function getEntityType(): string;
}

interface AuditPermissionStrategy
{
    public function getPermission(): AuditPermission;
    public function getPermissionString(): string;
    public function getEntityPermissionString(string $entityType): string;
    public function requiresViewPermission(): bool;
}

abstract class BaseAuditPermissionService
{
    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {}

    public function canPerform(User $user, string $entityType = null, AuditPermissionStrategy $strategy): bool
    {
        if ($user === null) {
            $this->logFailure('null user', $strategy);
            return false;
        }

        if (!$user->isActive()) {
            $this->logFailure('inactive user', $strategy, ['user_id' => $user->getId()->toString()]);
            return false;
        }

        $permission = $entityType !== null && $strategy instanceof EntityAuditPermissionStrategy
            ? $strategy->getEntityPermissionString($entityType)
            : $strategy->getPermissionString();

        if (!$user->hasPermission('audit', $permission)) {
            $this->logFailure('missing permission', $strategy, ['user_id' => $user->getId()->toString()]);
            return false;
        }

        if ($strategy->requiresViewPermission() && !$user->hasPermission('audit', 'view')) {
            $this->logFailure('view permission required', $strategy, ['user_id' => $user->getId()->toString()]);
            return false;
        }

        $this->logSuccess($strategy, ['user_id' => $user->getId()->toString()]);
        return true;
    }

    private function logFailure(string $reason, AuditPermissionStrategy $strategy, array $context = []): void
    {
        $this->logger->warning("Audit permission denied: {$reason}", array_merge(
            ['permission' => $strategy->getPermission()->value],
            $context
        ));
    }

    private function logSuccess(AuditPermissionStrategy $strategy, array $context = []): void
    {
        $this->logger->debug('Audit permission granted', array_merge(
            ['permission' => $strategy->getPermission()->value],
            $context
        ));
    }
}

interface EntityAuditPermissionStrategy extends AuditPermissionStrategy {}

final class AuditLogPermissionService extends BaseAuditPermissionService {}
final class AuditRetentionPermissionService extends BaseAuditPermissionService {}
final class AuditAlertPermissionService extends BaseAuditPermissionService {}
