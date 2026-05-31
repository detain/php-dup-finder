<?php
declare(strict_types=1);

namespace App\Caching\Handlers;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Repository\RateLimitRepository;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class RateLimitCacheHandler
{
    private const CACHE_PREFIX = 'rate_limit';
    private const WINDOW_SIZE = 60;

    public function __construct(
        private readonly CacheService $cache,
        private readonly CacheKeyBuilder $keyBuilder,
        private readonly RateLimitRepository $rateLimitRepository,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getUserRateLimit(int $userId, string $action): ?array
    {
        $cacheKey = $this->buildUserRateLimitKey($userId, $action);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'rate_limit']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'rate_limit']);

        $rateLimit = $this->rateLimitRepository->findByUserAndAction($userId, $action);

        if ($rateLimit === null) {
            return null;
        }

        $data = $this->serializeRateLimit($rateLimit);
        $this->setUserRateLimit($userId, $action, $data);

        return $data;
    }

    public function setUserRateLimit(int $userId, string $action, array $data): void
    {
        $cacheKey = $this->buildUserRateLimitKey($userId, $action);

        $this->cache->set($cacheKey, $data, self::WINDOW_SIZE);

        $this->logger->debug('Cached user rate limit', [
            'user_id' => $userId,
            'action' => $action,
        ]);
    }

    public function invalidateUserRateLimit(int $userId, string $action): void
    {
        $cacheKey = $this->buildUserRateLimitKey($userId, $action);
        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated user rate limit cache', [
            'user_id' => $userId,
            'action' => $action,
        ]);
    }

    public function refreshUserRateLimit(int $userId, string $action): void
    {
        $rateLimit = $this->rateLimitRepository->findByUserAndAction($userId, $action);

        if ($rateLimit === null) {
            $this->cache->delete($this->buildUserRateLimitKey($userId, $action));
            return;
        }

        $data = $this->serializeRateLimit($rateLimit);
        $this->setUserRateLimit($userId, $action, $data);

        $this->logger->debug('Refreshed user rate limit cache', [
            'user_id' => $userId,
            'action' => $action,
        ]);
    }

    public function warmUserRateLimits(int $userId): void
    {
        $rateLimits = $this->rateLimitRepository->findByUserId($userId);

        foreach ($rateLimits as $rateLimit) {
            $data = $this->serializeRateLimit($rateLimit);
            $this->setUserRateLimit($userId, $rateLimit->getAction(), $data);
        }

        $this->logger->debug('Warmed user rate limit cache', [
            'user_id' => $userId,
            'limits_warmed' => count($rateLimits),
        ]);
    }

    public function getIpRateLimit(string $ipAddress, string $action): ?array
    {
        $cacheKey = $this->buildIpRateLimitKey($ipAddress, $action);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'rate_limit_ip']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'rate_limit_ip']);

        $rateLimit = $this->rateLimitRepository->findByIpAndAction($ipAddress, $action);

        if ($rateLimit === null) {
            return null;
        }

        $data = $this->serializeRateLimit($rateLimit);
        $this->setIpRateLimit($ipAddress, $action, $data);

        return $data;
    }

    public function setIpRateLimit(string $ipAddress, string $action, array $data): void
    {
        $cacheKey = $this->buildIpRateLimitKey($ipAddress, $action);

        $this->cache->set($cacheKey, $data, self::WINDOW_SIZE);

        $this->logger->debug('Cached IP rate limit', [
            'ip_address' => $ipAddress,
            'action' => $action,
        ]);
    }

    public function invalidateIpRateLimit(string $ipAddress, string $action): void
    {
        $cacheKey = $this->buildIpRateLimitKey($ipAddress, $action);
        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated IP rate limit cache', [
            'ip_address' => $ipAddress,
            'action' => $action,
        ]);
    }

    public function refreshIpRateLimit(string $ipAddress, string $action): void
    {
        $rateLimit = $this->rateLimitRepository->findByIpAndAction($ipAddress, $action);

        if ($rateLimit === null) {
            $this->cache->delete($this->buildIpRateLimitKey($ipAddress, $action));
            return;
        }

        $data = $this->serializeRateLimit($rateLimit);
        $this->setIpRateLimit($ipAddress, $action, $data);

        $this->logger->debug('Refreshed IP rate limit cache', [
            'ip_address' => $ipAddress,
            'action' => $action,
        ]);
    }

    public function warmIpRateLimits(string $ipAddress): void
    {
        $rateLimits = $this->rateLimitRepository->findByIpAddress($ipAddress);

        foreach ($rateLimits as $rateLimit) {
            $data = $this->serializeRateLimit($rateLimit);
            $this->setIpRateLimit($ipAddress, $rateLimit->getAction(), $data);
        }

        $this->logger->debug('Warmed IP rate limit cache', [
            'ip_address' => $ipAddress,
            'limits_warmed' => count($rateLimits),
        ]);
    }

    public function getApiKeyRateLimit(string $apiKey, string $action): ?array
    {
        $cacheKey = $this->buildApiKeyRateLimitKey($apiKey, $action);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'rate_limit_apikey']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'rate_limit_apikey']);

        $rateLimit = $this->rateLimitRepository->findByApiKeyAndAction($apiKey, $action);

        if ($rateLimit === null) {
            return null;
        }

        $data = $this->serializeRateLimit($rateLimit);
        $this->setApiKeyRateLimit($apiKey, $action, $data);

        return $data;
    }

    public function setApiKeyRateLimit(string $apiKey, string $action, array $data): void
    {
        $cacheKey = $this->buildApiKeyRateLimitKey($apiKey, $action);

        $this->cache->set($cacheKey, $data, self::WINDOW_SIZE);

        $this->logger->debug('Cached API key rate limit', [
            'action' => $action,
        ]);
    }

    public function invalidateApiKeyRateLimit(string $apiKey, string $action): void
    {
        $cacheKey = $this->buildApiKeyRateLimitKey($apiKey, $action);
        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated API key rate limit cache', [
            'action' => $action,
        ]);
    }

    public function refreshApiKeyRateLimit(string $apiKey, string $action): void
    {
        $rateLimit = $this->rateLimitRepository->findByApiKeyAndAction($apiKey, $action);

        if ($rateLimit === null) {
            $this->cache->delete($this->buildApiKeyRateLimitKey($apiKey, $action));
            return;
        }

        $data = $this->serializeRateLimit($rateLimit);
        $this->setApiKeyRateLimit($apiKey, $action, $data);

        $this->logger->debug('Refreshed API key rate limit cache', [
            'action' => $action,
        ]);
    }

    public function warmApiKeyRateLimits(string $apiKey): void
    {
        $rateLimits = $this->rateLimitRepository->findByApiKey($apiKey);

        foreach ($rateLimits as $rateLimit) {
            $data = $this->serializeRateLimit($rateLimit);
            $this->setApiKeyRateLimit($apiKey, $rateLimit->getAction(), $data);
        }

        $this->logger->debug('Warmed API key rate limit cache', [
            'limits_warmed' => count($rateLimits),
        ]);
    }

    public function incrementCounter(string $cacheKey): int
    {
        $current = (int) $this->cache->get($cacheKey);

        $newValue = $current + 1;

        $this->cache->set($cacheKey, $newValue, self::WINDOW_SIZE);

        return $newValue;
    }

    public function getCurrentCount(string $cacheKey): int
    {
        return (int) $this->cache->get($cacheKey);
    }

    public function resetCounter(string $cacheKey): void
    {
        $this->cache->delete($cacheKey);
    }

    public function handleRateLimitChange(int $userId): void
    {
        $rateLimits = $this->rateLimitRepository->findByUserId($userId);

        foreach ($rateLimits as $rateLimit) {
            $this->invalidateUserRateLimit($userId, $rateLimit->getAction());
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'rate_limit_change',
            'user_id' => (string) $userId,
        ]);

        $this->logger->info('Handled rate limit change cache invalidation', [
            'user_id' => $userId,
        ]);
    }

    public function handleApiKeyChange(string $apiKey): void
    {
        $rateLimits = $this->rateLimitRepository->findByApiKey($apiKey);

        foreach ($rateLimits as $rateLimit) {
            $this->invalidateApiKeyRateLimit($apiKey, $rateLimit->getAction());
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'api_key_change',
        ]);

        $this->logger->info('Handled API key change cache invalidation');
    }

    public function handleIpBlocklist(string $ipAddress): void
    {
        $pattern = $this->keyBuilder->buildPattern(self::CACHE_PREFIX, 'ip', $ipAddress, '*');
        $this->cache->deleteByPattern($pattern);

        $this->metrics->increment('cache.invalidation', [
            'type' => 'ip_blocklist',
        ]);

        $this->logger->info('Handled IP blocklist cache invalidation', [
            'ip_address' => $ipAddress,
        ]);
    }

    public function handleGlobalRateLimitUpdate(): void
    {
        $pattern = $this->keyBuilder->buildPattern(self::CACHE_PREFIX, '*');
        $this->cache->deleteByPattern($pattern);

        $this->metrics->increment('cache.invalidation', [
            'type' => 'global_rate_limit_update',
        ]);

        $this->logger->info('Handled global rate limit update cache invalidation');
    }

    private function buildUserRateLimitKey(int $userId, string $action): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'user', (string) $userId, $action);
    }

    private function buildIpRateLimitKey(string $ipAddress, string $action): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'ip', $ipAddress, $action);
    }

    private function buildApiKeyRateLimitKey(string $apiKey, string $action): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'apikey', hash('sha256', $apiKey), $action);
    }

    private function serializeRateLimit(object $rateLimit): array
    {
        return [
            'limit' => $rateLimit->getLimit(),
            'window' => $rateLimit->getWindow(),
            'current' => $rateLimit->getCurrent(),
            'reset_at' => $rateLimit->getResetAt()?->format(\DATE_ATOM),
        ];
    }
}
