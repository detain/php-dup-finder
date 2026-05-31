<?php
declare(strict_types=1);

namespace Authentication\Shared;

final class SessionConfig
{
    public const TIMEOUT_SECONDS = 1800;
    public const ABSOLUTE_TIMEOUT_SECONDS = 28800;
    public const IDLE_TIMEOUT_SECONDS = 900;
    public const SLIDING_EXPIRY = true;
    public const REMEMBER_ME_DURATION_SECONDS = 1209600;
    public const MAX_CONCURRENT = 5;
    public const RENEWAL_THRESHOLD_SECONDS = 300;
}

final class CacheConfig
{
    public const TTL_SECONDS = 600;
    public const PREFIX = 'auth_';
}

final class TokenConfig
{
    public const EXPIRY_SECONDS = 3600;
    public const REFRESH_EXPIRY_SECONDS = 604800;
    public const PASSWORD_RESET_EXPIRY_SECONDS = 3600;
    public const EMAIL_VERIFICATION_EXPIRY_SECONDS = 86400;
}

final class SecurityConfig
{
    public const MAX_LOGIN_ATTEMPTS = 5;
    public const LOCKOUT_DURATION_SECONDS = 300;
}

interface SessionManagerInterface
{
    public function createSession(User $user, bool $rememberMe): Session;
    public function validateSession(string $sessionId): ?Session;
    public function refreshSession(string $sessionId): ?Session;
    public function invalidateSession(string $sessionId): void;
}

trait SessionManagementLogic
{
    private SessionConfig $sessionConfig;
    private CacheConfig $cacheConfig;
    private TokenConfig $tokenConfig;
    private SecurityConfig $securityConfig;
    private LoggerInterface $logger;

    protected function createUserSession(User $user, bool $rememberMe, callable $generateId): Session
    {
        $this->checkConcurrentSessionLimit($user->getId());

        $sessionId = $generateId();
        $expiryDuration = $rememberMe
            ? $this->sessionConfig::REMEMBER_ME_DURATION_SECONDS
            : $this->tokenConfig::EXPIRY_SECONDS;

        $session = new Session(
            id: $sessionId,
            userId: $user->getId(),
            createdAt: time(),
            expiresAt: time() + $expiryDuration,
            lastActivityAt: time(),
            ipAddress: $this->getClientIp(),
            userAgent: $this->getUserAgent(),
            isRemembered: $rememberMe,
        );

        $this->saveSession($session);
        $this->cacheSession($session);

        return $session;
    }

    protected function validateUserSession(string $sessionId, callable $loadSession): ?Session
    {
        $session = $loadSession($sessionId);

        if ($session === null || $session->isExpired()) {
            return null;
        }

        if ($this->isIdleTimedOut($session) || $this->isAbsoluteTimedOut($session)) {
            $this->invalidateSession($sessionId);
            return null;
        }

        if ($this->sessionConfig::SLIDING_EXPIRY && $this->needsRenewal($session)) {
            $session->extendExpiry($this->tokenConfig::EXPIRY_SECONDS);
            $this->saveSession($session);
            $this->cacheSession($session);
        }

        return $session;
    }

    private function checkConcurrentSessionLimit(string $userId): void
    {
        $activeCount = $this->getActiveSessionCount($userId);
        if ($activeCount >= $this->sessionConfig::MAX_CONCURRENT) {
            throw new \RuntimeException('Maximum concurrent sessions exceeded');
        }
    }

    private function isIdleTimedOut(Session $session): bool
    {
        return (time() - $session->getLastActivityAt()) > $this->sessionConfig::IDLE_TIMEOUT_SECONDS;
    }

    private function isAbsoluteTimedOut(Session $session): bool
    {
        return (time() - $session->getCreatedAt()) > $this->sessionConfig::ABSOLUTE_TIMEOUT_SECONDS;
    }

    private function needsRenewal(Session $session): bool
    {
        return ($session->getExpiresAt() - time()) < $this->sessionConfig::RENEWAL_THRESHOLD_SECONDS;
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
