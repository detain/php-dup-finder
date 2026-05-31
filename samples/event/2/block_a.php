<?php
declare(strict_types=1);

namespace App\Domain\Subscription\EventHandler;

use App\Entity\Subscription;
use App\Entity\AuditLog;
use App\Entity\AnalyticsEvent;
use App\Service\QueueService;
use App\Service\AccessControlService;
use App\Service\QuotaService;
use App\Service\EmailService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class SubscriptionRenewedEventHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly QueueService $queueService,
        private readonly AccessControlService $accessControlService,
        private readonly QuotaService $quotaService,
        private readonly EmailService $emailService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Subscription $subscription): void
    {
        $this->logger->info('Processing subscription renewed event', [
            'subscription_id' => $subscription->getId(),
            'user_id' => $subscription->getUserId(),
            'plan_id' => $subscription->getPlanId(),
        ]);

        $this->entityManager->beginTransaction();
        try {
            $this->extendAccess($subscription);
            $this->updateQuotaLimits($subscription);
            $this->sendRenewalReceipt($subscription);
            $this->logRenewalAnalytics($subscription);
            $this->recordAuditEntry($subscription);
            $this->notifyIntegrations($subscription);

            $this->entityManager->commit();

            $this->logger->info('Subscription renewed event processed', [
                'subscription_id' => $subscription->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to process subscription renewed event', [
                'subscription_id' => $subscription->getId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function extendAccess(Subscription $subscription): void
    {
        $currentExpiry = $subscription->getExpiresAt();
        $newExpiry = $currentExpiry->modify('+' . $subscription->getIntervalDays() . ' days');

        $subscription->setExpiresAt($newExpiry);
        $subscription->setStatus('active');
        $subscription->setAutoRenew(true);
        $subscription->setRenewedAt(new \DateTimeImmutable());

        $this->entityManager->persist($subscription);

        $this->accessControlService->refreshUserPermissions($subscription->getUserId());

        $this->logger->debug('Extended subscription access', [
            'subscription_id' => $subscription->getId(),
            'new_expiry' => $newExpiry->format(\DATE_ATOM),
        ]);
    }

    private function updateQuotaLimits(Subscription $subscription): void
    {
        $plan = $this->entityManager
            ->getRepository(\App\Entity\Plan::class)
            ->find($subscription->getPlanId());

        if ($plan === null) {
            throw new \RuntimeException('Plan not found: ' . $subscription->getPlanId());
        }

        $quota = $this->quotaService->getOrCreateUserQuota($subscription->getUserId());

        $quota->setApiCallsLimit($plan->getApiCallsLimit());
        $quota->setStorageLimit($plan->getStorageLimit());
        $quota->setTeamMembersLimit($plan->getTeamMembersLimit());
        $quota->setProjectsLimit($plan->getProjectsLimit());
        $quota->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($quota);

        $this->logger->debug('Updated quota limits', [
            'user_id' => $subscription->getUserId(),
            'plan_id' => $plan->getId(),
            'api_calls' => $plan->getApiCallsLimit(),
        ]);
    }

    private function sendRenewalReceipt(Subscription $subscription): void
    {
        $template = $this->entityManager
            ->getRepository(\App\Entity\EmailTemplate::class)
            ->findOneBy(['code' => 'subscription_renewal_receipt']);

        if ($template === null) {
            $this->logger->warning('Renewal receipt template not found');
            return;
        }

        $user = $this->entityManager
            ->getRepository(\App\Entity\User::class)
            ->find($subscription->getUserId());

        $plan = $this->entityManager
            ->getRepository(\App\Entity\Plan::class)
            ->find($subscription->getPlanId());

        $invoice = $this->entityManager
            ->getRepository(\App\Entity\Invoice::class)
            ->findOneBy(['subscriptionId' => $subscription->getId(), 'status' => 'paid']);

        $variables = [
            'first_name' => $user?->getFirstName(),
            'plan_name' => $plan?->getName(),
            'renewal_amount' => $invoice?->getTotal()->getAmount() ?? 0,
            'currency' => $invoice?->getTotal()->getCurrency() ?? 'USD',
            'next_billing_date' => $subscription->getExpiresAt()->format('Y-m-d'),
            'invoice_number' => $invoice?->getInvoiceNumber(),
        ];

        $this->queueService->publish('email.outbound', [
            'template_id' => $template->getId(),
            'recipient' => $user?->getEmail(),
            'variables' => $variables,
            'priority' => 'normal',
        ]);

        $this->logger->debug('Queued renewal receipt email', [
            'subscription_id' => $subscription->getId(),
        ]);
    }

    private function logRenewalAnalytics(Subscription $subscription): void
    {
        $analyticsEvent = new AnalyticsEvent();
        $analyticsEvent->setEventName('subscription_renewed');
        $analyticsEvent->setCustomerId($subscription->getUserId());
        $analyticsEvent->setPayload([
            'subscription_id' => $subscription->getId(),
            'plan_id' => $subscription->getPlanId(),
            'interval' => $subscription->getInterval(),
            'amount_paid' => $subscription->getLastBilledAmount(),
            'currency' => $subscription->getCurrency(),
        ]);
        $analyticsEvent->setOccurredAt(new \DateTimeImmutable());

        $this->entityManager->persist($analyticsEvent);

        $this->logger->debug('Recorded analytics event', [
            'subscription_id' => $subscription->getId(),
            'event' => 'subscription_renewed',
        ]);
    }

    private function recordAuditEntry(Subscription $subscription): void
    {
        $auditEntry = new AuditLog();
        $auditEntry->setAction('SUBSCRIPTION_RENEWED');
        $auditEntry->setEntityType('subscription');
        $auditEntry->setEntityId($subscription->getId());
        $auditEntry->setUserId($subscription->getUserId());
        $auditEntry->setMetadata([
            'plan_id' => $subscription->getPlanId(),
            'interval' => $subscription->getInterval(),
            'expires_at' => $subscription->getExpiresAt()->format(\DATE_ATOM),
            'auto_renew' => $subscription->isAutoRenew(),
        ]);
        $auditEntry->setCreatedAt(new \DateTimeImmutable());

        $this->entityManager->persist($auditEntry);

        $this->logger->debug('Created audit log entry', [
            'subscription_id' => $subscription->getId(),
            'action' => 'SUBSCRIPTION_RENEWED',
        ]);
    }

    private function notifyIntegrations(Subscription $subscription): void
    {
        $integrations = $this->entityManager
            ->getRepository(\App\Entity\UserIntegration::class)
            ->findActiveByUser($subscription->getUserId());

        foreach ($integrations as $integration) {
            if ($integration->getProvider() === 'slack') {
                $this->queueService->publish('webhook.delivery', [
                    'integration_id' => $integration->getId(),
                    'payload' => [
                        'event' => 'subscription.renewed',
                        'subscription_id' => $subscription->getId(),
                        'plan_id' => $subscription->getPlanId(),
                        'expires_at' => $subscription->getExpiresAt()->format(\DATE_ATOM),
                    ],
                ]);
            }
        }

        $this->logger->debug('Notified integrations', [
            'subscription_id' => $subscription->getId(),
            'integration_count' => count($integrations),
        ]);
    }
}
