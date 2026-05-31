<?php
declare(strict_types=1);

namespace App\Subscription\Workflow;

use App\Domain\Entity\Subscription;
use App\Domain\Entity\PaymentTransaction;
use App\Domain\Service\PaymentGatewayInterface;
use App\Domain\Service\FeatureServiceInterface;
use App\Domain\Service\NotificationServiceInterface;
use App\Domain\Repository\SubscriptionRepositoryInterface;
use Psr\Log\LoggerInterface;

final readonly class SubscriptionActivationWorkflow
{
    public function __construct(
        private SubscriptionRepositoryInterface $subscriptionRepository,
        private PaymentGatewayInterface $paymentGateway,
        private FeatureServiceInterface $featureService,
        private NotificationServiceInterface $notificationService,
        private LoggerInterface $logger,
    ) {}

    public function activateSubscription(string $subscriptionId): void
    {
        $subscription = $this->subscriptionRepository->findById($subscriptionId);
        if ($subscription === null) {
            throw new \RuntimeException("Subscription not found: {$subscriptionId}");
        }

        $this->logger->info('Starting subscription activation workflow', ['subscription_id' => $subscriptionId]);

        $this->validateSubscription($subscription);

        $this->processInitialPayment($subscription);

        $this->activateFeatures($subscription);

        $this->updateSubscriptionStatus($subscription, 'active');

        $this->sendActivationConfirmation($subscription);

        $this->recordAuditEvent($subscription, 'subscription_activated');

        $this->logger->info('Subscription activation workflow completed', ['subscription_id' => $subscriptionId]);
    }

    private function validateSubscription(Subscription $subscription): void
    {
        if ($subscription->getStatus() !== 'pending') {
            throw new \RuntimeException("Subscription {$subscription->getId()} is not in pending status");
        }

        if ($subscription->getPlan() === null) {
            throw new \RuntimeException("Subscription {$subscription->getId()} has no plan");
        }

        if ($subscription->getCustomerId() === null) {
            throw new \RuntimeException("Subscription {$subscription->getId()} has no customer");
        }

        $this->logger->debug('Subscription validation passed', ['subscription_id' => $subscription->getId()->toString()]);
    }

    private function processInitialPayment(Subscription $subscription): void
    {
        $transaction = $this->paymentGateway->charge(
            $subscription->getCustomerId(),
            $subscription->getPlan()->getAmount(),
            $subscription->getCurrency()
        );

        if (!$transaction->isSuccessful()) {
            $this->recordAuditEvent($subscription, 'initial_payment_failed', [
                'reason' => $transaction->getFailureMessage(),
            ]);
            throw new \RuntimeException("Initial payment failed: {$transaction->getFailureMessage()}");
        }

        $subscription->setPaymentTransactionId($transaction->getId());
        $this->recordAuditEvent($subscription, 'initial_payment_processed', ['transaction_id' => $transaction->getId()]);

        $this->logger->debug('Initial payment processed', [
            'subscription_id' => $subscription->getId()->toString(),
            'transaction_id' => $transaction->getId(),
        ]);
    }

    private function activateFeatures(Subscription $subscription): void
    {
        $plan = $subscription->getPlan();
        foreach ($plan->getFeatures() as $feature) {
            $result = $this->featureService->enableForCustomer(
                $subscription->getCustomerId(),
                $feature
            );

            if (!$result->isSuccessful()) {
                $this->cancelSubscription($subscription);
                $this->recordAuditEvent($subscription, 'feature_activation_failed', [
                    'feature' => $feature->getName(),
                    'reason' => $result->getMessage(),
                ]);
                throw new \RuntimeException("Feature activation failed: {$result->getMessage()}");
            }

            $this->recordAuditEvent($subscription, 'feature_activated', [
                'feature' => $feature->getName(),
            ]);
        }

        $this->logger->debug('Features activated', ['subscription_id' => $subscription->getId()->toString()]);
    }

    private function sendActivationConfirmation(Subscription $subscription): void
    {
        $this->notificationService->send(
            $subscription->getCustomerId(),
            'subscription_activated',
            [
                'subscription_id' => $subscription->getId()->toString(),
                'plan_name' => $subscription->getPlan()->getName(),
                'start_date' => $subscription->getStartDate()->format('Y-m-d'),
            ]
        );

        $this->recordAuditEvent($subscription, 'activation_confirmation_sent');

        $this->logger->debug('Activation confirmation sent', ['subscription_id' => $subscription->getId()->toString()]);
    }

    private function cancelSubscription(Subscription $subscription): void
    {
        if ($subscription->getPaymentTransactionId() !== null) {
            $this->paymentGateway->refund($subscription->getPaymentTransactionId());
        }
    }

    private function updateSubscriptionStatus(Subscription $subscription, string $status): void
    {
        $subscription->setStatus($status);
        $subscription->setUpdatedAt(new \DateTimeImmutable());
        $this->subscriptionRepository->save($subscription);
    }

    private function recordAuditEvent(Subscription $subscription, string $event, array $data = []): void
    {
        $this->logger->info('Audit event', array_merge([
            'subscription_id' => $subscription->getId()->toString(),
            'event' => $event,
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], $data));
    }
}
