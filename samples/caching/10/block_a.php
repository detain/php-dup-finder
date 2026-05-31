<?php
declare(strict_types=1);

namespace App\Caching\Handlers;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Repository\PaymentMethodRepository;
use App\Repository\CustomerRepository;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class PaymentMethodCacheHandler
{
    private const CACHE_PREFIX = 'payment_method';
    private const DEFAULT_TTL = 7200;
    private const STALE_TTL = 600;

    public function __construct(
        private readonly CacheService $cache,
        private readonly CacheKeyBuilder $keyBuilder,
        private readonly PaymentMethodRepository $methodRepository,
        private readonly CustomerRepository $customerRepository,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getPaymentMethod(int $methodId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildPaymentMethodCacheKey($methodId);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'payment_method']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'payment_method']);
        $method = $this->methodRepository->find($methodId);

        if ($method === null) {
            return null;
        }

        $data = $this->serializePaymentMethod($method);
        $this->setPaymentMethod($methodId, $data);
        return $data;
    }

    public function setPaymentMethod(int $methodId, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildPaymentMethodCacheKey($methodId);
        $ttl = $ttl ?? self::DEFAULT_TTL;
        $this->cache->set($cacheKey, $data, $ttl);
    }

    public function invalidatePaymentMethod(int $methodId): void
    {
        $cacheKey = $this->buildPaymentMethodCacheKey($methodId);
        $this->cache->delete($cacheKey);
    }

    public function invalidateCustomerPaymentMethods(int $customerId): void
    {
        $methods = $this->methodRepository->findByCustomerId($customerId);
        $cacheKeys = array_map(
            fn($method) => $this->buildPaymentMethodCacheKey($method->getId()),
            $methods
        );

        if (!empty($cacheKeys)) {
            $this->cache->deleteMultiple($cacheKeys);
        }

        $this->invalidateCustomerDefaultPaymentMethod($customerId);
        $this->logger->info('Invalidated payment methods for customer', [
            'customer_id' => $customerId,
            'method_count' => count($methods),
        ]);
    }

    public function refreshPaymentMethod(int $methodId): void
    {
        $cacheKey = $this->buildPaymentMethodCacheKey($methodId);
        $method = $this->methodRepository->find($methodId);

        if ($method === null) {
            $this->cache->delete($cacheKey);
            return;
        }

        $data = $this->serializePaymentMethod($method);
        $this->setPaymentMethod($methodId, $data);
    }

    public function warmCustomer(int $customerId): void
    {
        $methods = $this->methodRepository->findByCustomerId($customerId);

        foreach ($methods as $method) {
            $data = $this->serializePaymentMethod($method);
            $this->setPaymentMethod($method->getId(), $data, self::DEFAULT_TTL);
        }

        $this->logger->debug('Warmed payment method cache for customer', [
            'customer_id' => $customerId,
            'methods_warmed' => count($methods),
        ]);
    }

    public function handleAddPaymentMethod(int $methodId): void
    {
        $method = $this->methodRepository->find($methodId);
        if ($method === null) {
            return;
        }

        $this->invalidateCustomerPaymentMethods($method->getCustomerId());

        $this->metrics->increment('cache.invalidation', [
            'type' => 'add_payment_method',
            'method_id' => (string) $methodId,
        ]);
    }

    public function handleUpdatePaymentMethod(int $methodId): void
    {
        $this->invalidatePaymentMethod($methodId);

        $method = $this->methodRepository->find($methodId);
        if ($method === null) {
            return;
        }

        $updateKeys = [
            $this->keyBuilder->build('payment_method', $methodId, 'billing_address'),
            $this->keyBuilder->build('payment_method', $methodId, 'expiry_info'),
        ];

        foreach ($updateKeys as $key) {
            $this->cache->delete($key);
        }

        $this->logger->info('Handled payment method update cache invalidation', [
            'method_id' => $methodId,
        ]);
    }

    public function handleRemovePaymentMethod(int $methodId): void
    {
        $method = $this->methodRepository->find($methodId);
        if ($method !== null) {
            $this->invalidatePaymentMethod($methodId);
            $this->invalidateCustomerPaymentMethods($method->getCustomerId());
        }

        $this->logger->info('Handled payment method removal cache invalidation', [
            'method_id' => $methodId,
        ]);
    }

    public function handleSetDefaultPaymentMethod(int $methodId, int $customerId): void
    {
        $this->invalidatePaymentMethod($methodId);
        $this->invalidateCustomerDefaultPaymentMethod($customerId);

        $this->metrics->increment('cache.invalidation', [
            'type' => 'set_default_payment_method',
            'method_id' => (string) $methodId,
        ]);
    }

    public function handleExpiringCard(int $methodId): void
    {
        $this->invalidatePaymentMethod($methodId);

        $expiryKey = $this->keyBuilder->build('payment_method', $methodId, 'expiry_warning');
        $this->cache->delete($expiryKey);

        $method = $this->methodRepository->find($methodId);
        if ($method !== null) {
            $this->invalidateCustomerPaymentMethods($method->getCustomerId());
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'expiring_card',
            'method_id' => (string) $methodId,
        ]);
    }

    public function handleFailedTransaction(int $methodId): void
    {
        $this->invalidatePaymentMethod($methodId);

        $failureKey = $this->keyBuilder->build('payment_method', $methodId, 'failure_count');
        $this->cache->delete($failureKey);

        $method = $this->methodRepository->find($methodId);
        if ($method !== null) {
            $this->invalidateCustomerPaymentMethods($method->getCustomerId());
        }

        $this->logger->info('Handled failed transaction cache invalidation', [
            'method_id' => $methodId,
        ]);
    }

    public function handleVerificationComplete(int $methodId): void
    {
        $this->invalidatePaymentMethod($methodId);

        $verifyKey = $this->keyBuilder->build('payment_method', $methodId, 'verification_status');
        $this->cache->delete($verifyKey);

        $this->logger->info('Handled verification complete cache invalidation', [
            'method_id' => $methodId,
        ]);
    }

    private function buildPaymentMethodCacheKey(int $methodId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'method', $methodId);
    }

    private function buildCustomerDefaultPaymentMethodCacheKey(int $customerId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'customer', $customerId, 'default_method');
    }

    private function invalidateCustomerDefaultPaymentMethod(int $customerId): void
    {
        $this->cache->delete($this->buildCustomerDefaultPaymentMethodCacheKey($customerId));
    }

    private function serializePaymentMethod(object $method): array
    {
        return [
            'id' => $method->getId(),
            'customer_id' => $method->getCustomerId(),
            'type' => $method->getType(),
            'last_four' => $method->getLastFour(),
            'expiry_month' => $method->getExpiryMonth(),
            'expiry_year' => $method->getExpiryYear(),
            'is_default' => $method->isDefault(),
            'is_verified' => $method->isVerified(),
            'created_at' => $method->getCreatedAt()?->format(\DATE_ATOM),
        ];
    }
}
