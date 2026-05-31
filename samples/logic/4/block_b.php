<?php

declare(strict_types=1);

namespace App\SubscriptionWorkflow;

use App\Entity\Subscription;
use App\Repository\SubscriptionRepository;
use App\Event\SubscriptionStatusChangedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class SubscriptionStatusService
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptionRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {}

    public function activateSubscription(int $subscriptionId): Subscription
    {
        $subscription = $this->subscriptionRepository->findById($subscriptionId);

        if ($subscription === null) {
            throw new \RuntimeException('Subscription not found');
        }

        if ($subscription->getStatus() !== 'pending') {
            throw new \InvalidArgumentException('Only pending subscriptions can be activated');
        }

        if ($subscription->getCustomerId() === null) {
            throw new \InvalidArgumentException('Subscription must have a customer');
        }

        if ($subscription->getPlanId() === null) {
            throw new \InvalidArgumentException('Subscription must have a plan');
        }

        $subscription->setStatus('active');
        $subscription->setActivatedAt(new \DateTimeImmutable());
        $subscription->setCurrentPeriodStart(new \DateTimeImmutable());
        $subscription->setCurrentPeriodEnd(new \DateTimeImmutable('+1 month'));

        $this->subscriptionRepository->save($subscription);

        $this->eventDispatcher->dispatch(
            new SubscriptionStatusChangedEvent($subscription, 'pending', 'active'),
            SubscriptionStatusChangedEvent::NAME
        );

        $this->logger->info('Subscription activated', [
            'subscription_id' => $subscriptionId,
        ]);

        return $subscription;
    }

    public function pauseSubscription(int $subscriptionId): Subscription
    {
        $subscription = $this->subscriptionRepository->findById($subscriptionId);

        if ($subscription === null) {
            throw new \RuntimeException('Subscription not found');
        }

        if ($subscription->getStatus() !== 'active') {
            throw new \InvalidArgumentException('Only active subscriptions can be paused');
        }

        if ($subscription->hasOutstandingInvoices()) {
            throw new \InvalidArgumentException('Cannot pause subscription with outstanding invoices');
        }

        $subscription->setStatus('paused');
        $subscription->setPausedAt(new \DateTimeImmutable());

        $this->subscriptionRepository->save($subscription);

        $this->eventDispatcher->dispatch(
            new SubscriptionStatusChangedEvent($subscription, 'active', 'paused'),
            SubscriptionStatusChangedEvent::NAME
        );

        $this->logger->info('Subscription paused', [
            'subscription_id' => $subscriptionId,
        ]);

        return $subscription;
    }

    public function resumeSubscription(int $subscriptionId): Subscription
    {
        $subscription = $this->subscriptionRepository->findById($subscriptionId);

        if ($subscription === null) {
            throw new \RuntimeException('Subscription not found');
        }

        if ($subscription->getStatus() !== 'paused') {
            throw new \InvalidArgumentException('Only paused subscriptions can be resumed');
        }

        $subscription->setStatus('active');
        $subscription->setResumedAt(new \DateTimeImmutable());

        $this->subscriptionRepository->save($subscription);

        $this->eventDispatcher->dispatch(
            new SubscriptionStatusChangedEvent($subscription, 'paused', 'active'),
            SubscriptionStatusChangedEvent::NAME
        );

        $this->logger->info('Subscription resumed', [
            'subscription_id' => $subscriptionId,
        ]);

        return $subscription;
    }

    public function cancelSubscription(int $subscriptionId, string $reason): Subscription
    {
        $subscription = $this->subscriptionRepository->findById($subscriptionId);

        if ($subscription === null) {
            throw new \RuntimeException('Subscription not found');
        }

        if (in_array($subscription->getStatus(), ['cancelled', 'expired', 'terminated'], true)) {
            throw new \InvalidArgumentException('Subscription is already cancelled, expired, or terminated');
        }

        if ($subscription->getStatus() === 'active' && $subscription->hasActiveAddons()) {
            throw new \InvalidArgumentException('Cannot cancel subscription with active add-ons');
        }

        $subscription->setStatus('cancelled');
        $subscription->setCancelledAt(new \DateTimeImmutable());
        $subscription->setCancellationReason($reason);
        $subscription->setCanceledByCustomer(true);

        $this->subscriptionRepository->save($subscription);

        $this->eventDispatcher->dispatch(
            new SubscriptionStatusChangedEvent($subscription, $subscription->getStatus(), 'cancelled'),
            SubscriptionStatusChangedEvent::NAME
        );

        $this->logger->info('Subscription cancelled', [
            'subscription_id' => $subscriptionId,
            'reason' => $reason,
        ]);

        return $subscription;
    }
}
