<?php
declare(strict_types=1);

namespace App\Caching\Handlers;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Repository\FeatureFlagRepository;
use App\Repository\PermissionRepository;
use App\Repository\BusinessRuleRepository;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class ConfigurationCacheHandler
{
    private const CACHE_PREFIX = 'config';
    private const DEFAULT_TTL = 86400;
    private const STALE_TTL = 3600;

    public function __construct(
        private readonly CacheService $cache,
        private readonly CacheKeyBuilder $keyBuilder,
        private readonly FeatureFlagRepository $featureFlagRepository,
        private readonly PermissionRepository $permissionRepository,
        private readonly BusinessRuleRepository $businessRuleRepository,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getFeatureFlags(int $userId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildFeatureFlagCacheKey($userId);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'feature_flags']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'feature_flags']);

        $flags = $this->featureFlagRepository->findActiveByUserId($userId);

        if ($flags === null) {
            return null;
        }

        $data = $this->serializeFeatureFlags($flags);
        $this->setFeatureFlags($userId, $data);

        return $data;
    }

    public function setFeatureFlags(int $userId, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildFeatureFlagCacheKey($userId);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        $this->cache->set($cacheKey, $data, $ttl);

        $this->logger->debug('Cached feature flags', [
            'user_id' => $userId,
            'ttl' => $ttl,
        ]);
    }

    public function invalidateFeatureFlags(int $userId): void
    {
        $cacheKey = $this->buildFeatureFlagCacheKey($userId);
        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated feature flags cache', [
            'user_id' => $userId,
        ]);
    }

    public function refreshFeatureFlags(int $userId): void
    {
        $flags = $this->featureFlagRepository->findActiveByUserId($userId);

        if ($flags === null) {
            $this->cache->delete($this->buildFeatureFlagCacheKey($userId));
            return;
        }

        $data = $this->serializeFeatureFlags($flags);
        $this->setFeatureFlags($userId, $data);

        $this->logger->debug('Refreshed feature flags cache', [
            'user_id' => $userId,
        ]);
    }

    public function warmFeatureFlags(int $userId): void
    {
        $flags = $this->featureFlagRepository->findActiveByUserId($userId);
        $data = $this->serializeFeatureFlags($flags);
        $this->setFeatureFlags($userId, $data, self::DEFAULT_TTL);

        $this->logger->debug('Warmed feature flags cache', [
            'user_id' => $userId,
        ]);
    }

    public function getPermissions(int $userId, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildPermissionCacheKey($userId);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'permissions']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'permissions']);

        $permissions = $this->permissionRepository->findActiveByUserId($userId);

        if ($permissions === null) {
            return null;
        }

        $data = $this->serializePermissions($permissions);
        $this->setPermissions($userId, $data);

        return $data;
    }

    public function setPermissions(int $userId, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildPermissionCacheKey($userId);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        $this->cache->set($cacheKey, $data, $ttl);

        $this->logger->debug('Cached permissions', [
            'user_id' => $userId,
            'ttl' => $ttl,
        ]);
    }

    public function invalidatePermissions(int $userId): void
    {
        $cacheKey = $this->buildPermissionCacheKey($userId);
        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated permissions cache', [
            'user_id' => $userId,
        ]);
    }

    public function refreshPermissions(int $userId): void
    {
        $permissions = $this->permissionRepository->findActiveByUserId($userId);

        if ($permissions === null) {
            $this->cache->delete($this->buildPermissionCacheKey($userId));
            return;
        }

        $data = $this->serializePermissions($permissions);
        $this->setPermissions($userId, $data);

        $this->logger->debug('Refreshed permissions cache', [
            'user_id' => $userId,
        ]);
    }

    public function warmPermissions(int $userId): void
    {
        $permissions = $this->permissionRepository->findActiveByUserId($userId);
        $data = $this->serializePermissions($permissions);
        $this->setPermissions($userId, $data, self::DEFAULT_TTL);

        $this->logger->debug('Warmed permissions cache', [
            'user_id' => $userId,
        ]);
    }

    public function getBusinessRules(string $context, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildBusinessRuleCacheKey($context);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'business_rules']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'business_rules']);

        $rules = $this->businessRuleRepository->findActiveByContext($context);

        if ($rules === null) {
            return null;
        }

        $data = $this->serializeBusinessRules($rules);
        $this->setBusinessRules($context, $data);

        return $data;
    }

    public function setBusinessRules(string $context, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildBusinessRuleCacheKey($context);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        $this->cache->set($cacheKey, $data, $ttl);

        $this->logger->debug('Cached business rules', [
            'context' => $context,
            'ttl' => $ttl,
        ]);
    }

    public function invalidateBusinessRules(string $context): void
    {
        $cacheKey = $this->buildBusinessRuleCacheKey($context);
        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated business rules cache', [
            'context' => $context,
        ]);
    }

    public function refreshBusinessRules(string $context): void
    {
        $rules = $this->businessRuleRepository->findActiveByContext($context);

        if ($rules === null) {
            $this->cache->delete($this->buildBusinessRuleCacheKey($context));
            return;
        }

        $data = $this->serializeBusinessRules($rules);
        $this->setBusinessRules($context, $data);

        $this->logger->debug('Refreshed business rules cache', [
            'context' => $context,
        ]);
    }

    public function warmBusinessRules(string $context): void
    {
        $rules = $this->businessRuleRepository->findActiveByContext($context);
        $data = $this->serializeBusinessRules($rules);
        $this->setBusinessRules($context, $data, self::DEFAULT_TTL);

        $this->logger->debug('Warmed business rules cache', [
            'context' => $context,
        ]);
    }

    public function handleFeatureFlagChange(int $flagId): void
    {
        $affectedUsers = $this->featureFlagRepository->findUsersByFlagId($flagId);

        foreach ($affectedUsers as $userId) {
            $this->invalidateFeatureFlags($userId);
        }

        $this->invalidateBusinessRules('feature_flags');

        $this->metrics->increment('cache.invalidation', [
            'type' => 'feature_flag_change',
            'flag_id' => (string) $flagId,
            'affected_users' => count($affectedUsers),
        ]);

        $this->logger->info('Handled feature flag change cache invalidation', [
            'flag_id' => $flagId,
            'affected_users' => count($affectedUsers),
        ]);
    }

    public function handlePermissionChange(int $userId): void
    {
        $this->invalidatePermissions($userId);
        $this->invalidateFeatureFlags($userId);

        $this->metrics->increment('cache.invalidation', [
            'type' => 'permission_change',
            'user_id' => (string) $userId,
        ]);

        $this->logger->info('Handled permission change cache invalidation', [
            'user_id' => $userId,
        ]);
    }

    public function handleRoleChange(int $userId): void
    {
        $this->invalidatePermissions($userId);
        $this->invalidateFeatureFlags($userId);

        $roleKey = $this->keyBuilder->build('user', $userId, 'role_permissions');
        $this->cache->delete($roleKey);

        $this->metrics->increment('cache.invalidation', [
            'type' => 'role_change',
            'user_id' => (string) $userId,
        ]);

        $this->logger->info('Handled role change cache invalidation', [
            'user_id' => $userId,
        ]);
    }

    public function handleBusinessRuleChange(int $ruleId): void
    {
        $rule = $this->businessRuleRepository->find($ruleId);

        if ($rule !== null) {
            $this->invalidateBusinessRules($rule->getContext());
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'business_rule_change',
            'rule_id' => (string) $ruleId,
        ]);

        $this->logger->info('Handled business rule change cache invalidation', [
            'rule_id' => $ruleId,
        ]);
    }

    public function handleGlobalConfigChange(): void
    {
        $pattern = $this->keyBuilder->buildPattern(self::CACHE_PREFIX, 'feature_flags', '*');
        $this->cache->deleteByPattern($pattern);

        $pattern = $this->keyBuilder->buildPattern(self::CACHE_PREFIX, 'permissions', '*');
        $this->cache->deleteByPattern($pattern);

        $pattern = $this->keyBuilder->buildPattern(self::CACHE_PREFIX, 'business_rules', '*');
        $this->cache->deleteByPattern($pattern);

        $this->metrics->increment('cache.invalidation', [
            'type' => 'global_config_change',
        ]);

        $this->logger->info('Handled global config change cache invalidation');
    }

    public function setWithStale(int $userId, string $type, array $data): void
    {
        $cacheKey = match ($type) {
            'feature_flags' => $this->buildFeatureFlagCacheKey($userId),
            'permissions' => $this->buildPermissionCacheKey($userId),
            default => throw new \InvalidArgumentException("Unknown type: $type"),
        };

        $staleKey = $cacheKey . ':stale';

        $this->cache->set($staleKey, $data, self::DEFAULT_TTL + self::STALE_TTL);
        $this->cache->set($cacheKey, $data, self::DEFAULT_TTL);

        $this->logger->debug('Set config with stale backup', [
            'user_id' => $userId,
            'type' => $type,
        ]);
    }

    public function getOrSet(int $userId, string $type, callable $fetcher): array
    {
        $cached = match ($type) {
            'feature_flags' => $this->getFeatureFlags($userId),
            'permissions' => $this->getPermissions($userId),
            default => throw new \InvalidArgumentException("Unknown type: $type"),
        };

        if ($cached !== null) {
            return $cached;
        }

        $data = $fetcher($userId);

        if ($data !== null) {
            match ($type) {
                'feature_flags' => $this->setFeatureFlags($userId, $data),
                'permissions' => $this->setPermissions($userId, $data),
            };
        }

        return $data;
    }

    private function buildFeatureFlagCacheKey(int $userId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'feature_flags', (string) $userId);
    }

    private function buildPermissionCacheKey(int $userId): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'permissions', (string) $userId);
    }

    private function buildBusinessRuleCacheKey(string $context): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'business_rules', $context);
    }

    private function serializeFeatureFlags(array $flags): array
    {
        $result = [];
        foreach ($flags as $flag) {
            $result[$flag->getKey()] = [
                'enabled' => $flag->isEnabled(),
                'value' => $flag->getValue(),
            ];
        }
        return $result;
    }

    private function serializePermissions(array $permissions): array
    {
        $result = [];
        foreach ($permissions as $perm) {
            $result[$perm->getResource()][] = $perm->getAction();
        }
        return $result;
    }

    private function serializeBusinessRules(array $rules): array
    {
        $result = [];
        foreach ($rules as $rule) {
            $result[$rule->getName()] = [
                'context' => $rule->getContext(),
                'config' => $rule->getConfig(),
            ];
        }
        return $result;
    }
}
