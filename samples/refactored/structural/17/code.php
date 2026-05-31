<?php
declare(strict_types=1);

namespace Caching\Shared;

interface CacheWarmupStrategy
{
    public function identifyTargets(CacheWarmupRequest $request): array;
    public function generateCacheKeys(array $records): array;
    public function fetchRecordForCache(CacheKey $key): mixed;
    public function getCacheTtlSeconds(): int;
}

abstract class BaseCacheWarmupWorkflow
{
    protected LoggerInterface $logger;
    protected RepositoryInterface $repository;
    protected CacheManager $cache;

    private const BATCH_SIZE = 100;
    private const DEFAULT_TTL = 3600;

    public function execute(CacheWarmupRequest $request): WarmupResult
    {
        $this->logger->info('Starting cache warmup workflow', ['strategy' => $request->getStrategy()]);

        $targets = $this->identifyTargets($request);
        $keys = $this->generateCacheKeys($targets);
        $staleKeys = $this->filterStale($keys);
        $warmed = $this->warmCache($staleKeys);
        $failures = $this->verifyIntegrity($warmed);

        $this->logger->info('Cache warmup completed', [
            'processed' => count($targets),
            'warmed' => count($warmed),
            'failures' => count($failures),
        ]);

        return new WarmupResult(
            recordsProcessed: count($targets),
            cacheKeysWarmed: count($warmed),
            verificationFailures: count($failures),
        );
    }

    protected function filterStale(array $cacheKeys): array
    {
        $stale = [];
        $now = new \DateTimeImmutable();
        $ttl = $this->getCacheTtlSeconds();

        foreach ($cacheKeys as $key) {
            $cached = $this->cache->get($key->key);
            $metadata = $cached !== null ? $this->cache->getMetadata($key->key) : null;
            $cachedAt = $metadata['cached_at'] ?? null;

            if ($cached === null || $cachedAt === null) {
                $stale[] = $key;
                continue;
            }

            if (($now->getTimestamp() - $cachedAt->getTimestamp()) > ($ttl / 2)) {
                $stale[] = $key;
            }
        }

        return $stale;
    }

    protected function warmCache(array $staleKeys): array
    {
        $warmed = [];
        $batches = array_chunk($staleKeys, self::BATCH_SIZE);

        foreach ($batches as $batch) {
            foreach ($batch as $key) {
                try {
                    $record = $this->fetchRecordForCache($key);
                    $this->cache->set($key->key, $record, $this->getCacheTtlSeconds());
                    $warmed[] = $key;
                } catch (\Throwable $e) {
                    $this->logger->warning('Warmup failed', ['key' => $key->key, 'error' => $e->getMessage()]);
                }
            }
        }

        return $warmed;
    }

    protected function verifyIntegrity(array $warmedKeys): array
    {
        return array_filter(
            $warmedKeys,
            fn($key) => $this->cache->get($key->key) === null
        );
    }

    abstract protected function identifyTargets(CacheWarmupRequest $request): array;
    abstract protected function generateCacheKeys(array $records): array;
    abstract protected function fetchRecordForCache(CacheKey $key): mixed;
    abstract protected function getCacheTtlSeconds(): int;
}

final class UserCacheWarmupWorkflow extends BaseCacheWarmupWorkflow
{
    protected function identifyTargets(CacheWarmupRequest $request): array
    {
        return $this->repository->findAllActiveUsers();
    }

    protected function generateCacheKeys(array $records): array
    {
        return array_map(fn($r) => new CacheKey("user:{$r->getId()}", $r->getId(), 'user'), $records);
    }

    protected function fetchRecordForCache(CacheKey $key): mixed
    {
        return $this->repository->findById($key->recordId);
    }

    protected function getCacheTtlSeconds(): int
    {
        return 3600;
    }
}
