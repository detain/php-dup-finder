<?php
declare(strict_types=1);

namespace SecureAuth\Authentication\Api;

use Psr\Log\LoggerInterface;
use SecureAuth\Authentication\Entities\ApiKey;

final class ApiKeyManager
{
    private const SESSION_TIMEOUT_SECONDS = 1800;
    private const ABSOLUTE_TIMEOUT_SECONDS = 28800;
    private const IDLE_TIMEOUT_SECONDS = 900;
    private const SLIDING_EXPIRY_ENABLED = true;
    private const REMEMBER_ME_DURATION_SECONDS = 1209600;
    private const MAX_CONCURRENT_SESSIONS = 5;
    private const SESSION_RENEWAL_THRESHOLD_SECONDS = 300;

    private const CACHE_TTL_SECONDS = 600;
    private const CACHE_PREFIX = 'apikey_';
    private const APIKEY_HEADER_NAME = 'X-API-KEY';
    private const APIKEY_COOKIE_SECURE = true;
    private const APIKEY_COOKIE_HTTPONLY = true;
    private const APIKEY_COOKIE_SAMESITE = 'Strict';

    private const TOKEN_EXPIRY_SECONDS = 3600;
    private const REFRESH_TOKEN_EXPIRY_SECONDS = 604800;
    private const PASSWORD_RESET_EXPIRY_SECONDS = 3600;
    private const EMAIL_VERIFICATION_EXPIRY_SECONDS = 86400;
    private const MAX_LOGIN_ATTEMPTS = 5;
    private const LOCKOUT_DURATION_SECONDS = 300;

    public function __construct(
        private readonly ApiKeyStorage $storage,
        private readonly LoggerInterface $logger,
    ) {}

    public function createApiKey(User $user, bool $rememberMe = false): ApiKey
    {
        $this->logger->info('Creating API key', [
            'user_id' => $user->getId(),
            'remember_me' => $rememberMe,
        ]);

        $existingKeys = $this->storage->getActiveKeyCount($user->getId());
        if ($existingKeys >= self::MAX_CONCURRENT_SESSIONS) {
            $this->logger->warning('Max concurrent API keys reached', [
                'user_id' => $user->getId(),
                'active_keys' => $existingKeys,
            ]);
            throw new \RuntimeException('Maximum concurrent API keys exceeded');
        }

        $keyId = $this->generateApiKeyId();
        $expiresAt = $rememberMe
            ? time() + self::REMEMBER_ME_DURATION_SECONDS
            : time() + self::TOKEN_EXPIRY_SECONDS;

        $apiKey = new ApiKey(
            id: $keyId,
            userId: $user->getId(),
            createdAt: time(),
            expiresAt: $expiresAt,
            lastActivityAt: time(),
            ipAddress: $this->getClientIp(),
            userAgent: $this->getUserAgent(),
            isRemembered: $rememberMe,
        );

        $this->storage->save($apiKey);
        $this->cacheApiKey($apiKey);

        $this->logger->info('API key created', [
            'key_id' => $keyId,
            'user_id' => $user->getId(),
            'expires_at' => date('Y-m-d H:i:s', $expiresAt),
        ]);

        return $apiKey;
    }

    public function validateApiKey(string $keyId): ?ApiKey
    {
        $apiKey = $this->getApiKeyFromCache($keyId) ?? $this->storage->findById($keyId);

        if ($apiKey === null) {
            return null;
        }

        if ($apiKey->isExpired()) {
            $this->logger->info('API key expired', ['key_id' => $keyId]);
            $this->revokeApiKey($keyId);
            return null;
        }

        $idleTime = time() - $apiKey->getLastActivityAt();
        if ($idleTime > self::IDLE_TIMEOUT_SECONDS) {
            $this->logger->info('API key invalidated due to idle timeout', ['key_id' => $keyId]);
            $this->revokeApiKey($keyId);
            return null;
        }

        $absoluteTimeout = time() - $apiKey->getCreatedAt();
        if ($absoluteTimeout > self::ABSOLUTE_TIMEOUT_SECONDS) {
            $this->logger->info('API key invalidated due to absolute timeout', ['key_id' => $keyId]);
            $this->revokeApiKey($keyId);
            return null;
        }

        if (self::SLIDING_EXPIRY_ENABLED && $this->shouldRenewApiKey($apiKey)) {
            $apiKey->extendExpiry(self::TOKEN_EXPIRY_SECONDS);
            $this->storage->save($apiKey);
            $this->cacheApiKey($apiKey);
        }

        $apiKey->updateLastActivity();
        return $apiKey;
    }

    public function refreshApiKey(string $keyId): ?ApiKey
    {
        $apiKey = $this->storage->findById($keyId);
        if ($apiKey === null) {
            return null;
        }

        if ($apiKey->isExpired()) {
            $this->revokeApiKey($keyId);
            return null;
        }

        $apiKey->extendExpiry(self::TOKEN_EXPIRY_SECONDS);
        $apiKey->updateLastActivity();

        $this->storage->save($apiKey);
        $this->cacheApiKey($apiKey);

        return $apiKey;
    }

    public function revokeApiKey(string $keyId): void
    {
        $apiKey = $this->storage->findById($keyId);
        if ($apiKey !== null) {
            $this->storage->delete($keyId);
            $this->clearApiKeyCache($keyId);
            $this->logger->info('API key revoked', ['key_id' => $keyId]);
        }
    }

    private function getApiKeyFromCache(string $keyId): ?ApiKey
    {
        $cacheKey = self::CACHE_PREFIX . $keyId;
        $cached = apcu_fetch($cacheKey, $success);

        return $success ? unserialize($cached) : null;
    }

    private function cacheApiKey(ApiKey $apiKey): void
    {
        $cacheKey = self::CACHE_PREFIX . $apiKey->getId();
        apcu_store($cacheKey, serialize($apiKey), self::CACHE_TTL_SECONDS);
    }

    private function clearApiKeyCache(string $keyId): void
    {
        $cacheKey = self::CACHE_PREFIX . $keyId;
        apcu_delete($cacheKey);
    }

    private function shouldRenewApiKey(ApiKey $apiKey): bool
    {
        $timeUntilExpiry = $apiKey->getExpiresAt() - time();
        return $timeUntilExpiry < self::SESSION_RENEWAL_THRESHOLD_SECONDS;
    }

    private function generateApiKeyId(): string
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
