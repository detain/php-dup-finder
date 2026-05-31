<?php
declare(strict_types=1);

namespace App\Account\Handlers;

use App\Entity\UserAccount;
use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use App\Service\SessionService;
use App\Service\NotificationService;
use App\Service\DataExportService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class AccountSuspendedEventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QueueService $queueService,
        private readonly SessionService $sessionService,
        private readonly NotificationService $notificationService,
        private readonly DataExportService $dataExportService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(UserAccount $account, string $reason, ?int $suspendedBy): void
    {
        $this->logger->info('Processing account suspended event', [
            'account_id' => $account->getId(),
            'user_id' => $account->getUserId(),
            'reason' => $reason,
        ]);

        $this->entityManager->beginTransaction();
        try {
            $this->suspendAccount($account, $reason);
            $this->invalidateAllSessions($account);
            $this->revokeActiveTokens($account);
            $this->sendSuspensionNotification($account, $reason);
            $this->notifyLinkedServices($account);
            $this->prepareAccountData($account);
            $this->recordSuspensionAnalytics($account, $reason);
            $this->createAuditEntry($account, $reason, $suspendedBy);
            $this->scheduleAutoReinstatement($account);

            $this->entityManager->commit();

            $this->logger->info('Account suspended event processed', [
                'account_id' => $account->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process account suspended event', [
                'account_id' => $account->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function suspendAccount(UserAccount $account, string $reason): void
    {
        $account->setStatus('suspended');
        $account->setSuspensionReason($reason);
        $account->setSuspendedAt(new \DateTimeImmutable());
        $account->setSuspensionExpiresAt(
            $this->calculateSuspensionExpiry($reason)
        );

        $this->entityManager->persist($account);

        $this->logger->debug('Suspended account', [
            'account_id' => $account->getId(),
            'reason' => $reason,
        ]);
    }

    private function invalidateAllSessions(UserAccount $account): void
    {
        $sessions = $this->entityManager
            ->getRepository(\App\Entity\UserSession::class)
            ->findActiveByUser($account->getUserId());

        foreach ($sessions as $session) {
            $session->setStatus('suspended');
            $session->setInvalidatedAt(new \DateTimeImmutable());
            $session->setInvalidationReason('account_suspended');

            $this->entityManager->persist($session);

            $this->queueService->publish('session.invalidate', [
                'session_id' => $session->getId(),
                'user_id' => $account->getUserId(),
                'reason' => 'account_suspended',
            ]);
        }

        $this->logger->debug('Invalidated all sessions', [
            'account_id' => $account->getId(),
            'session_count' => count($sessions),
        ]);
    }

    private function revokeActiveTokens(UserAccount $account): void
    {
        $tokens = $this->entityManager
            ->getRepository(\App\Entity\RefreshToken::class)
            ->findActiveByUser($account->getUserId());

        foreach ($tokens as $token) {
            $token->setStatus('revoked');
            $token->setRevokedAt(new \DateTimeImmutable());
            $token->setRevocationReason('account_suspended');

            $this->entityManager->persist($token);
        }

        $this->queueService->publish('tokens.revoke_all', [
            'user_id' => $account->getUserId(),
            'reason' => 'account_suspended',
        ]);

        $this->logger->debug('Revoked active tokens', [
            'account_id' => $account->getId(),
            'token_count' => count($tokens),
        ]);
    }

    private function sendSuspensionNotification(UserAccount $account, string $reason): void
    {
        $user = $this->entityManager
            ->getRepository(\App\Entity\User::class)
            ->find($account->getUserId());

        if ($user === null || $user->getEmail() === null) {
            return;
        }

        $template = $this->entityManager
            ->getRepository(\App\Entity\EmailTemplate::class)
            ->findOneBy(['code' => 'account_suspended']);

        if ($template === null) {
            return;
        }

        $this->queueService->publish('email.outbound', [
            'template_id' => $template->getId(),
            'recipient' => $user->getEmail(),
            'variables' => [
                'user_name' => $user->getDisplayName(),
                'suspension_reason' => $this->getHumanReadableReason($reason),
                'suspended_at' => $account->getSuspendedAt()->format('Y-m-d H:i:s'),
                'expires_at' => $account->getSuspensionExpiresAt()?->format('Y-m-d H:i:s'),
                'appeal_url' => '/account/appeal?suspension_id=' . $account->getId(),
            ],
            'priority' => 'high',
        ]);

        if ($user->getPhone()) {
            $this->queueService->publish('sms.outbound', [
                'recipient' => $user->getPhone(),
                'message' => sprintf(
                    'Your account has been suspended. Reason: %s. Visit %s for more information.',
                    $this->getHumanReadableReason($reason),
                    '/account/appeal'
                ),
            ]);
        }

        $this->logger->debug('Sent suspension notification', [
            'account_id' => $account->getId(),
            'user_email' => $user->getEmail(),
        ]);
    }

    private function notifyLinkedServices(UserAccount $account): void
    {
        $linkedServices = $this->entityManager
            ->getRepository(\App\Entity\LinkedService::class)
            ->findByUser($account->getUserId());

        foreach ($linkedServices as $service) {
            $this->queueService->publish('service.account_status', [
                'service_id' => $service->getId(),
                'user_id' => $account->getUserId(),
                'status' => 'suspended',
                'reason' => $reason ?? null,
            ]);
        }

        $this->logger->debug('Notified linked services', [
            'account_id' => $account->getId(),
            'service_count' => count($linkedServices),
        ]);
    }

    private function prepareAccountData(UserAccount $account): void
    {
        $exportJob = new \App\Entity\DataExportJob();
        $exportJob->setUserId($account->getUserId());
        $exportJob->setType('suspension_backup');
        $exportJob->setStatus('pending');
        $exportJob->setRequestedAt(new \DateTimeImmutable());
        $exportJob->setExpiresAt(
            (new \DateTimeImmutable())->modify('+30 days')
        );

        $this->entityManager->persist($exportJob);

        $this->queueService->publish('data.export.suspension_backup', [
            'job_id' => $exportJob->getId(),
            'user_id' => $account->getUserId(),
        ]);

        $accountSnapshot = new \App\Entity\AccountSnapshot();
        $accountSnapshot->setAccountId($account->getId());
        $accountSnapshot->setUserId($account->getUserId());
        $accountSnapshot->setStatus('suspended');
        $accountSnapshot->setSnapshotData(json_encode([
            'email' => $account->getEmail(),
            'subscription_status' => $account->getSubscriptionStatus(),
            'member_since' => $account->getCreatedAt()?->format(\DATE_ATOM),
            'suspension_reason' => $reason,
        ]));
        $accountSnapshot->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($accountSnapshot);

        $this->logger->debug('Prepared account data for suspension', [
            'account_id' => $account->getId(),
        ]);
    }

    private function recordSuspensionAnalytics(UserAccount $account, string $reason): void
    {
        $analyticsEvent = new AnalyticsEvent();
        $analyticsEvent->setEventName('account_suspended');
        $analyticsEvent->setCustomerId($account->getUserId());
        $analyticsEvent->setPayload([
            'account_id' => $account->getId(),
            'reason' => $reason,
            'account_age_days' => $account->getCreatedAt()
                ? (new \DateTimeImmutable())->diff($account->getCreatedAt())->days
                : 0,
            'has_active_subscription' => $account->getSubscriptionStatus() === 'active',
        ]);
        $analyticsEvent->setOccurredAt(new \DateTimeImmutable());

        $this->entityManager->persist($analyticsEvent);

        $this->logger->debug('Recorded suspension analytics', [
            'account_id' => $account->getId(),
        ]);
    }

    private function createAuditEntry(UserAccount $account, string $reason, ?int $suspendedBy): void
    {
        $auditEntry = new AuditLog();
        $auditEntry->setAction('ACCOUNT_SUSPENDED');
        $auditEntry->setEntityType('user_account');
        $auditEntry->setEntityId($account->getId());
        $auditEntry->setUserId($suspendedBy ?? $account->getUserId());
        $auditEntry->setMetadata([
            'user_id' => $account->getUserId(),
            'reason' => $reason,
            'suspended_at' => $account->getSuspendedAt()->format(\DATE_ATOM),
            'expires_at' => $account->getSuspensionExpiresAt()?->format(\DATE_ATOM),
        ]);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($auditEntry);

        $this->logger->debug('Created audit entry', [
            'account_id' => $account->getId(),
        ]);
    }

    private function scheduleAutoReinstatement(UserAccount $account): void
    {
        if ($account->getSuspensionExpiresAt() === null) {
            return;
        }

        $reinstatementJob = new \App\Entity\ScheduledJob();
        $reinstatementJob->setType('account_reinstatement');
        $reinstatementJob->setReferenceType('account');
        $reinstatementJob->setReferenceId($account->getId());
        $reinstatementJob->setScheduledFor($account->getSuspensionExpiresAt());
        $reinstatementJob->setStatus('pending');
        $reinstatementJob->setMaxRetries(3);
        $reinstatementJob->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($reinstatementJob);

        $this->queueService->publish('jobs.schedule', [
            'job_id' => $reinstatementJob->getId(),
            'type' => 'account_reinstatement',
            'scheduled_for' => $account->getSuspensionExpiresAt()->format(\DATE_ATOM),
        ]);

        $this->logger->debug('Scheduled auto reinstatement', [
            'account_id' => $account->getId(),
            'expires_at' => $account->getSuspensionExpiresAt()->format(\DATE_ATOM),
        ]);
    }

    private function calculateSuspensionExpiry(string $reason): ?\DateTimeImmutable
    {
        return match ($reason) {
            'payment_failed' => (new \DateTimeImmutable())->modify('+7 days'),
            'terms_violation' => (new \DateTimeImmutable())->modify('+30 days'),
            'investigation' => (new \DateTimeImmutable())->modify('+14 days'),
            'self_requested' => (new \DateTimeImmutable())->modify('+30 days'),
            default => null,
        };
    }

    private function getHumanReadableReason(string $reason): string
    {
        return match ($reason) {
            'payment_failed' => 'payment failure',
            'terms_violation' => 'terms of service violation',
            'investigation' => 'account investigation',
            'self_requested' => 'voluntary suspension',
            default => 'policy violation',
        };
    }
}
