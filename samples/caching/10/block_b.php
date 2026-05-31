<?php
declare(strict_types=1);

namespace App\Caching\Handlers;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Repository\SubscriptionRepository;
use App\Repository\PlanRepository;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class SubscriptionCacheHandler
{
    private const CACHE_PREFIX = 'subscription';
    private const DEFAULT_TTL = 3600;
    private const STALE_TTL = 600;

    public function __construct(
        private readonly CacheService $cache,
        private readonly CacheKeyBuilder $keyBuilder,
        private readonly SubscriptionRepository $subscriptionRepository,
        private readonly PlanRepository $planRepository,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getSubscription(int $subscriptionId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildSubscriptionCacheKey($subscriptionId);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'subscription']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'subscription']);
        $subscription = $this->subscriptionRepository->find($subscriptionId);

        if ($subscription === null) {
            return null;
        }

        $data = $this->serializeSubscription($subscription);
        $this->setSubscription($subscriptionId, $data);
        return $data;
    }

    public function setSubscription(int $subscriptionId, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildSubscriptionCacheKey($subscriptionId);
        $ttl = $ttl ?? self::DEFAULT_TTL;
        $this->cache->set($cacheKey, $data, $ttl);
    }

    public function invalidateSubscription(int $subscriptionId): void
    {
        $cacheKey = $this->buildSubscriptionCacheKey($subscriptionId);
        $this->cache->delete($cacheKey);
    }

    public function invalidateCustomerSubscriptions(int $customerId): void
    {
        $subscriptions = $this->subscriptionRepository->findByCustomerId($customerId);
        $cacheKeys = array_map(
            fn($subscription) => $this->buildSubscriptionCacheKey($subscription->getId()),
            $subscriptions
        );

        if (!empty($cacheKeys)) {
            $this->cache->deleteMultiple($cacheKeys);
        }

        $this->invalidateCustomerSubscriptionStatus($customerId);
        $this->logger->info('Invalidated subscriptions for customer', [
            'customer_id' => $customerId,
            'subscription_count' => count($subscriptions),
        ]);
    }

    public function refreshSubscription(int $subscriptionId): void
    {
        $cacheKey = $this->buildSubscriptionCacheKey($subscriptionId);
        $subscription = $this->subscriptionRepository->find($subscriptionId);

        if ($subscription === null) {
            $this->cache->delete($cacheKey);
            return;
        }

        $data = $this->serializeSubscription($subscription);
        $this->setSubscription($subscriptionId, $data);
    }

    public function warmCustomer(int $customerId): void
    {
        $subscriptions = $this->subscriptionRepository->findByCustomerId($customerId);

        foreach ($subscriptions as $subscription) {
            $data = $this->serializeSubscription($subscription);
            $this->setSubscription($subscription->getId(), $data, self::DEFAULT_TTL);
        }

        $this->logger->debug('Warmed subscription cache for customer', [
            'customer_id' => $customerId,
            'subscriptions_warmed' => count($subscriptions),
        ]);
    }

    public function handleCreateSubscription(int $subscriptionId): void
    {
        $subscription = $this->subscriptionRepository->find($subscriptionId);
        if ($subscription === null) {
            return;
        }

        $this->invalidateCustomerSubscriptions($subscription->getCustomerId());

        $this->metrics->increment('cache.invalidation', [
            'type' => 'create_subscription',
            'subscription_id' => (string) $subscriptionId,
        ]);
    }

    public function handleUpdateSubscription(int $subscriptionId): void
    {
        $this->invalidateSubscription($subscriptionId);

        $subscription = $this->subscriptionRepository->find($subscriptionId);
        if ($subscription === null) {
            return;
        }

        $updateKeys = [
            $this->keyBuilder->build('subscription', $subscriptionId, 'billing_info'),
            $this->keyBuilder->build('subscription', $subscriptionId, 'addons'),
        ];

        foreach ($updateKeys as $key) {
            $this->cache->delete($key);
        }

        $this->logger->info('Handled subscription update cache invalidation', [
            'subscription_id' => $subscriptionId,
        ]);
    }

    public function handleCancelSubscription(int $subscriptionId): void
    {
        $this->invalidateSubscription($subscriptionId);

        $subscription = $this->subscriptionRepository->find($subscriptionId);
        if ($subscription !== null) {
            $this->invalidateCustomerSubscriptions($subscription->getCustomerId());
        }

        $this->logger->info('Handled cancel subscription cache invalidation', [
            'subscription_id' => $subscriptionId,
        ]);
    }

    public function handleRenewSubscription(int $subscriptionId): void
    {
        $this->invalidateSubscription($subscriptionId);

        $renewalKey = $this->keyBuilder->build('subscription', $subscriptionId, 'renewal_info');
        $this->cache->delete($renewalKey);

        $subscription = $this->subscriptionRepository->find($subscriptionId);
        if ($subscription !== null) {
            $this->invalidateCustomerSubscriptions($subscription->getCustomerId());
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'renew_subscription',
            'subscription_id' => (string) $subscriptionId,
        ]);
    }

    public function handleChangePlan(int $subscriptionId): void
    {
        $this->invalidateSubscription($subscriptionId);

        $planKey = $this->keyBuilder->build('subscription', $subscriptionId, 'plan_details');
        $this->cache->delete($planKey);

        $subscription = $this->subscriptionRepository->find($subscriptionId);
        if ($subscription !== null) {
            $this->invalidateCustomerSubscriptions($subscription->getCustomerId());
        }

        $this->logger->info('Handled change plan cache invalidation', [
            'subscription_id' => $subscriptionId,
        ]);
    }

    public function handlePaymentFailed(int $subscriptionId): void
    {
        $this->invalidateSubscription($subscriptionId);

        $failureKey = $this->keyBuilder->build('subscription', $subscriptionId, 'payment_failure');
        $this->cache->delete($failureKey);

        $subscription = $this->subscriptionRepository->find($subscriptionId);
        if ($subscription !== null) {
            $this->invalidateCustomerSubscriptionStatus($subscription->getCustomerId());
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'payment_failed',
            'subscription_id' => (string) $subscriptionId,
        ]);
    }

    private function buildSubscriptionCacheKey(int $subscriptionId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'subscription', $subscriptionId);
    }

    private function buildCustomerSubscriptionStatusCacheKey(int $customerId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'customer', $customerId, 'subscription_status');
    }

    private function invalidateCustomerSubscriptionStatus(int $customerId): void
    {
        $this->cache->delete($this->buildCustomerSubscriptionStatusCacheKey($customerId));
    }

    private function serializeSubscription(object $subscription): array
    {
        return [
            'id' => $subscription->getId(),
            'customer_id' => $subscription->getCustomerId(),
            'plan_id' => $subscription->getPlanId(),
            'status' => $subscription->getStatus(),
            'current_period_start' => $subscription->getCurrentPeriodStart()?->format(\DATE_ATOM),
            'current_period_end' => $subscription->getCurrentPeriodEnd()?->format(\DATE_ATOM),
            'cancel_at_period_end' => $subscription->cancelAtPeriodEnd(),
            'created_at' => $subscription->getCreatedAt()?->format(\DATE_ATOM),
        ];
    }
}
