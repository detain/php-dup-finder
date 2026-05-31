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

final class PasswordChangedEventHandler
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

    public function handle(User $user, string $ipAddress, string $userAgent): void
    {
        $this->logger->info('Processing password changed event', [
            'user_id' => $user->getId(),
            'email' => $user->getEmail(),
        ]);

        $this->entityManager->beginTransaction();
        try {
            $this->invalidateExistingSessions($user);
            $this->revokeActiveTokens($user);
            $this->sendSecurityNotification($user);
            $this->updateSecurityLog($user, $ipAddress, $userAgent);
            $this->recordSecurityAnalytics($user);
            $this->createAuditEntry($user);
            $this->notifySecurityTeam($user);
            $this->updateSecurityScore($user);

            $this->entityManager->commit();

            $this->logger->info('Password changed event processed', [
                'user_id' => $user->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process password changed event', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function invalidateExistingSessions(User $user): void
    {
        $sessions = $this->entityManager
            ->getRepository(\App\Entity\UserSession::class)
            ->findActiveByUser($user->getId());

        foreach ($sessions as $session) {
            $session->setStatus('invalidated');
            $session->setInvalidatedAt(new \DateTimeImmutable());
            $session->setInvalidationReason('password_changed');

            $this->entityManager->persist($session);

            $this->queueService->publish('session.invalidate', [
                'session_id' => $session->getId(),
                'user_id' => $user->getId(),
                'reason' => 'password_changed',
            ]);
        }

        $this->logger->debug('Invalidated existing sessions', [
            'user_id' => $user->getId(),
            'session_count' => count($sessions),
        ]);
    }

    private function revokeActiveTokens(User $user): void
    {
        $tokens = $this->entityManager
            ->getRepository(\App\Entity\RefreshToken::class)
            ->findActiveByUser($user->getId());

        foreach ($tokens as $token) {
            $token->setStatus('revoked');
            $token->setRevokedAt(new \DateTimeImmutable());
            $token->setRevocationReason('password_changed');

            $this->entityManager->persist($token);
        }

        $this->tokenService->revokeAllUserTokens($user->getId(), 'password_changed');

        $this->logger->debug('Revoked active tokens', [
            'user_id' => $user->getId(),
            'token_count' => count($tokens),
        ]);
    }

    private function sendSecurityNotification(User $user): void
    {
        $template = $this->entityManager
            ->getRepository(\App\Entity\EmailTemplate::class)
            ->findOneBy(['code' => 'password_changed']);

        if ($template === null) {
            return;
        }

        $variables = [
            'first_name' => $user->getFirstName(),
            'email' => $user->getEmail(),
            'changed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'support_url' => '/help/security',
        ];

        $this->queueService->publish('email.security', [
            'template_id' => $template->getId(),
            'recipient' => $user->getEmail(),
            'variables' => $variables,
            'priority' => 'high',
            'category' => 'security',
        ]);

        if ($user->getPhone()) {
            $this->queueService->publish('sms.security', [
                'recipient' => $user->getPhone(),
                'message' => sprintf(
                    'Security Alert: Your password was changed on %s. If this wasn\'t you, contact support immediately.',
                    (new \DateTimeImmutable())->format('Y-m-d H:i')
                ),
            ]);
        }

        $this->logger->debug('Sent security notification', [
            'user_id' => $user->getId(),
        ]);
    }

    private function updateSecurityLog(User $user, string $ipAddress, string $userAgent): void
    {
        $securityLog = new \App\Entity\SecurityLog();
        $securityLog->setUser($user);
        $securityLog->setEventType('password_changed');
        $securityLog->setIpAddress($ipAddress);
        $securityLog->setUserAgent($userAgent);
        $securityLog->setRiskLevel('medium');
        $securityLog->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($securityLog);

        $this->logger->debug('Updated security log', [
            'user_id' => $user->getId(),
            'event' => 'password_changed',
        ]);
    }

    private function recordSecurityAnalytics(User $user): void
    {
        $analyticsEvent = new AnalyticsEvent();
        $analyticsEvent->setEventName('password_changed');
        $analyticsEvent->setCustomerId($user->getId());
        $analyticsEvent->setPayload([
            'user_id' => $user->getId(),
            'account_age_days' => (new \DateTimeImmutable())->diff($user->getCreatedAt())->days,
            'last_password_change' => $user->getLastPasswordChangeAt()?->format(\DATE_ATOM),
        ]);
        $analyticsEvent->setOccurredAt(new \DateTimeImmutable());

        $this->entityManager->persist($analyticsEvent);

        $this->logger->debug('Recorded security analytics', [
            'user_id' => $user->getId(),
            'event' => 'password_changed',
        ]);
    }

    private function createAuditEntry(User $user): void
    {
        $auditEntry = new AuditLog();
        $auditEntry->setAction('PASSWORD_CHANGED');
        $auditEntry->setEntityType('user');
        $auditEntry->setEntityId($user->getId());
        $auditEntry->setUserId($user->getId());
        $auditEntry->setMetadata([
            'email' => $user->getEmail(),
            'password_changed_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ]);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($auditEntry);

        $this->logger->debug('Created audit log entry', [
            'user_id' => $user->getId(),
            'action' => 'PASSWORD_CHANGED',
        ]);
    }

    private function notifySecurityTeam(User $user): void
    {
        $suspiciousActivity = $this->entityManager
            ->getRepository(\App\Entity\SecurityLog::class)
            ->countRecentByUser($user->getId(), '24 hours');

        if ($suspiciousActivity > 5) {
            $securityTeam = $this->entityManager
                ->getRepository(\App\Entity\User::class)
                ->findByRole('security');

            foreach ($securityTeam as $member) {
                $notification = new \App\Entity\SecurityAlert();
                $notification->setUser($member);
                $notification->setType('high_risk_password_change');
                $notification->setTitle('High-Risk Password Change');
                $notification->setBody(sprintf(
                    'User %s changed their password. %d security events in the last 24 hours.',
                    $user->getEmail(),
                    $suspiciousActivity
                ));
                $notification->setPriority('high');
                $notification->setMetadata([
                    'user_id' => $user->getId(),
                    'event_count' => $suspiciousActivity,
                ]);
                $notification->setCreatedAt(new \DateTimeImmutable());

                $this->entityManager->persist($notification);
            }

            $this->logger->warning('Notified security team of suspicious activity', [
                'user_id' => $user->getId(),
                'event_count' => $suspiciousActivity,
            ]);
        }
    }

    private function updateSecurityScore(User $user): void
    {
        $user->setLastPasswordChangeAt(new \DateTimeImmutable());
        $user->setPasswordChangeCount($user->getPasswordChangeCount() + 1);

        $this->entityManager->persist($user);

        $this->logger->debug('Updated security score', [
            'user_id' => $user->getId(),
        ]);
    }
}
