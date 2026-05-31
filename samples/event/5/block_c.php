<?php
declare(strict_types=1);

namespace App\Security\Handlers;

use App\Entity\User;
use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use App\Service\SessionService;
use App\Service\NotificationService;
use App\Service\TokenService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class TwoFactorEnabledEventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QueueService $queueService,
        private readonly SessionService $sessionService,
        private readonly NotificationService $notificationService,
        private readonly TokenService $tokenService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(User $user, string $method, string $ipAddress, string $userAgent): void
    {
        $this->logger->info('Processing 2FA enabled event', [
            'user_id' => $user->getId(),
            'method' => $method,
        ]);

        $this->entityManager->beginTransaction();
        try {
            $this->activateTwoFactorMethod($user, $method);
            $this->generateBackupCodes($user);
            $this->invalidateNonTwoFactorSessions($user);
            $this->sendEnableConfirmation($user, $method);
            $this->sendAdminAlert($user);
            $this->updateSecurityLog($user, $method, $ipAddress, $userAgent);
            $this->recordSecurityAnalytics($user, $method);
            $this->createAuditEntry($user, $method);
            $this->triggerSecurityTraining($user);

            $this->entityManager->commit();

            $this->logger->info('2FA enabled event processed', [
                'user_id' => $user->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process 2FA enabled event', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function activateTwoFactorMethod(User $user, string $method): void
    {
        $user->setTwoFactorEnabled(true);
        $user->setTwoFactorMethod($method);
        $user->setTwoFactorEnabledAt(new \DateTimeImmutable());

        $this->entityManager->persist($user);

        $this->logger->debug('Activated two-factor authentication', [
            'user_id' => $user->getId(),
            'method' => $method,
        ]);
    }

    private function generateBackupCodes(User $user): void
    {
        $existingCodes = $this->entityManager
            ->getRepository(\App\Entity\BackupCode::class)
            ->findByUser($user->getId());

        foreach ($existingCodes as $code) {
            $code->setStatus('revoked');
            $code->setRevokedAt(new \DateTimeImmutable());
            $this->entityManager->persist($code);
        }

        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $code = bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(4));
            $codes[] = $code;

            $backupCode = new \App\Entity\BackupCode();
            $backupCode->setUser($user);
            $backupCode->setCode(hash('sha256', $code));
            $backupCode->setStatus('active');
            $backupCode->setCreatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($backupCode);
        }

        $this->queueService->publish('email.backup_codes', [
            'template' => '2fa_backup_codes',
            'recipient' => $user->getEmail(),
            'variables' => [
                'codes' => $codes,
                'user_name' => $user->getDisplayName(),
            ],
            'priority' => 'high',
            'encrypted' => true,
        ]);

        $this->logger->debug('Generated backup codes', [
            'user_id' => $user->getId(),
            'code_count' => count($codes),
        ]);
    }

    private function invalidateNonTwoFactorSessions(User $user): void
    {
        $sessions = $this->entityManager
            ->getRepository(\App\Entity\UserSession::class)
            ->findActiveByUser($user->getId());

        $invalidatedCount = 0;
        foreach ($sessions as $session) {
            if (!$session->getTwoFactorVerified()) {
                $session->setStatus('invalidated');
                $session->setInvalidatedAt(new \DateTimeImmutable());
                $session->setInvalidationReason('2fa_enabled');

                $this->entityManager->persist($session);

                $this->queueService->publish('session.invalidate', [
                    'session_id' => $session->getId(),
                    'user_id' => $user->getId(),
                    'reason' => '2fa_enabled',
                ]);

                $invalidatedCount++;
            }
        }

        $this->logger->debug('Invalidated non-2FA sessions', [
            'user_id' => $user->getId(),
            'invalidated_count' => $invalidatedCount,
        ]);
    }

    private function sendEnableConfirmation(User $user, string $method): void
    {
        $template = $this->entityManager
            ->getRepository(\App\Entity\EmailTemplate::class)
            ->findOneBy(['code' => '2fa_enabled']);

        if ($template === null) {
            return;
        }

        $this->queueService->publish('email.security', [
            'template_id' => $template->getId(),
            'recipient' => $user->getEmail(),
            'variables' => [
                'user_name' => $user->getDisplayName(),
                'method' => $method,
                'enabled_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'backup_codes_url' => '/account/2fa/backup-codes',
            ],
            'priority' => 'high',
            'category' => 'security',
        ]);

        if ($user->getPhone() && $method === 'sms') {
            $this->queueService->publish('sms.security', [
                'recipient' => $user->getPhone(),
                'message' => sprintf(
                    'Two-factor authentication has been enabled on your account using %s. If you did not enable 2FA, contact support immediately.',
                    $method
                ),
            ]);
        }

        $this->logger->debug('Sent 2FA enable confirmation', [
            'user_id' => $user->getId(),
        ]);
    }

    private function sendAdminAlert(User $user): void
    {
        $admins = $this->entityManager
            ->getRepository(\App\Entity\User::class)
            ->findByRole('admin');

        foreach ($admins as $admin) {
            $notification = new \App\Entity\AdminNotification();
            $notification->setUser($admin);
            $notification->setType('2fa_enabled');
            $notification->setTitle('2FA Enabled');
            $notification->setBody(sprintf(
                'User %s has enabled two-factor authentication using %s.',
                $user->getEmail(),
                $user->getTwoFactorMethod()
            ));
            $notification->setMetadata([
                'user_id' => $user->getId(),
                'method' => $user->getTwoFactorMethod(),
            ]);
            $notification->setCreatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($notification);
        }

        $this->logger->debug('Sent admin alert', [
            'user_id' => $user->getId(),
            'admin_count' => count($admins),
        ]);
    }

    private function updateSecurityLog(User $user, string $method, string $ipAddress, string $userAgent): void
    {
        $securityLog = new \App\Entity\SecurityLog();
        $securityLog->setUser($user);
        $securityLog->setEventType('2fa_enabled');
        $securityLog->setIpAddress($ipAddress);
        $securityLog->setUserAgent($userAgent);
        $securityLog->setRiskLevel('low');
        $securityLog->setMetadata([
            'method' => $method,
        ]);
        $securityLog->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($securityLog);

        $this->logger->debug('Updated security log', [
            'user_id' => $user->getId(),
            'event' => '2fa_enabled',
        ]);
    }

    private function recordSecurityAnalytics(User $user, string $method): void
    {
        $analyticsEvent = new AnalyticsEvent();
        $analyticsEvent->setEventName('2fa_enabled');
        $analyticsEvent->setCustomerId($user->getId());
        $analyticsEvent->setPayload([
            'user_id' => $user->getId(),
            'method' => $method,
            'account_age_days' => (new \DateTimeImmutable())->diff($user->getCreatedAt())->days,
        ]);
        $analyticsEvent->setOccurredAt(new \DateTimeImmutable());

        $this->entityManager->persist($analyticsEvent);

        $this->logger->debug('Recorded security analytics', [
            'user_id' => $user->getId(),
            'event' => '2fa_enabled',
        ]);
    }

    private function createAuditEntry(User $user, string $method): void
    {
        $auditEntry = new AuditLog();
        $auditEntry->setAction('TWO_FACTOR_ENABLED');
        $auditEntry->setEntityType('user');
        $auditEntry->setEntityId($user->getId());
        $auditEntry->setUserId($user->getId());
        $auditEntry->setMetadata([
            'method' => $method,
            'enabled_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ]);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($auditEntry);

        $this->logger->debug('Created audit log entry', [
            'user_id' => $user->getId(),
            'action' => 'TWO_FACTOR_ENABLED',
        ]);
    }

    private function triggerSecurityTraining(User $user): void
    {
        $trainingModules = $this->entityManager
            ->getRepository(\App\Entity\TrainingModule::class)
            ->findByCategory('security');

        foreach ($trainingModules as $module) {
            $enrollment = new \App\Entity\TrainingEnrollment();
            $enrollment->setUser($user);
            $enrollment->setModule($module);
            $enrollment->setStatus('recommended');
            $enrollment->setRecommendedAt(new \DateTimeImmutable());
            $enrollment->setCreatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($enrollment);

            $this->queueService->publish('training.recommend', [
                'user_id' => $user->getId(),
                'module_id' => $module->getId(),
                'reason' => '2fa_enabled',
            ]);
        }

        $this->logger->debug('Triggered security training', [
            'user_id' => $user->getId(),
            'module_count' => count($trainingModules),
        ]);
    }
}
