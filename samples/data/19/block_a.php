<?php
declare(strict_types=1);

namespace SecureAuth\Authentication\Session;

use Psr\Log\LoggerInterface;
use SecureAuth\Authentication\Entities\User;

final class SessionManager
{
    private const SESSION_TIMEOUT_SECONDS = 1800;
    private const ABSOLUTE_TIMEOUT_SECONDS = 28800;
    private const IDLE_TIMEOUT_SECONDS = 900;
    private const SLIDING_EXPIRY_ENABLED = true;
    private const REMEMBER_ME_DURATION_SECONDS = 1209600;
    private const MAX_CONCURRENT_SESSIONS = 5;
    private const SESSION_RENEWAL_THRESHOLD_SECONDS = 300;

    private const CACHE_TTL_SECONDS = 600;
    private const CACHE_PREFIX = 'session_';
    private const SESSION_COOKIE_NAME = 'SECURE_SESSION_ID';
    private const SESSION_COOKIE_SECURE = true;
    private const SESSION_COOKIE_HTTPONLY = true;
    private const SESSION_COOKIE_SAMESITE = 'Strict';

    private const TOKEN_EXPIRY_SECONDS = 3600;
    private const REFRESH_TOKEN_EXPIRY_SECONDS = 604800;
    private const PASSWORD_RESET_EXPIRY_SECONDS = 3600;
    private const EMAIL_VERIFICATION_EXPIRY_SECONDS = 86400;
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_DURATION_SECONDS = 300;

    public function __construct(
        private readonly SessionStorage $storage,
        private readonly LoggerInterface $logger,
    ) {}

    public function createSession(User $user, bool $rememberMe = false): Session
    {
        $this->logger->info('Creating user session', [
            'user_id' => $user->getId(),
            'remember_me' => $rememberMe,
        ]);

        $existingSessions = $this->storage->getActiveSessionCount($user->getId());
        if ($existingSessions >= self::MAX_CONCURRENT_SESSIONS) {
            $this->logger->warning('Max concurrent sessions reached', [
                'user_id' => $user->getId(),
                'active_sessions' => $existingSessions,
            ]);
            throw new \RuntimeException('Maximum concurrent sessions exceeded');
        }

        $sessionId = $this->generateSessionId();
        $expiresAt = $rememberMe
            ? time() + self::REMEMBER_ME_DURATION_SECONDS
            : time() + self::SESSION_TIMEOUT_SECONDS;

        $session = new Session(
            id: $sessionId,
            userId: $user->getId(),
            createdAt: time(),
            expiresAt: $expiresAt,
            lastActivityAt: time(),
            ipAddress: $this->getClientIp(),
            userAgent: $this->getUserAgent(),
            isRemembered: $rememberMe,
        );

        $this->storage->save($session);
        $this->cacheSession($session);

        $this->logger->info('Session created', [
            'session_id' => $sessionId,
            'user_id' => $user->getId(),
            'expires_at' => date('Y-m-d H:i:s', $expiresAt),
        ]);

        return $session;
    }

    public function validateSession(string $sessionId): ?Session
    {
        $session = $this->getSessionFromCache($sessionId) ?? $this->storage->findById($sessionId);

        if ($session === null) {
            return null;
        }

        if ($session->isExpired()) {
            $this->logger->info('Session expired', ['session_id' => $sessionId]);
            $this->invalidateSession($sessionId);
            return null;
        }

        $idleTime = time() - $session->getLastActivityAt();
        if ($idleTime > self::IDLE_TIMEOUT_SECONDS) {
            $this->logger->info('Session invalidated due to idle timeout', ['session_id' => $sessionId]);
            $this->invalidateSession($sessionId);
            return null;
        }

        $absoluteTimeout = time() - $session->getCreatedAt();
        if ($absoluteTimeout > self::ABSOLUTE_TIMEOUT_SECONDS) {
            $this->logger->info('Session invalidated due to absolute timeout', ['session_id' => $sessionId]);
            $this->invalidateSession($sessionId);
            return null;
        }

        if (self::SLIDING_EXPIRY_ENABLED && $this->shouldRenewSession($session)) {
            $session->extendExpiry(self::SESSION_TIMEOUT_SECONDS);
            $this->storage->save($session);
            $this->cacheSession($session);
        }

        $session->updateLastActivity();
        return $session;
    }

    public function refreshSession(string $sessionId): ?Session
    {
        $session = $this->storage->findById($sessionId);
        if ($session === null) {
            return null;
        }

        if ($session->isExpired()) {
            $this->invalidateSession($sessionId);
            return null;
        }

        $session->extendExpiry(self::SESSION_TIMEOUT_SECONDS);
        $session->updateLastActivity();

        $this->storage->save($session);
        $this->cacheSession($session);

        return $session;
    }

    public function invalidateSession(string $sessionId): void
    {
        $session = $this->storage->findById($sessionId);
        if ($session !== null) {
            $this->storage->delete($sessionId);
            $this->clearSessionCache($sessionId);
            $this->logger->info('Session invalidated', ['session_id' => $sessionId]);
        }
    }

    private function getSessionFromCache(string $sessionId): ?Session
    {
        $cacheKey = self::CACHE_PREFIX . $sessionId;
        $cached = apcu_fetch($cacheKey, $success);

        return $success ? unserialize($cached) : null;
    }

    private function cacheSession(Session $session): void
    {
        $cacheKey = self::CACHE_PREFIX . $session->getId();
        apcu_store($cacheKey, serialize($session), self::CACHE_TTL_SECONDS);
    }

    private function clearSessionCache(string $sessionId): void
    {
        $cacheKey = self::CACHE_PREFIX . $sessionId;
        apcu_delete($cacheKey);
    }

    private function shouldRenewSession(Session $session): bool
    {
        $timeUntilExpiry = $session->getExpiresAt() - time();
        return $timeUntilExpiry < self::SESSION_RENEWAL_THRESHOLD_SECONDS;
    }

    private function generateSessionId(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function getClientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private function getUserAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    }
}
