<?php
declare(strict_types=1);

namespace Caching\Workflow;

use Psr\Log\LoggerInterface;

final class ProductCacheWarmupWorkflow
{
    private const BATCH_SIZE = 100;
    private const CACHE_TTL_SECONDS = 7200;
    private const STALE_THRESHOLD_DAYS = 14;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ProductRepository $productRepository,
        private readonly CacheManager $cache,
    ) {}

    public function execute(CacheWarmupRequest $request): WarmupResult
    {
        $this->logger->info('Starting product cache warmup workflow', [
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
        $this->logger->info('Product cache warmup workflow completed', [
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
            'bestsellers' => $this->productRepository->findBestsellers(500),
            'recently_updated' => $this->productRepository->findRecentlyUpdated(self::STALE_THRESHOLD_DAYS),
            'featured' => $this->productRepository->findFeaturedProducts(),
            'all_active' => $this->productRepository->findAllActiveProducts(),
            default => $this->productRepository->findAllActiveProducts(),
        };
    }

    private function generateCacheKeys(array $records): array
    {
        $keys = [];

        foreach ($records as $record) {
            $keys[] = new CacheKey(
                key: sprintf('product:%s', $record->getSku()),
                recordId: $record->getId(),
                recordType: 'product',
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
            'product' => $this->productRepository->findById($cacheKey->recordId),
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
