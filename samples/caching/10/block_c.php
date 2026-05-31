<?php
declare(strict_types=1);

namespace App\Caching\Handlers;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Repository\RefundRepository;
use App\Repository\OrderRepository;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class RefundCacheHandler
{
    private const CACHE_PREFIX = 'refund';
    private const DEFAULT_TTL = 3600;
    private const STALE_TTL = 600;

    public function __construct(
        private readonly CacheService $cache,
        private readonly CacheKeyBuilder $keyBuilder,
        private readonly RefundRepository $refundRepository,
        private readonly OrderRepository $orderRepository,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getRefund(int $refundId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildRefundCacheKey($refundId);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'refund']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'refund']);
        $refund = $this->refundRepository->find($refundId);

        if ($refund === null) {
            return null;
        }

        $data = $this->serializeRefund($refund);
        $this->setRefund($refundId, $data);
        return $data;
    }

    public function setRefund(int $refundId, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildRefundCacheKey($refundId);
        $ttl = $ttl ?? self::DEFAULT_TTL;
        $this->cache->set($cacheKey, $data, $ttl);
    }

    public function invalidateRefund(int $refundId): void
    {
        $cacheKey = $this->buildRefundCacheKey($refundId);
        $this->cache->delete($cacheKey);
    }

    public function invalidateOrderRefunds(int $orderId): void
    {
        $refunds = $this->refundRepository->findByOrderId($orderId);
        $cacheKeys = array_map(
            fn($refund) => $this->buildRefundCacheKey($refund->getId()),
            $refunds
        );

        if (!empty($cacheKeys)) {
            $this->cache->deleteMultiple($cacheKeys);
        }

        $this->invalidateOrderRefundSummary($orderId);
        $this->logger->info('Invalidated refunds for order', [
            'order_id' => $orderId,
            'refund_count' => count($refunds),
        ]);
    }

    public function refreshRefund(int $refundId): void
    {
        $cacheKey = $this->buildRefundCacheKey($refundId);
        $refund = $this->refundRepository->find($refundId);

        if ($refund === null) {
            $this->cache->delete($cacheKey);
            return;
        }

        $data = $this->serializeRefund($refund);
        $this->setRefund($refundId, $data);
    }

    public function warmOrder(int $orderId): void
    {
        $refunds = $this->refundRepository->findByOrderId($orderId);

        foreach ($refunds as $refund) {
            $data = $this->serializeRefund($refund);
            $this->setRefund($refund->getId(), $data, self::DEFAULT_TTL);
        }

        $this->logger->debug('Warmed refund cache for order', [
            'order_id' => $orderId,
            'refunds_warmed' => count($refunds),
        ]);
    }

    public function handleCreateRefund(int $refundId): void
    {
        $refund = $this->refundRepository->find($refundId);
        if ($refund === null) {
            return;
        }

        $this->invalidateOrderRefunds($refund->getOrderId());

        $this->metrics->increment('cache.invalidation', [
            'type' => 'create_refund',
            'refund_id' => (string) $refundId,
        ]);
    }

    public function handleUpdateRefund(int $refundId): void
    {
        $this->invalidateRefund($refundId);

        $refund = $this->refundRepository->find($refundId);
        if ($refund === null) {
            return;
        }

        $updateKeys = [
            $this->keyBuilder->build('refund', $refundId, 'status_history'),
            $this->keyBuilder->build('refund', $refundId, 'tracking_info'),
        ];

        foreach ($updateKeys as $key) {
            $this->cache->delete($key);
        }

        $this->logger->info('Handled refund update cache invalidation', [
            'refund_id' => $refundId,
        ]);
    }

    public function handleApproveRefund(int $refundId): void
    {
        $this->invalidateRefund($refundId);

        $refund = $this->refundRepository->find($refundId);
        if ($refund !== null) {
            $this->invalidateOrderRefunds($refund->getOrderId());
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'approve_refund',
            'refund_id' => (string) $refundId,
        ]);
    }

    public function handleRejectRefund(int $refundId): void
    {
        $this->invalidateRefund($refundId);

        $refund = $this->refundRepository->find($refundId);
        if ($refund !== null) {
            $this->invalidateOrderRefunds($refund->getOrderId());
        }

        $this->logger->info('Handled reject refund cache invalidation', [
            'refund_id' => $refundId,
        ]);
    }

    public function handleProcessRefund(int $refundId): void
    {
        $this->invalidateRefund($refundId);

        $processKey = $this->keyBuilder->build('refund', $refundId, 'processing_info');
        $this->cache->delete($processKey);

        $refund = $this->refundRepository->find($refundId);
        if ($refund !== null) {
            $this->invalidateOrderRefunds($refund->getOrderId());
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'process_refund',
            'refund_id' => (string) $refundId,
        ]);
    }

    public function handleCompleteRefund(int $refundId): void
    {
        $this->invalidateRefund($refundId);

        $completeKey = $this->keyBuilder->build('refund', $refundId, 'completion_info');
        $this->cache->delete($completeKey);

        $refund = $this->refundRepository->find($refundId);
        if ($refund !== null) {
            $this->invalidateOrderRefunds($refund->getOrderId());
        }

        $this->logger->info('Handled complete refund cache invalidation', [
            'refund_id' => $refundId,
        ]);
    }

    private function buildRefundCacheKey(int $refundId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'refund', $refundId);
    }

    private function buildOrderRefundSummaryCacheKey(int $orderId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'order', $orderId, 'refund_summary');
    }

    private function invalidateOrderRefundSummary(int $orderId): void
    {
        $this->cache->delete($this->buildOrderRefundSummaryCacheKey($orderId));
    }

    private function serializeRefund(object $refund): array
    {
        return [
            'id' => $refund->getId(),
            'order_id' => $refund->getOrderId(),
            'amount' => $refund->getAmount(),
            'reason' => $refund->getReason(),
            'status' => $refund->getStatus(),
            'processed_at' => $refund->getProcessedAt()?->format(\DATE_ATOM),
            'created_at' => $refund->getCreatedAt()?->format(\DATE_ATOM),
        ];
    }
}
