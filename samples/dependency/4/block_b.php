<?php

declare(strict_types=1);

namespace App\Application\Preferences;

use App\Infrastructure\Cache\CacheService;
use App\Domain\Preferences\Entity\UserPreferences;
use App\Domain\Preferences\Repository\PreferencesRepositoryInterface;

/**
 * User preferences management service.
 * The CacheService is manually injected here, duplicated from
 * SessionService and other services.
 */
class PreferenceService
{
    private const PREFERENCES_PREFIX = 'preferences:';
    private const PREFERENCES_TTL_SECONDS = 86400;

    private CacheService $cache;
    private PreferencesRepositoryInterface $preferencesRepository;

    public function __construct(
        CacheService $cache,
        PreferencesRepositoryInterface $preferencesRepository
    ) {
        $this->cache = $cache;
        $this->preferencesRepository = $preferencesRepository;
    }

    public function getUserPreferences(string $userId): UserPreferences
    {
        $cached = $this->cache->get($this->getCacheKey($userId));

        if ($cached !== null) {
            return $this->hydratePreferences($userId, $cached);
        }

        $preferences = $this->preferencesRepository->findByUserId($userId);

        if ($preferences === null) {
            $preferences = new UserPreferences(
                userId: $userId,
                theme: 'light',
                language: 'en',
                notifications: [],
                data: [],
            );
        }

        $this->cache->set(
            $this->getCacheKey($userId),
            $preferences->toArray(),
            self::PREFERENCES_TTL_SECONDS
        );

        return $preferences;
    }

    public function updatePreferences(string $userId, array $updates): UserPreferences
    {
        $preferences = $this->getUserPreferences($userId);

        if (isset($updates['theme'])) {
            $preferences->setTheme($updates['theme']);
        }

        if (isset($updates['language'])) {
            $preferences->setLanguage($updates['language']);
        }

        if (isset($updates['notifications'])) {
            $preferences->setNotifications($updates['notifications']);
        }

        if (isset($updates['data'])) {
            $preferences->mergeData($updates['data']);
        }

        $this->preferencesRepository->save($preferences);

        $this->cache->set(
            $this->getCacheKey($userId),
            $preferences->toArray(),
            self::PREFERENCES_TTL_SECONDS
        );

        return $preferences;
    }

    public function setDefaultPreferences(string $userId): UserPreferences
    {
        $preferences = new UserPreferences(
            userId: $userId,
            theme: 'light',
            language: 'en',
            notifications: [
                'email' => true,
                'push' => true,
                'sms' => false,
            ],
            data: [],
        );

        $this->preferencesRepository->save($preferences);

        $this->cache->set(
            $this->getCacheKey($userId),
            $preferences->toArray(),
            self::PREFERENCES_TTL_SECONDS
        );

        return $preferences;
    }

    public function resetPreferences(string $userId): UserPreferences
    {
        $this->cache->delete($this->getCacheKey($userId));
        $this->preferencesRepository->delete($userId);

        return $this->setDefaultPreferences($userId);
    }

    public function invalidateCache(string $userId): void
    {
        $this->cache->delete($this->getCacheKey($userId));
    }

    public function warmCache(string $userId): void
    {
        $preferences = $this->getUserPreferences($userId);

        $this->cache->set(
            $this->getCacheKey($userId),
            $preferences->toArray(),
            self::PREFERENCES_TTL_SECONDS
        );
    }

    private function getCacheKey(string $userId): string
    {
        return self::PREFERENCES_PREFIX . $userId;
    }

    private function hydratePreferences(string $userId, array $data): UserPreferences
    {
        return new UserPreferences(
            userId: $userId,
            theme: $data['theme'] ?? 'light',
            language: $data['language'] ?? 'en',
            notifications: $data['notifications'] ?? [],
            data: $data['data'] ?? [],
        );
    }
}
