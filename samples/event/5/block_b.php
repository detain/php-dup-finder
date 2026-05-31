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

final class EmailChangedEventHandler
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

    public function handle(User $user, string $oldEmail, string $newEmail, string $ipAddress, string $userAgent): void
    {
        $this->logger->info('Processing email changed event', [
            'user_id' => $user->getId(),
            'old_email' => $oldEmail,
            'new_email' => $newEmail,
        ]);

        $this->entityManager->beginTransaction();
        try {
            $this->verifyNewEmailOwnership($user, $newEmail);
            $this->invalidateEmailSpecificSessions($user);
            $this->revokeEmailVerificationTokens($user, $oldEmail);
            $this->sendChangeNotificationToOldEmail($user, $oldEmail, $newEmail);
            $this->sendConfirmationToNewEmail($user, $newEmail);
            $this->updateSecurityLog($user, $oldEmail, $newEmail, $ipAddress, $userAgent);
            $this->recordSecurityAnalytics($user, $oldEmail, $newEmail);
            $this->createAuditEntry($user, $oldEmail, $newEmail);
            $this->updateLinkedAccounts($user, $oldEmail, $newEmail);

            $this->entityManager->commit();

            $this->logger->info('Email changed event processed', [
                'user_id' => $user->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process email changed event', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function verifyNewEmailOwnership(User $user, string $newEmail): void
    {
        $pendingVerification = new \App\Entity\EmailChangeVerification();
        $pendingVerification->setUser($user);
        $pendingVerification->setOldEmail($user->getEmail());
        $pendingVerification->setNewEmail($newEmail);
        $pendingVerification->setVerificationToken(bin2hex(random_bytes(32)));
        $pendingVerification->setExpiresAt(
            (new \DateTimeImmutable())->modify('+24 hours')
        );
        $pendingVerification->setStatus('pending');
        $pendingVerification->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($pendingVerification);

        $this->queueService->publish('email.verification', [
            'template' => 'email_change_confirmation',
            'recipient' => $newEmail,
            'variables' => [
                'user_name' => $user->getDisplayName(),
                'verification_token' => $pendingVerification->getVerificationToken(),
                'verify_url' => sprintf('/account/verify-email-change?token=%s', $pendingVerification->getVerificationToken()),
            ],
            'priority' => 'high',
        ]);

        $this->logger->debug('Initiated email change verification', [
            'user_id' => $user->getId(),
            'verification_id' => $pendingVerification->getId(),
        ]);
    }

    private function invalidateEmailSpecificSessions(User $user): void
    {
        $sessions = $this->entityManager
            ->getRepository(\App\Entity\UserSession::class)
            ->findActiveByUser($user->getId());

        $invalidatedCount = 0;
        foreach ($sessions as $session) {
            if ($session->getEmail() === $user->getEmail()) {
                $session->setStatus('invalidated');
                $session->setInvalidatedAt(new \DateTimeImmutable());
                $session->setInvalidationReason('email_changed');

                $this->entityManager->persist($session);

                $this->queueService->publish('session.invalidate', [
                    'session_id' => $session->getId(),
                    'user_id' => $user->getId(),
                    'reason' => 'email_changed',
                ]);

                $invalidatedCount++;
            }
        }

        $this->logger->debug('Invalidated email-specific sessions', [
            'user_id' => $user->getId(),
            'invalidated_count' => $invalidatedCount,
        ]);
    }

    private function revokeEmailVerificationTokens(User $user, string $oldEmail): void
    {
        $tokens = $this->entityManager
            ->getRepository(\App\Entity\EmailVerificationToken::class)
            ->findByEmail($oldEmail);

        foreach ($tokens as $token) {
            $token->setStatus('revoked');
            $token->setRevokedAt(new \DateTimeImmutable());
            $token->setRevocationReason('email_changed');

            $this->entityManager->persist($token);
        }

        $this->logger->debug('Revoked email verification tokens', [
            'user_id' => $user->getId(),
            'token_count' => count($tokens),
        ]);
    }

    private function sendChangeNotificationToOldEmail(User $user, string $oldEmail, string $newEmail): void
    {
        $template = $this->entityManager
            ->getRepository(\App\Entity\EmailTemplate::class)
            ->findOneBy(['code' => 'email_changed_old_address']);

        if ($template === null) {
            return;
        }

        $this->queueService->publish('email.security', [
            'template_id' => $template->getId(),
            'recipient' => $oldEmail,
            'variables' => [
                'user_name' => $user->getDisplayName(),
                'old_email' => $oldEmail,
                'new_email' => $newEmail,
                'changed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'support_url' => '/help/security',
            ],
            'priority' => 'high',
            'category' => 'security',
        ]);

        $this->logger->debug('Sent notification to old email address', [
            'user_id' => $user->getId(),
            'old_email' => $oldEmail,
        ]);
    }

    private function sendConfirmationToNewEmail(User $user, string $newEmail): void
    {
        $template = $this->entityManager
            ->getRepository(\App\Entity\EmailTemplate::class)
            ->findOneBy(['code' => 'email_changed_new_address']);

        if ($template === null) {
            return;
        }

        $this->queueService->publish('email.security', [
            'template_id' => $template->getId(),
            'recipient' => $newEmail,
            'variables' => [
                'user_name' => $user->getDisplayName(),
                'new_email' => $newEmail,
                'verify_url' => sprintf('/account/verify-email-change?token=%s', $user->getId()),
            ],
            'priority' => 'high',
            'category' => 'security',
        ]);

        $this->logger->debug('Sent confirmation to new email address', [
            'user_id' => $user->getId(),
            'new_email' => $newEmail,
        ]);
    }

    private function updateSecurityLog(User $user, string $oldEmail, string $newEmail, string $ipAddress, string $userAgent): void
    {
        $securityLog = new \App\Entity\SecurityLog();
        $securityLog->setUser($user);
        $securityLog->setEventType('email_changed');
        $securityLog->setIpAddress($ipAddress);
        $securityLog->setUserAgent($userAgent);
        $securityLog->setRiskLevel('high');
        $securityLog->setMetadata([
            'old_email' => $oldEmail,
            'new_email' => $newEmail,
        ]);
        $securityLog->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($securityLog);

        $this->logger->debug('Updated security log', [
            'user_id' => $user->getId(),
            'event' => 'email_changed',
        ]);
    }

    private function recordSecurityAnalytics(User $user, string $oldEmail, string $newEmail): void
    {
        $analyticsEvent = new AnalyticsEvent();
        $analyticsEvent->setEventName('email_changed');
        $analyticsEvent->setCustomerId($user->getId());
        $analyticsEvent->setPayload([
            'user_id' => $user->getId(),
            'old_email_domain' => substr(strrchr($oldEmail, '@'), 1),
            'new_email_domain' => substr(strrchr($newEmail, '@'), 1),
            'domain_changed' => substr(strrchr($oldEmail, '@'), 1) !== substr(strrchr($newEmail, '@'), 1),
        ]);
        $analyticsEvent->setOccurredAt(new \DateTimeImmutable());

        $this->entityManager->persist($analyticsEvent);

        $this->logger->debug('Recorded security analytics', [
            'user_id' => $user->getId(),
            'event' => 'email_changed',
        ]);
    }

    private function createAuditEntry(User $user, string $oldEmail, string $newEmail): void
    {
        $auditEntry = new AuditLog();
        $auditEntry->setAction('EMAIL_CHANGED');
        $auditEntry->setEntityType('user');
        $auditEntry->setEntityId($user->getId());
        $auditEntry->setUserId($user->getId());
        $auditEntry->setMetadata([
            'old_email' => $oldEmail,
            'new_email' => $newEmail,
            'changed_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ]);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($auditEntry);

        $this->logger->debug('Created audit log entry', [
            'user_id' => $user->getId(),
            'action' => 'EMAIL_CHANGED',
        ]);
    }

    private function updateLinkedAccounts(User $user, string $oldEmail, string $newEmail): void
    {
        $linkedAccounts = $this->entityManager
            ->getRepository(\App\Entity\LinkedAccount::class)
            ->findByEmail($oldEmail);

        foreach ($linkedAccounts as $account) {
            $account->setEmail($newEmail);
            $account->setUpdatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($account);

            $this->queueService->publish('linked_accounts.sync', [
                'account_id' => $account->getId(),
                'old_email' => $oldEmail,
                'new_email' => $newEmail,
            ]);
        }

        $this->logger->debug('Updated linked accounts', [
            'user_id' => $user->getId(),
            'updated_count' => count($linkedAccounts),
        ]);
    }
}
