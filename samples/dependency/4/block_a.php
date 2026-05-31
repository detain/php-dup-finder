<?php

declare(strict_types=1);

namespace App\Application\Session;

use App\Infrastructure\Cache\CacheService;
use App\Domain\Session\Entity\UserSession;
use App\Domain\Session\Repository\SessionRepositoryInterface;

/**
 * Session management service.
 * The CacheService is manually injected here, duplicated across
 * all services that need caching.
 */
class SessionService
{
    private const SESSION_PREFIX = 'session:';
    private const SESSION_TTL_SECONDS = 3600;

    private CacheService $cache;
    private SessionRepositoryInterface $sessionRepository;

    public function __construct(
        CacheService $cache,
        SessionRepositoryInterface $sessionRepository
    ) {
        $this->cache = $cache;
        $this->sessionRepository = $sessionRepository;
    }

    public function createSession(string $userId, array $data = []): UserSession
    {
        $sessionId = bin2hex(random_bytes(32));
        $expiresAt = new \DateTimeImmutable('+' . self::SESSION_TTL_SECONDS . ' seconds');

        $session = new UserSession(
            id: $sessionId,
            userId: $userId,
            data: $data,
            createdAt: new \DateTimeImmutable(),
            expiresAt: $expiresAt,
        );

        $this->cache->set(
            $this->getCacheKey($sessionId),
            $session->toArray(),
            self::SESSION_TTL_SECONDS
        );

        $this->sessionRepository->save($session);

        return $session;
    }

    public function getSession(string $sessionId): ?UserSession
    {
        $cached = $this->cache->get($this->getCacheKey($sessionId));

        if ($cached !== null) {
            return $this->hydrateSession($cached);
        }

        $session = $this->sessionRepository->findById($sessionId);

        if ($session === null) {
            return null;
        }

        if ($session->isExpired()) {
            $this->deleteSession($sessionId);
            return null;
        }

        $this->cache->set(
            $this->getCacheKey($sessionId),
            $session->toArray(),
            $session->getExpiresAt()->getTimestamp() - time()
        );

        return $session;
    }

    public function updateSession(string $sessionId, array $data): ?UserSession
    {
        $session = $this->getSession($sessionId);

        if ($session === null) {
            return null;
        }

        $session->updateData($data);
        $session->extend(self::SESSION_TTL_SECONDS);

        $this->cache->set(
            $this->getCacheKey($sessionId),
            $session->toArray(),
            $session->getExpiresAt()->getTimestamp() - time()
        );

        $this->sessionRepository->save($session);

        return $session;
    }

    public function deleteSession(string $sessionId): void
    {
        $this->cache->delete($this->getCacheKey($sessionId));
        $this->sessionRepository->delete($sessionId);
    }

    public function deleteUserSessions(string $userId): int
    {
        $sessions = $this->sessionRepository->findByUserId($userId);
        $count = 0;

        foreach ($sessions as $session) {
            $this->deleteSession($session->getId());
            $count++;
        }

        return $count;
    }

    public function cleanupExpiredSessions(): int
    {
        $expiredSessions = $this->sessionRepository->findExpired();

        foreach ($expiredSessions as $session) {
            $this->cache->delete($this->getCacheKey($session->getId()));
        }

        return $this->sessionRepository->deleteExpired();
    }

    private function getCacheKey(string $sessionId): string
    {
        return self::SESSION_PREFIX . $sessionId;
    }

    private function hydrateSession(array $data): UserSession
    {
        return new UserSession(
            id: $data['id'],
            userId: $data['user_id'],
            data: $data['data'] ?? [],
            createdAt: new \DateTimeImmutable($data['created_at']),
            expiresAt: new \DateTimeImmutable($data['expires_at']),
        );
    }
}
