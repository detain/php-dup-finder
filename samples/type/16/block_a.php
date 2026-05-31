<?php
declare(strict_types=1);

namespace UserService\Caching;

use Psr\Log\LoggerInterface;

final class UserCacheManager
{
    private const CACHE_PREFIX = 'user:';
    private const DEFAULT_TTL_SECONDS = 3600;
    private const PROFILE_TTL_SECONDS = 1800;
    private const SESSIONS_TTL_SECONDS = 900;
    private const PREFERENCES_TTL_SECONDS = 7200;

    private const LIST_CACHE_PREFIX = 'user_list:';
    private const LIST_TTL_SECONDS = 600;

    private const LOCK_PREFIX = 'user_lock:';
    private const LOCK_TTL_SECONDS = 30;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function getUser(string $userId): ?array
    {
        $cacheKey = self::CACHE_PREFIX . $userId;
        $cached = apcu_fetch($cacheKey, $success);

        if ($success && $cached !== false) {
            $this->logger->debug('User cache hit', ['user_id' => $userId]);
            return unserialize($cached);
        }

        $this->logger->debug('User cache miss', ['user_id' => $userId]);
        return null;
    }

    public function setUser(string $userId, array $userData, ?int $ttl = null): void
    {
        $cacheKey = self::CACHE_PREFIX . $userId;
        $effectiveTtl = $ttl ?? self::DEFAULT_TTL_SECONDS;

        apcu_store($cacheKey, serialize($userData), $effectiveTtl);

        $this->logger->debug('User cached', [
            'user_id' => $userId,
            'ttl' => $effectiveTtl,
        ]);
    }

    public function invalidateUser(string $userId): void
    {
        $cacheKey = self::CACHE_PREFIX . $userId;
        apcu_delete($cacheKey);

        $this->logger->debug('User cache invalidated', ['user_id' => $userId]);
    }

    public function getUserProfile(string $userId): ?array
    {
        $cacheKey = self::CACHE_PREFIX . $userId . ':profile';
        $cached = apcu_fetch($cacheKey, $success);

        if ($success && $cached !== false) {
            return unserialize($cached);
        }

        return null;
    }

    public function setUserProfile(string $userId, array $profileData): void
    {
        $cacheKey = self::CACHE_PREFIX . $userId . ':profile';
        apcu_store($cacheKey, serialize($profileData), self::PROFILE_TTL_SECONDS);
    }

    public function invalidateUserProfile(string $userId): void
    {
        $cacheKey = self::CACHE_PREFIX . $userId . ':profile';
        apcu_delete($cacheKey);
    }

    public function getUserSessions(string $userId): ?array
    {
        $cacheKey = self::CACHE_PREFIX . $userId . ':sessions';
        $cached = apcu_fetch($cacheKey, $success);

        if ($success && $cached !== false) {
            return unserialize($cached);
        }

        return null;
    }

    public function setUserSessions(string $userId, array $sessions): void
    {
        $cacheKey = self::CACHE_PREFIX . $userId . ':sessions';
        apcu_store($cacheKey, serialize($sessions), self::SESSIONS_TTL_SECONDS);
    }

    public function invalidateUserSessions(string $userId): void
    {
        $cacheKey = self::CACHE_PREFIX . $userId . ':sessions';
        apcu_delete($cacheKey);
    }

    public function getUserPreferences(string $userId): ?array
    {
        $cacheKey = self::CACHE_PREFIX . $userId . ':preferences';
        $cached = apcu_fetch($cacheKey, $success);

        if ($success && $cached !== false) {
            return unserialize($cached);
        }

        return null;
    }

    public function setUserPreferences(string $userId, array $preferences): void
    {
        $cacheKey = self::CACHE_PREFIX . $userId . ':preferences';
        apcu_store($cacheKey, serialize($preferences), self::PREFERENCES_TTL_SECONDS);
    }

    public function invalidateUserPreferences(string $userId): void
    {
        $cacheKey = self::CACHE_PREFIX . $userId . ':preferences';
        apcu_delete($cacheKey);
    }

    public function getUserList(string $listKey): ?array
    {
        $cacheKey = self::LIST_CACHE_PREFIX . $listKey;
        $cached = apcu_fetch($cacheKey, $success);

        if ($success && $cached !== false) {
            return unserialize($cached);
        }

        return null;
    }

    public function setUserList(string $listKey, array $userIds): void
    {
        $cacheKey = self::LIST_CACHE_PREFIX . $listKey;
        apcu_store($cacheKey, serialize($userIds), self::LIST_TTL_SECONDS);
    }

    public function invalidateUserList(string $listKey): void
    {
        $cacheKey = self::LIST_CACHE_PREFIX . $listKey;
        apcu_delete($cacheKey);
    }

    public function invalidateAllUserData(string $userId): void
    {
        $this->invalidateUser($userId);
        $this->invalidateUserProfile($userId);
        $this->invalidateUserSessions($userId);
        $this->invalidateUserPreferences($userId);

        $this->logger->info('All user data invalidated', ['user_id' => $userId]);
    }

    public function acquireLock(string $userId): bool
    {
        $lockKey = self::LOCK_PREFIX . $userId;
        return apcu_add($lockKey, time(), self::LOCK_TTL_SECONDS);
    }

    public function releaseLock(string $userId): void
    {
        $lockKey = self::LOCK_PREFIX . $userId;
        apcu_delete($lockKey);
    }
}
