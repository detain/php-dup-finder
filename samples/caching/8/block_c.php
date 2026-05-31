<?php
declare(strict_types=1);

namespace App\Caching\Handlers;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Repository\ActivityLogRepository;
use App\Repository\UserRepository;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class ActivityLogCacheHandler
{
    private const CACHE_PREFIX = 'activity_log';
    private const DEFAULT_TTL = 1800;
    private const STALE_TTL = 300;

    public function __construct(
        private readonly CacheService $cache,
        private readonly CacheKeyBuilder $keyBuilder,
        private readonly ActivityLogRepository $activityLogRepository,
        private readonly UserRepository $userRepository,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getActivityLog(int $logId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildActivityLogCacheKey($logId);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'activity_log']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'activity_log']);
        $activityLog = $this->activityLogRepository->find($logId);

        if ($activityLog === null) {
            return null;
        }

        $data = $this->serializeActivityLog($activityLog);
        $this->setActivityLog($logId, $data);
        return $data;
    }

    public function setActivityLog(int $logId, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildActivityLogCacheKey($logId);
        $ttl = $ttl ?? self::DEFAULT_TTL;
        $this->cache->set($cacheKey, $data, $ttl);
    }

    public function invalidateActivityLog(int $logId): void
    {
        $cacheKey = $this->buildActivityLogCacheKey($logId);
        $this->cache->delete($cacheKey);
    }

    public function invalidateUserActivityLogs(int $userId): void
    {
        $activityLogs = $this->activityLogRepository->findByUserId($userId);
        $cacheKeys = array_map(
            fn($log) => $this->buildActivityLogCacheKey($log->getId()),
            $activityLogs
        );

        if (!empty($cacheKeys)) {
            $this->cache->deleteMultiple($cacheKeys);
        }

        $this->invalidateUserActivitySummary($userId);
        $this->logger->info('Invalidated activity logs for user', [
            'user_id' => $userId,
            'log_count' => count($activityLogs),
        ]);
    }

    public function invalidateEntityActivityLogs(string $entityType, int $entityId): void
    {
        $activityLogs = $this->activityLogRepository->findByEntity($entityType, $entityId);
        $cacheKeys = array_map(
            fn($log) => $this->buildActivityLogCacheKey($log->getId()),
            $activityLogs
        );

        if (!empty($cacheKeys)) {
            $this->cache->deleteMultiple($cacheKeys);
        }

        $this->logger->info('Invalidated activity logs for entity', [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'log_count' => count($activityLogs),
        ]);
    }

    public function refreshActivityLog(int $logId): void
    {
        $cacheKey = $this->buildActivityLogCacheKey($logId);
        $activityLog = $this->activityLogRepository->find($logId);

        if ($activityLog === null) {
            $this->cache->delete($cacheKey);
            return;
        }

        $data = $this->serializeActivityLog($activityLog);
        $this->setActivityLog($logId, $data);
    }

    public function warmUser(int $userId): void
    {
        $activityLogs = $this->activityLogRepository->findRecentByUserId($userId, 100);

        foreach ($activityLogs as $log) {
            $data = $this->serializeActivityLog($log);
            $this->setActivityLog($log->getId(), $data, self::DEFAULT_TTL);
        }

        $this->logger->debug('Warmed activity log cache for user', [
            'user_id' => $userId,
            'logs_warmed' => count($activityLogs),
        ]);
    }

    public function handleCreateActivityLog(int $logId): void
    {
        $activityLog = $this->activityLogRepository->find($logId);
        if ($activityLog === null) {
            return;
        }

        $this->invalidateUserActivityLogs($activityLog->getUserId());

        if ($activityLog->getEntityType() !== null && $activityLog->getEntityId() !== null) {
            $this->invalidateEntityActivityLogs($activityLog->getEntityType(), $activityLog->getEntityId());
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'create_activity_log',
            'log_id' => (string) $logId,
        ]);
    }

    public function handleDeleteActivityLog(int $logId): void
    {
        $activityLog = $this->activityLogRepository->find($logId);
        if ($activityLog !== null) {
            $this->invalidateActivityLog($logId);
            $this->invalidateUserActivityLogs($activityLog->getUserId());
        }

        $this->logger->info('Handled activity log deletion cache invalidation', [
            'log_id' => $logId,
        ]);
    }

    public function handleDeleteUserActivityLogs(int $userId): void
    {
        $activityLogs = $this->activityLogRepository->findByUserId($userId);

        foreach ($activityLogs as $log) {
            $this->invalidateActivityLog($log->getId());
        }

        $this->invalidateUserActivitySummary($userId);

        $this->logger->info('Handled delete user activity logs cache invalidation', [
            'user_id' => $userId,
        ]);
    }

    public function handlePrivacySettingsChange(int $userId): void
    {
        $this->invalidateUserActivityLogs($userId);
        $this->invalidateUserActivitySummary($userId);

        $this->logger->info('Handled privacy settings change cache invalidation', [
            'user_id' => $userId,
        ]);
    }

    private function buildActivityLogCacheKey(int $logId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'log', $logId);
    }

    private function buildUserActivitySummaryCacheKey(int $userId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'user', $userId, 'activity_summary');
    }

    private function invalidateUserActivitySummary(int $userId): void
    {
        $this->cache->delete($this->buildUserActivitySummaryCacheKey($userId));
    }

    private function serializeActivityLog(object $activityLog): array
    {
        return [
            'id' => $activityLog->getId(),
            'user_id' => $activityLog->getUserId(),
            'action' => $activityLog->getAction(),
            'entity_type' => $activityLog->getEntityType(),
            'entity_id' => $activityLog->getEntityId(),
            'metadata' => $activityLog->getMetadata(),
            'ip_address' => $activityLog->getIpAddress(),
            'created_at' => $activityLog->getCreatedAt()?->format(\DATE_ATOM),
        ];
    }
}
