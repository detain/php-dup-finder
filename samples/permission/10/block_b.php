<?php
declare(strict_types=1);

namespace App\Audit\Security;

use App\Domain\Entity\User;
use Psr\Log\LoggerInterface;

final readonly class AuditRetentionPermissionService
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function canConfigureRetentionPolicy(User $user): bool
    {
        if ($user === null) {
            $this->logger->warning('Retention policy configure permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Retention policy configure permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('audit', 'configure_retention')) {
            $this->logger->info('Retention policy configure permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Retention policy configure permission granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }

    public function canViewRetentionSettings(User $user): bool
    {
        if ($user === null) {
            $this->logger->warning('Retention settings view permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Retention settings view permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('audit', 'view_retention')) {
            $this->logger->info('Retention settings view permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Retention settings view permission granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }

    public function canDeleteAuditLogs(User $user): bool
    {
        if ($user === null) {
            $this->logger->warning('Audit logs delete permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Audit logs delete permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('audit', 'delete')) {
            $this->logger->info('Audit logs delete permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Audit logs delete permission granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }

    public function canArchiveAuditLogs(User $user): bool
    {
        if ($user === null) {
            $this->logger->warning('Audit logs archive permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Audit logs archive permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('audit', 'archive')) {
            $this->logger->info('Audit logs archive permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Audit logs archive permission granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }
}
