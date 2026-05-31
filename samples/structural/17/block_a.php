<?php
declare(strict_types=1);

namespace Caching\Workflow;

use Psr\Log\LoggerInterface;

final class UserCacheWarmupWorkflow
{
    private const BATCH_SIZE = 100;
    private const CACHE_TTL_SECONDS = 3600;
    private const STALE_THRESHOLD_DAYS = 7;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly UserRepository $userRepository,
        private readonly CacheManager $cache,
    ) {}

    public function execute(CacheWarmupRequest $request): WarmupResult
    {
        $this->logger->info('Starting user cache warmup workflow', [
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
        $this->logger->info('User cache warmup workflow completed', [
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
            'frequent_users' => $this->userRepository->findFrequentUsers(1000),
            'recent_users' => $this->userRepository->findRecentUsers(self::STALE_THRESHOLD_DAYS),
            'vip_users' => $this->userRepository->findVipUsers(),
            'all_active' => $this->userRepository->findAllActiveUsers(),
            default => $this->userRepository->findAllActiveUsers(),
        };
    }

    private function generateCacheKeys(array $records): array
    {
        $keys = [];

        foreach ($records as $record) {
            $keys[] = new CacheKey(
                key: sprintf('user:%s', $record->getId()),
                recordId: $record->getId(),
                recordType: 'user',
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
            'user' => $this->userRepository->findById($cacheKey->recordId),
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
