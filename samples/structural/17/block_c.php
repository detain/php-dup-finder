<?php
declare(strict_types=1);

namespace Caching\Workflow;

use Psr\Log\LoggerInterface;

final class OrderCacheWarmupWorkflow
{
    private const BATCH_SIZE = 100;
    private const CACHE_TTL_SECONDS = 1800;
    private const STALE_THRESHOLD_HOURS = 24;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly OrderRepository $orderRepository,
        private readonly CacheManager $cache,
    ) {}

    public function execute(CacheWarmupRequest $request): WarmupResult
    {
        $this->logger->info('Starting order cache warmup workflow', [
            'strategy' => $request->getStrategy(),
        ]);

        $targetRecords = $this->identifyTargetRecords($request);
        $this->logger->debug('Target records identified', ['count' => count($targetRecords)]);

        $cacheKeysGenerated = $this->generateCacheKeys($targetRecords);
        $existingCacheState = $this->checkExistingCache($cacheKeysGenerated);
        $staleCacheKeys = $this->filterStaleCacheKeys($existingCacheState);
        $this->logger->debug('Cache state analyzed', [
            'total_keys' => count($cacheKeysGenerated),
            'stale_keys' => count($staleCacheKeys),
        ]);

        $warmedCacheKeys = $this->warmCache($staleCacheKeys);
        $this->logger->debug('Cache warmed', ['warmed' => count($warmedCacheKeys)]);

        $verificationResults = $this->verifyCacheIntegrity($warmedCacheKeys);
        $this->logger->info('Order cache warmup workflow completed', [
            'total_processed' => count($targetRecords),
            'successfully_warmed' => count($warmedCacheKeys),
            'verification_failures' => count($verificationResults),
        ]);

        return new WarmupResult(
            recordsProcessed: count($targetRecords),
            cacheKeysWarmed: count($warmedCacheKeys),
            verificationFailures: count($verificationResults),
        );
    }

    private function identifyTargetRecords(CacheWarmupRequest $request): array
    {
        $strategy = $request->getStrategy();

        return match ($strategy) {
            'pending_orders' => $this->orderRepository->findPendingOrders(),
            'recent_orders' => $this->orderRepository->findRecentOrders(self::STALE_THRESHOLD_HOURS),
            'high_value' => $this->orderRepository->findHighValueOrders(1000),
            'all_active' => $this->orderRepository->findAllActiveOrders(),
            default => $this->orderRepository->findAllActiveOrders(),
        };
    }

    private function generateCacheKeys(array $records): array
    {
        $keys = [];

        foreach ($records as $record) {
            $keys[] = new CacheKey(
                key: sprintf('order:%s', $record->getOrderNumber()),
                recordId: $record->getId(),
                recordType: 'order',
            );
        }

        return $keys;
    }

    private function checkExistingCache(array $cacheKeys): array
    {
        $cacheState = [];

        foreach ($cacheKeys as $key) {
            $cachedValue = $this->cache->get($key->key);
            $cachedAt = $cachedValue !== null ? $this->cache->getMetadata($key->key)['cached_at'] ?? null : null;

            $cacheState[] = new CacheState(
                key: $key,
                exists: $cachedValue !== null,
                cachedAt: $cachedAt,
            );
        }

        return $cacheState;
    }

    private function filterStaleCacheKeys(array $cacheStates): array
    {
        $staleKeys = [];
        $now = new \DateTimeImmutable();

        foreach ($cacheStates as $state) {
            if (!$state->exists) {
                $staleKeys[] = $state->key;
                continue;
            }

            if ($state->cachedAt === null) {
                $staleKeys[] = $state->key;
                continue;
            }

            $age = $now->getTimestamp() - $state->cachedAt->getTimestamp();

            if ($age > (self::CACHE_TTL_SECONDS / 2)) {
                $staleKeys[] = $state->key;
            }
        }

        return $staleKeys;
    }

    private function warmCache(array $staleCacheKeys): array
    {
        $warmed = [];
        $batches = array_chunk($staleCacheKeys, self::BATCH_SIZE);

        foreach ($batches as $batchIndex => $batch) {
            foreach ($batch as $cacheKey) {
                try {
                    $record = $this->fetchRecordForCache($cacheKey);

                    $this->cache->set(
                        $cacheKey->key,
                        $record,
                        self::CACHE_TTL_SECONDS
                    );

                    $warmed[] = $cacheKey;
                } catch (\Throwable $e) {
                    $this->logger->warning('Cache warmup failed for key', [
                        'key' => $cacheKey->key,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $warmed;
    }

    private function fetchRecordForCache(CacheKey $cacheKey): mixed
    {
        return match ($cacheKey->recordType) {
            'order' => $this->orderRepository->findById($cacheKey->recordId),
            default => null,
        };
    }

    private function verifyCacheIntegrity(array $warmedCacheKeys): array
    {
        $failures = [];

        foreach ($warmedCacheKeys as $cacheKey) {
            $cachedValue = $this->cache->get($cacheKey->key);

            if ($cachedValue === null) {
                $failures[] = $cacheKey;
                $this->logger->warning('Cache verification failed - value is null', [
                    'key' => $cacheKey->key,
                ]);
            }
        }

        return $failures;
    }
}
