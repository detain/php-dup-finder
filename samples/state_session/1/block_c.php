<?php
declare(strict_types=1);

namespace Preferences\User;

use Doctrine\ORM\EntityManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

final class UserPreferencesManager
{
    private const PREFS_COOKIE = 'user_prefs';
    private const PREFS_CACHE_PREFIX = 'prefs:';
    private const CACHE_TTL = 86400;

    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly LoggerInterface $logger,
        private readonly CacheService $cache,
        private readonly NotificationPreferences $notifications
    ) {}

    public function getPreferences(Request $request, int $userId): array
    {
        // Check for cookie override (allows unauthenticated preference preview)
        $cookiePrefs = $request->cookies->get(self::PREFS_COOKIE);
        if ($cookiePrefs !== null) {
            $decoded = json_decode(base64_decode($cookiePrefs), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Try cache
        $cacheKey = self::PREFS_CACHE_PREFIX . $userId;
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return json_decode($cached, true);
        }

        // Load from database
        $user = $this->entityManager->find(User::class, $userId);
        if ($user === null) {
            return $this->getDefaultPreferences();
        }

        $preferences = $user->getPreferences();

        // Store in cache
        try {
            $this->cache->set($cacheKey, json_encode($preferences), self::CACHE_TTL);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to cache user preferences', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }

        return $preferences;
    }

    public function updatePreferences(Request $request, int $userId): UpdateResult
    {
        $data = json_decode($request->getContent(), true);

        $user = $this->entityManager->find(User::class, $userId);
        if ($user === null) {
            return UpdateResult::failure('User not found');
        }

        $currentPrefs = $user->getPreferences();

        // Merge with validation
        $newPrefs = $this->mergePreferences($currentPrefs, $data);

        // Validate all preference values
        $validationErrors = $this->validatePreferences($newPrefs);
        if (!empty($validationErrors)) {
            return UpdateResult::failure('Invalid preferences', $validationErrors);
        }

        // Update in database
        $user->setPreferences($newPrefs);
        $user->setPreferencesUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->flush();

        // Invalidate cache
        $this->cache->delete(self::PREFS_CACHE_PREFIX . $userId);

        // Update cookie for cross-site preference sync
        $this->setPreferencesCookie($newPrefs);

        $this->logger->info('User preferences updated', [
            'user_id' => $userId,
            'changed_keys' => array_keys($data)
        ]);

        return UpdateResult::success($newPrefs);
    }

    private function mergePreferences(array $current, array $updates): array
    {
        return array_merge($current, $updates);
    }

    private function validatePreferences(array $preferences): array
    {
        $errors = [];

        // Validate email frequency
        if (isset($preferences['email_frequency'])) {
            $validFrequencies = ['none', 'daily', 'weekly', 'monthly'];
            if (!in_array($preferences['email_frequency'], $validFrequencies, true)) {
                $errors['email_frequency'] = 'Invalid frequency value';
            }
        }

        // Validate theme
        if (isset($preferences['theme'])) {
            $validThemes = ['light', 'dark', 'system'];
            if (!in_array($preferences['theme'], $validThemes, true)) {
                $errors['theme'] = 'Invalid theme value';
            }
        }

        // Validate language
        if (isset($preferences['language'])) {
            $validLanguages = ['en', 'es', 'fr', 'de', 'ja', 'zh'];
            if (!in_array($preferences['language'], $validLanguages, true)) {
                $errors['language'] = 'Unsupported language';
            }
        }

        // Validate numeric ranges
        if (isset($preferences['items_per_page'])) {
            $preferences['items_per_page'] = max(10, min(100, (int) $preferences['items_per_page']));
        }

        return $errors;
    }

    private function getDefaultPreferences(): array
    {
        return [
            'theme' => 'system',
            'language' => 'en',
            'email_frequency' => 'weekly',
            'marketing_opt_in' => false,
            'items_per_page' => 25,
            'show_online_status' => true,
            'comment_notifications' => true,
            'mention_notifications' => true
        ];
    }

    private function setPreferencesCookie(array $preferences): void
    {
        // Cookie logic handled in controller
    }
}
