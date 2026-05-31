<?php
declare(strict_types=1);

namespace App\Audit\Security;

use App\Domain\Entity\User;
use Psr\Log\LoggerInterface;

final readonly class AuditAlertPermissionService
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function canConfigureAlertRules(User $user): bool
    {
        if ($user === null) {
            $this->logger->warning('Alert rules configure permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Alert rules configure permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('audit', 'configure_alerts')) {
            $this->logger->info('Alert rules configure permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Alert rules configure permission granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }

    public function canViewAlertRules(User $user): bool
    {
        if ($user === null) {
            $this->logger->warning('Alert rules view permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Alert rules view permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('audit', 'view_alerts')) {
            $this->logger->info('Alert rules view permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Alert rules view permission granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }

    public function canDeleteAlertRule(User $user, string $ruleId): bool
    {
        if ($user === null) {
            $this->logger->warning('Alert rule delete permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Alert rule delete permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'rule_id' => $ruleId,
            ]);
            return false;
        }

        if (!$user->hasPermission('audit', 'delete_alerts')) {
            $this->logger->info('Alert rule delete permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
                'rule_id' => $ruleId,
            ]);
            return false;
        }

        $this->logger->debug('Alert rule delete permission granted', [
            'user_id' => $user->getId()->toString(),
            'rule_id' => $ruleId,
        ]);

        return true;
    }

    public function canViewAlertHistory(User $user): bool
    {
        if ($user === null) {
            $this->logger->warning('Alert history view permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Alert history view permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        if (!$user->hasPermission('audit', 'view_alert_history')) {
            $this->logger->info('Alert history view permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
            ]);
            return false;
        }

        $this->logger->debug('Alert history view permission granted', [
            'user_id' => $user->getId()->toString(),
        ]);

        return true;
    }

    public function canAcknowledgeAlert(User $user, string $alertId): bool
    {
        if ($user === null) {
            $this->logger->warning('Alert acknowledge permission denied: null user');
            return false;
        }

        if (!$user->isActive()) {
            $this->logger->info('Alert acknowledge permission denied: user inactive', [
                'user_id' => $user->getId()->toString(),
                'alert_id' => $alertId,
            ]);
            return false;
        }

        if (!$user->hasPermission('audit', 'acknowledge_alerts')) {
            $this->logger->info('Alert acknowledge permission denied: missing permission', [
                'user_id' => $user->getId()->toString(),
                'alert_id' => $alertId,
            ]);
            return false;
        }

        $this->logger->debug('Alert acknowledge permission granted', [
            'user_id' => $user->getId()->toString(),
            'alert_id' => $alertId,
        ]);

        return true;
    }
}
