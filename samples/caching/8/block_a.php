<?php
declare(strict_types=1);

namespace App\Caching\Handlers;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class NotificationCacheHandler
{
    private const CACHE_PREFIX = 'notification';
    private const DEFAULT_TTL = 900;
    private const STALE_TTL = 60;

    public function __construct(
        private readonly CacheService $cache,
        private readonly CacheKeyBuilder $keyBuilder,
        private readonly NotificationRepository $notificationRepository,
        private readonly UserRepository $userRepository,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getNotification(int $notificationId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildNotificationCacheKey($notificationId);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'notification']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'notification']);
        $notification = $this->notificationRepository->find($notificationId);

        if ($notification === null) {
            return null;
        }

        $data = $this->serializeNotification($notification);
        $this->setNotification($notificationId, $data);
        return $data;
    }

    public function setNotification(int $notificationId, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildNotificationCacheKey($notificationId);
        $ttl = $ttl ?? self::DEFAULT_TTL;
        $this->cache->set($cacheKey, $data, $ttl);
    }

    public function invalidateNotification(int $notificationId): void
    {
        $cacheKey = $this->buildNotificationCacheKey($notificationId);
        $this->cache->delete($cacheKey);
    }

    public function invalidateUserNotifications(int $userId): void
    {
        $notifications = $this->notificationRepository->findByUserId($userId);
        $cacheKeys = array_map(
            fn($notification) => $this->buildNotificationCacheKey($notification->getId()),
            $notifications
        );

        if (!empty($cacheKeys)) {
            $this->cache->deleteMultiple($cacheKeys);
        }

        $this->invalidateUserUnreadCount($userId);
        $this->logger->info('Invalidated notifications for user', [
            'user_id' => $userId,
            'notification_count' => count($notifications),
        ]);
    }

    public function invalidateUserUnreadCount(int $userId): void
    {
        $countKey = $this->keyBuilder->build(self::CACHE_PREFIX, 'user', $userId, 'unread_count');
        $this->cache->delete($countKey);
    }

    public function refreshNotification(int $notificationId): void
    {
        $cacheKey = $this->buildNotificationCacheKey($notificationId);
        $notification = $this->notificationRepository->find($notificationId);

        if ($notification === null) {
            $this->cache->delete($cacheKey);
            return;
        }

        $data = $this->serializeNotification($notification);
        $this->setNotification($notificationId, $data);
    }

    public function warmUser(int $userId): void
    {
        $notifications = $this->notificationRepository->findRecentByUserId($userId, 30);

        foreach ($notifications as $notification) {
            $data = $this->serializeNotification($notification);
            $this->setNotification($notification->getId(), $data, self::DEFAULT_TTL);
        }

        $this->logger->debug('Warmed notification cache for user', [
            'user_id' => $userId,
            'notifications_warmed' => count($notifications),
        ]);
    }

    public function handleCreateNotification(int $notificationId): void
    {
        $notification = $this->notificationRepository->find($notificationId);
        if ($notification === null) {
            return;
        }

        $this->invalidateUserNotifications($notification->getUserId());

        $this->metrics->increment('cache.invalidation', [
            'type' => 'create_notification',
            'notification_id' => (string) $notificationId,
        ]);
    }

    public function handleMarkAsRead(int $notificationId): void
    {
        $this->invalidateNotification($notificationId);

        $notification = $this->notificationRepository->find($notificationId);
        if ($notification !== null) {
            $this->invalidateUserUnreadCount($notification->getUserId());
        }

        $this->logger->info('Handled mark as read cache invalidation', [
            'notification_id' => $notificationId,
        ]);
    }

    public function handleMarkAllAsRead(int $userId): void
    {
        $notifications = $this->notificationRepository->findUnreadByUserId($userId);

        foreach ($notifications as $notification) {
            $this->invalidateNotification($notification->getId());
        }

        $this->invalidateUserUnreadCount($userId);

        $this->logger->info('Handled mark all as read cache invalidation', [
            'user_id' => $userId,
            'notification_count' => count($notifications),
        ]);
    }

    public function handleDeleteNotification(int $notificationId): void
    {
        $notification = $this->notificationRepository->find($notificationId);
        if ($notification !== null) {
            $this->invalidateNotification($notificationId);
            $this->invalidateUserUnreadCount($notification->getUserId());
        }

        $this->logger->info('Handled notification deletion cache invalidation', [
            'notification_id' => $notificationId,
        ]);
    }

    public function handleDeleteAllUserNotifications(int $userId): void
    {
        $notifications = $this->notificationRepository->findByUserId($userId);

        foreach ($notifications as $notification) {
            $this->invalidateNotification($notification->getId());
        }

        $this->invalidateUserUnreadCount($userId);

        $this->logger->info('Handled delete all user notifications cache invalidation', [
            'user_id' => $userId,
        ]);
    }

    public function handleNotificationSettingsUpdate(int $userId): void
    {
        $settingsKey = $this->keyBuilder->build('user', $userId, 'notification_settings');
        $this->cache->delete($settingsKey);

        $this->logger->info('Handled notification settings update cache invalidation', [
            'user_id' => $userId,
        ]);
    }

    public function setWithStale(int $notificationId, array $data): void
    {
        $cacheKey = $this->buildNotificationCacheKey($notificationId);
        $staleKey = $cacheKey . ':stale';

        $this->cache->set($staleKey, $data, self::DEFAULT_TTL + self::STALE_TTL);
        $this->cache->set($cacheKey, $data, self::DEFAULT_TTL);
    }

    public function getOrSet(int $notificationId, callable $fetcher, ?int $ttl = null): array
    {
        $cached = $this->getNotification($notificationId);
        if ($cached !== null) {
            return $cached;
        }

        $data = $fetcher($notificationId);
        if ($data !== null) {
            $this->setNotification($notificationId, $data, $ttl);
        }
        return $data;
    }

    private function buildNotificationCacheKey(int $notificationId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'notification', $notificationId);
    }

    private function serializeNotification(object $notification): array
    {
        return [
            'id' => $notification->getId(),
            'user_id' => $notification->getUserId(),
            'type' => $notification->getType(),
            'title' => $notification->getTitle(),
            'message' => $notification->getMessage(),
            'is_read' => $notification->isRead(),
            'created_at' => $notification->getCreatedAt()?->format(\DATE_ATOM),
            'read_at' => $notification->getReadAt()?->format(\DATE_ATOM),
        ];
    }
}
