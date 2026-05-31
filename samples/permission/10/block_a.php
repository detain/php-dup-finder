<?php
declare(strict_types=1);

namespace App\Audit\Security;

use App\Domain\Entity\User;
use App\Domain\Repository\AuditLogRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class AuditLogPermissionService
{
    public function __construct(
        private AuditLogRepositoryInterface $auditLogRepository,
        private LoggerInterface $logger,
    ) {}

    public function canViewAuditLogs(User $user, string $entityType = null): bool
    {
        if ($user === null) {
            $this->logger->warning('Audit logs view permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Audit logs view permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if ($entityType !== null) {
            if (!$user->hasPermission('audit', 'view_entity', $entityType)) {
                $this->logger->info('Audit logs view permission denied: no entity permission', [
                    'user_id' => $user->getId()->toString(),
                    'entity_type' => $entityType,
                ]);
                return false;
            }
        } else {
            if (!$user->hasPermission('audit', 'view')) {
                $this->logger->info('Audit logs view permission denied: missing permission', [
                    'user_id' => $user->getId()->toString(),
                ]);
                return false;
            }
        }

        $this->logger->debug('Audit logs view permission granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }

    public function canExportAuditLogs(User $user): bool
    {
        if ($user === null) {
            $this->logger->warning('Audit logs export permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Audit logs export permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('audit', 'export')) {
            $this->logger->info('Audit logs export permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('audit', 'view')) {
            $this->logger->info('Audit logs export permission denied: export requires view permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Audit logs export permission granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }

    public function canViewAuditLogDetails(User $user, string $logId): bool
    {
        if ($user === null) {
            $this->logger->warning('Audit log details view permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Audit log details view permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'log_id' => $logId,
            ]);
            return false;
        }

        if (!$user->hasPermission('audit', 'view_details')) {
            $this->logger->info('Audit log details view permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
                'log_id' => $logId,
            ]);
            return false;
        }

        $log = $this->auditLogRepository->findById($logId);
        if ($log === null) {
            $this->logger->info('Audit log details view permission denied: log not found', [
                'log_id' => $logId,
            ]);
            return false;
        }

        if (!$this->canUserAccessLogEntity($user, $log)) {
            $this->logger->info('Audit log details view permission denied: entity access denied', [
                'user_id' => $user->getId()->toString(),
                'log_id' => $logId,
            ]);
            return false;
        }

        $this->logger->debug('Audit log details view permission granted', [
            'user_id' => $user->getId()->toString(),
            'log_id' => $logId,
        ]);

        return true;
    }

    public function canSearchAuditLogs(User $user): bool
    {
        if ($user === null) {
            $this->logger->warning('Audit logs search permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Audit logs search permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('audit', 'search')) {
            $this->logger->info('Audit logs search permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('audit', 'view')) {
            $this->logger->info('Audit logs search permission denied: search requires view permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Audit logs search permission granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }

    public function canViewAuditStatistics(User $user): bool
    {
        if ($user === null) {
            $this->logger->warning('Audit statistics view permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Audit statistics view permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('audit', 'view_statistics')) {
            $this->logger->info('Audit statistics view permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Audit statistics view permission granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }

    private function canUserAccessLogEntity(User $user, mixed $log): bool
    {
        return true;
    }
}
