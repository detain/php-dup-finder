<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Service\MailService;
use App\Repository\SubscriptionRepository;
use Psr\Log\LoggerInterface;

final class SendSubscriptionRenewalJob
{
    public function __construct(
        private readonly MailService $mailService,
        private readonly SubscriptionRepository $subscriptionRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function execute(int $subscriptionId): bool
    {
        $subscription = $this->subscriptionRepository->find($subscriptionId);

        if ($subscription === null) {
            $this->logger->error('Subscription not found for renewal email', [
                'subscription_id' => $subscriptionId,
            ]);
            return false;
        }

        if ($subscription->getStatus() !== 'active') {
            $this->logger->info('Subscription not active, skipping renewal notice', [
                'subscription_id' => $subscriptionId,
                'status' => $subscription->getStatus(),
            ]);
            return true;
        }

        try {
            $result = $this->mailService->send(
                $subscription->getCustomer()->getEmail(),
                'subscription_renewal',
                [
                    'subscription_plan' => $subscription->getPlanName(),
                    'customer_name' => $subscription->getCustomer()->getName(),
                    'renewal_date' => $subscription->getRenewalDate()->format('Y-m-d'),
                    'amount' => $subscription->getMonthlyAmount(),
                ]
            );

            if ($result) {
                $this->logger->info('Subscription renewal email sent', [
                    'subscription_id' => $subscriptionId,
                    'customer_email' => $subscription->getCustomer()->getEmail(),
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->error('Failed to send subscription renewal email', [
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
