<?php

declare(strict_types=1);

namespace App\Processor;

use App\Entity\Subscription;
use App\Repository\SubscriptionRepository;
use App\Service\PaymentService;
use App\Service\NotificationService;
use Psr\Log\LoggerInterface;

final class SubscriptionBatchProcessor
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptionRepository,
        private readonly PaymentService $paymentService,
        private readonly NotificationService $notificationService,
        private readonly LoggerInterface $logger,
    ) {}

    public function processRenewals(array $subscriptionIds): ProcessingResult
    {
        $processed = 0;
        $failed = [];
        $subscriptions = $this->subscriptionRepository->findByIds($subscriptionIds);

        foreach ($subscriptions as $subscription) {
            try {
                $this->validateSubscriptionForRenewal($subscription);

                $paymentResult = $this->paymentService->charge($subscription);

                if ($paymentResult->isSuccess()) {
                    $subscription->extend();
                    $subscription->setLastPaymentAt(new \DateTime());

                    $this->subscriptionRepository->save($subscription);

                    $this->notificationService->sendRenewalConfirmation($subscription);

                    $this->logger->info('Subscription renewed', [
                        'subscription_id' => $subscription->getId(),
                        'next_renewal' => $subscription->getRenewalDate()->format('Y-m-d'),
                    ]);

                    $processed++;
                } else {
                    throw new \RuntimeException('Payment failed: ' . $paymentResult->getErrorMessage());
                }
            } catch (\Exception $e) {
                $failed[] = [
                    'subscription_id' => $subscription->getId(),
                    'error' => $e->getMessage(),
                ];

                $this->logger->error('Failed to process renewal', [
                    'subscription_id' => $subscription->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return new ProcessingResult($processed, count($failed), $failed);
    }

    public function processCancellations(array $subscriptionIds, string $reason): ProcessingResult
    {
        $processed = 0;
        $failed = [];
        $subscriptions = $this->subscriptionRepository->findByIds($subscriptionIds);

        foreach ($subscriptions as $subscription) {
            try {
                $this->validateSubscriptionForCancellation($subscription);

                $subscription->cancel($reason);

                $this->subscriptionRepository->save($subscription);

                $this->notificationService->sendCancellationConfirmation($subscription, $reason);

                $this->logger->info('Subscription cancelled', [
                    'subscription_id' => $subscription->getId(),
                    'reason' => $reason,
                ]);

                $processed++;
            } catch (\Exception $e) {
                $failed[] = [
                    'subscription_id' => $subscription->getId(),
                    'error' => $e->getMessage(),
                ];

                $this->logger->error('Failed to cancel subscription', [
                    'subscription_id' => $subscription->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return new ProcessingResult($processed, count($failed), $failed);
    }

    public function processUpgrades(array $subscriptionIds, string $newPlan): ProcessingResult
    {
        $processed = 0;
        $failed = [];
        $subscriptions = $this->subscriptionRepository->findByIds($subscriptionIds);

        foreach ($subscriptions as $subscription) {
            try {
                $this->validateSubscriptionForUpgrade($subscription);

                $proratedAmount = $subscription->calculateUpgradeProration($newPlan);

                $subscription->upgrade($newPlan, $proratedAmount);

                $this->subscriptionRepository->save($subscription);

                $this->notificationService->sendUpgradeConfirmation($subscription, $newPlan, $proratedAmount);

                $this->logger->info('Subscription upgraded', [
                    'subscription_id' => $subscription->getId(),
                    'new_plan' => $newPlan,
                    'proration' => $proratedAmount,
                ]);

                $processed++;
            } catch (\Exception $e) {
                $failed[] = [
                    'subscription_id' => $subscription->getId(),
                    'error' => $e->getMessage(),
                ];

                $this->logger->error('Failed to upgrade subscription', [
                    'subscription_id' => $subscription->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return new ProcessingResult($processed, count($failed), $failed);
    }

    private function validateSubscriptionForRenewal(Subscription $subscription): void
    {
        if (!$subscription->canBeRenewed()) {
            throw new \RuntimeException('Subscription cannot be renewed in current status: ' . $subscription->getStatus());
        }
    }

    private function validateSubscriptionForCancellation(Subscription $subscription): void
    {
        if (!$subscription->canBeCancelled()) {
            throw new \RuntimeException('Subscription cannot be cancelled in current status: ' . $subscription->getStatus());
        }
    }

    private function validateSubscriptionForUpgrade(Subscription $subscription): void
    {
        if (!$subscription->canBeUpgraded()) {
            throw new \RuntimeException('Subscription cannot be upgraded in current status: ' . $subscription->getStatus());
        }
    }
}
