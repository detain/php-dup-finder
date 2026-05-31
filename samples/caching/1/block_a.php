<?php
declare(strict_types=1);

namespace App\Caching\Handlers;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Repository\UserSessionRepository;
use App\Repository\UserRepository;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class UserSessionCacheHandler
{
    private const CACHE_PREFIX = 'user_session';
    private const DEFAULT_TTL = 3600;
    private const STALE_TTL = 300;

    public function __construct(
        private readonly CacheService $cache,
        private readonly CacheKeyBuilder $keyBuilder,
        private readonly UserSessionRepository $sessionRepository,
        private readonly UserRepository $userRepository,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function get(int $sessionId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildCacheKey($sessionId);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => self::CACHE_PREFIX]);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => self::CACHE_PREFIX]);

        $session = $this->sessionRepository->find($sessionId);

        if ($session === null) {
            return null;
        }

        $data = $this->serializeSession($session);
        $this->set($sessionId, $data);

        return $data;
    }

    public function set(int $sessionId, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildCacheKey($sessionId);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        $this->cache->set($cacheKey, $data, $ttl);

        $this->logger->debug('Cached user session', [
            'session_id' => $sessionId,
            'ttl' => $ttl,
        ]);
    }

    public function invalidate(int $sessionId): void
    {
        $cacheKey = $this->buildCacheKey($sessionId);

        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated user session cache', [
            'session_id' => $sessionId,
        ]);
    }

    public function invalidateUserSessions(int $userId): void
    {
        $sessions = $this->sessionRepository->findByUserId($userId);

        $cacheKeys = array_map(
            fn($session) => $this->buildCacheKey($session->getId()),
            $sessions
        );

        if (!empty($cacheKeys)) {
            $this->cache->deleteMultiple($cacheKeys);
        }

        $this->logger->info('Invalidated all sessions for user', [
            'user_id' => $userId,
            'session_count' => count($sessions),
        ]);
    }

    public function refresh(int $sessionId): void
    {
        $cacheKey = $this->buildCacheKey($sessionId);

        $session = $this->sessionRepository->find($sessionId);

        if ($session === null) {
            $this->cache->delete($cacheKey);
            return;
        }

        $data = $this->serializeSession($session);
        $this->set($sessionId, $data);

        $this->logger->debug('Refreshed user session cache', [
            'session_id' => $sessionId,
        ]);
    }

    public function warm(int $userId): void
    {
        $sessions = $this->sessionRepository->findActiveByUserId($userId);

        foreach ($sessions as $session) {
            $data = $this->serializeSession($session);
            $this->set($session->getId(), $data, self::DEFAULT_TTL);
        }

        $this->logger->debug('Warmed session cache for user', [
            'user_id' => $userId,
            'sessions_warmed' => count($sessions),
        ]);
    }

    public function handleUserLogout(int $userId): void
    {
        $this->invalidateUserSessions($userId);

        $cacheKeys = [
            $this->keyBuilder->build('user', $userId, 'profile'),
            $this->keyBuilder->build('user', $userId, 'preferences'),
            $this->keyBuilder->build('user', $userId, 'permissions'),
        ];

        foreach ($cacheKeys as $key) {
            $this->cache->delete($key);
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'user_logout',
            'user_id' => (string) $userId,
        ]);

        $this->logger->info('Handled user logout cache invalidation', [
            'user_id' => $userId,
        ]);
    }

    public function handlePasswordChange(int $userId): void
    {
        $this->invalidateUserSessions($userId);

        $securityKeys = [
            $this->keyBuilder->build('user', $userId, 'session_tokens'),
            $this->keyBuilder->build('user', $userId, 'security_settings'),
            $this->keyBuilder->build('user', $userId, 'mfa_status'),
        ];

        foreach ($securityKeys as $key) {
            $this->cache->delete($key);
        }

        $this->logger->info('Handled password change cache invalidation', [
            'user_id' => $userId,
        ]);
    }

    public function handleRoleChange(int $userId): void
    {
        $permissionKeys = [
            $this->keyBuilder->build('user', $userId, 'permissions'),
            $this->keyBuilder->build('user', $userId, 'roles'),
            $this->keyBuilder->build('user', $userId, 'access_levels'),
        ];

        foreach ($permissionKeys as $key) {
            $this->cache->delete($key);
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'role_change',
            'user_id' => (string) $userId,
        ]);

        $this->logger->info('Handled role change cache invalidation', [
            'user_id' => $userId,
        ]);
    }

    public function handleAccountSuspension(int $userId): void
    {
        $suspensionKeys = [
            $this->keyBuilder->build('user', $userId, 'session_tokens'),
            $this->keyBuilder->build('user', $userId, 'access_token'),
            $this->keyBuilder->build('user', $userId, 'refresh_tokens'),
        ];

        foreach ($suspensionKeys as $key) {
            $this->cache->delete($key);
        }

        $this->invalidateUserSessions($userId);

        $this->logger->info('Handled account suspension cache invalidation', [
            'user_id' => $userId,
        ]);
    }

    public function setWithStale(int $sessionId, array $data): void
    {
        $cacheKey = $this->buildCacheKey($sessionId);

        $staleKey = $cacheKey . ':stale';

        $this->cache->set($staleKey, $data, self::DEFAULT_TTL + self::STALE_TTL);
        $this->cache->set($cacheKey, $data, self::DEFAULT_TTL);

        $this->logger->debug('Set session with stale backup', [
            'session_id' => $sessionId,
        ]);
    }

    public function getOrSet(int $sessionId, callable $fetcher, ?int $ttl = null): array
    {
        $cached = $this->get($sessionId);

        if ($cached !== null) {
            return $cached;
        }

        $data = $fetcher($sessionId);

        if ($data !== null) {
            $this->set($sessionId, $data, $ttl);
        }

        return $data;
    }

    private function buildCacheKey(int $sessionId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, $sessionId);
    }

    private function serializeSession(object $session): array
    {
        return [
            'id' => $session->getId(),
            'user_id' => $session->getUserId(),
            'ip_address' => $session->getIpAddress(),
            'user_agent' => $session->getUserAgent(),
            'created_at' => $session->getCreatedAt()?->format(\DATE_ATOM),
            'last_activity_at' => $session->getLastActivityAt()?->format(\DATE_ATOM),
            'expires_at' => $session->getExpiresAt()?->format(\DATE_ATOM),
            'status' => $session->getStatus(),
        ];
    }
}
