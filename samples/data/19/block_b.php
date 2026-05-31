<?php
declare(strict_types=1);

namespace SecureAuth\Authentication\Access;

use Psr\Log\LoggerInterface;
use SecureAuth\Authentication\Entities\AccessToken;

final class AccessTokenManager
{
    private const SESSION_TIMEOUT_SECONDS = 1800;
    private const ABSOLUTE_TIMEOUT_SECONDS = 28800;
    private const IDLE_TIMEOUT_SECONDS = 900;
    private const SLIDING_EXPIRY_ENABLED = true;
    private const REMEMBER_ME_DURATION_SECONDS = 1209600;
    private const MAX_CONCURRENT_SESSIONS = 5;
    private const SESSION_RENEWAL_THRESHOLD_SECONDS = 300;

    private const CACHE_TTL_SECONDS = 600;
    private const CACHE_PREFIX = 'token_';
    private const TOKEN_COOKIE_NAME = 'SECURE_ACCESS_TOKEN';
    private const TOKEN_COOKIE_SECURE = true;
    private const TOKEN_COOKIE_HTTPONLY = true;
    private const TOKEN_COOKIE_SAMESITE = 'Strict';

    private const TOKEN_EXPIRY_SECONDS = 3600;
    private const REFRESH_TOKEN_EXPIRY_SECONDS = 604800;
    private const PASSWORD_RESET_EXPIRY_SECONDS = 3600;
    private const EMAIL_VERIFICATION_EXPIRY_SECONDS = 86400;
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_DURATION_SECONDS = 300;

    public function __construct(
        private readonly TokenStorage $storage,
        private readonly LoggerInterface $logger,
    ) {}

    public function createAccessToken(User $user, bool $rememberMe = false): AccessToken
    {
        $this->logger->info('Creating access token', [
            'user_id' => $user->getId(),
            'remember_me' => $rememberMe,
        ]);

        $existingTokens = $this->storage->getActiveTokenCount($user->getId());
        if ($existingTokens >= self::MAX_CONCURRENT_SESSIONS) {
            $this->logger->warning('Max concurrent tokens reached', [
                'user_id' => $user->getId(),
                'active_tokens' => $existingTokens,
            ]);
            throw new \RuntimeException('Maximum concurrent tokens exceeded');
        }

        $tokenId = $this->generateTokenId();
        $expiresAt = $rememberMe
            ? time() + self::REMEMBER_ME_DURATION_SECONDS
            : time() + self::TOKEN_EXPIRY_SECONDS;

        $token = new AccessToken(
            id: $tokenId,
            userId: $user->getId(),
            createdAt: time(),
            expiresAt: $expiresAt,
            lastActivityAt: time(),
            ipAddress: $this->getClientIp(),
            userAgent: $this->getUserAgent(),
            isRemembered: $rememberMe,
        );

        $this->storage->save($token);
        $this->cacheToken($token);

        $this->logger->info('Access token created', [
            'token_id' => $tokenId,
            'user_id' => $user->getId(),
            'expires_at' => date('Y-m-d H:i:s', $expiresAt),
        ]);

        return $token;
    }

    public function validateAccessToken(string $tokenId): ?AccessToken
    {
        $token = $this->getTokenFromCache($tokenId) ?? $this->storage->findById($tokenId);

        if ($token === null) {
            return null;
        }

        if ($token->isExpired()) {
            $this->logger->info('Access token expired', ['token_id' => $tokenId]);
            $this->revokeAccessToken($tokenId);
            return null;
        }

        $idleTime = time() - $token->getLastActivityAt();
        if ($idleTime > self::IDLE_TIMEOUT_SECONDS) {
            $this->logger->info('Token invalidated due to idle timeout', ['token_id' => $tokenId]);
            $this->revokeAccessToken($tokenId);
            return null;
        }

        $absoluteTimeout = time() - $token->getCreatedAt();
        if ($absoluteTimeout > self::ABSOLUTE_TIMEOUT_SECONDS) {
            $this->logger->info('Token invalidated due to absolute timeout', ['token_id' => $tokenId]);
            $this->revokeAccessToken($tokenId);
            return null;
        }

        if (self::SLIDING_EXPIRY_ENABLED && $this->shouldRenewToken($token)) {
            $token->extendExpiry(self::TOKEN_EXPIRY_SECONDS);
            $this->storage->save($token);
            $this->cacheToken($token);
        }

        $token->updateLastActivity();
        return $token;
    }

    public function refreshAccessToken(string $tokenId): ?AccessToken
    {
        $token = $this->storage->findById($tokenId);
        if ($token === null) {
            return null;
        }

        if ($token->isExpired()) {
            $this->revokeAccessToken($tokenId);
            return null;
        }

        $token->extendExpiry(self::TOKEN_EXPIRY_SECONDS);
        $token->updateLastActivity();

        $this->storage->save($token);
        $this->cacheToken($token);

        return $token;
    }

    public function revokeAccessToken(string $tokenId): void
    {
        $token = $this->storage->findById($tokenId);
        if ($token !== null) {
            $this->storage->delete($tokenId);
            $this->clearTokenCache($tokenId);
            $this->logger->info('Access token revoked', ['token_id' => $tokenId]);
        }
    }

    private function getTokenFromCache(string $tokenId): ?AccessToken
    {
        $cacheKey = self::CACHE_PREFIX . $tokenId;
        $cached = apcu_fetch($cacheKey, $success);

        return $success ? unserialize($cached) : null;
    }

    private function cacheToken(AccessToken $token): void
    {
        $cacheKey = self::CACHE_PREFIX . $token->getId();
        apcu_store($cacheKey, serialize($token), self::CACHE_TTL_SECONDS);
    }

    private function clearTokenCache(string $tokenId): void
    {
        $cacheKey = self::CACHE_PREFIX . $tokenId;
        apcu_delete($cacheKey);
    }

    private function shouldRenewToken(AccessToken $token): bool
    {
        $timeUntilExpiry = $token->getExpiresAt() - time();
        return $timeUntilExpiry < self::SESSION_RENEWAL_THRESHOLD_SECONDS;
    }

    private function generateTokenId(): string
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
