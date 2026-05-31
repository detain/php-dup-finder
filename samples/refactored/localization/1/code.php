<?php
declare(strict_types=1);

namespace App\Localization;

use App\Service\TranslationService;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

abstract class AbstractLocalizationHandler
{
    protected const DEFAULT_LOCALE = 'en';
    protected const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt', 'ja', 'zh'];

    public function __construct(
        protected readonly TranslationService $translator,
        protected readonly LocaleService $localeService,
        protected readonly MetricsService $metrics,
        protected readonly LoggerInterface $logger,
    ) {
    }

    public function get(int $id, ?string $locale = null): ?array
    {
        $locale = $this->normalizeLocale($locale);
        $cacheKey = $this->buildPrimaryCacheKey($id, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => $this->getEntityType(), 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => $this->getEntityType(), 'locale' => $locale]);
        $entity = $this->findEntity($id);

        if ($entity === null) {
            return null;
        }

        $data = $this->translate($entity, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);
        return $data;
    }

    public function getMultiple(array $ids, ?string $locale = null): array
    {
        $locale = $this->normalizeLocale($locale);
        $results = [];

        foreach ($ids as $id) {
            $localized = $this->get($id, $locale);
            if ($localized !== null) {
                $results[$id] = $localized;
            }
        }

        return $results;
    }

    public function getBySlug(string $slug, ?string $locale = null): ?array
    {
        $locale = $this->normalizeLocale($locale);
        $cacheKey = $this->buildSlugCacheKey($slug, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $entity = $this->findBySlug($slug);
        if ($entity === null) {
            return null;
        }

        $data = $this->translate($entity, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);
        return $data;
    }

    public function invalidate(int $id): void
    {
        foreach (self::SUPPORTED_LOCALES as $locale) {
            $this->translator->invalidateCache($this->buildPrimaryCacheKey($id, $locale));
        }

        $entity = $this->findEntity($id);
        if ($entity !== null) {
            $this->translator->invalidateCache($this->buildSlugCacheKey($this->getSlug($entity), self::DEFAULT_LOCALE));
        }

        $this->logger->debug('Invalidated localization', [
            'entity_type' => $this->getEntityType(),
            'id' => $id,
        ]);
    }

    public function invalidateForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern($this->getEntityType() . ':*:' . $locale);
        $this->logger->info("Invalidated all {$this->getEntityType()} for locale", ['locale' => $locale]);
    }

    public function updateTranslation(int $id, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildPrimaryCacheKey($id, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        $entity = $this->findEntity($id);
        if ($entity !== null) {
            $this->translator->cacheTranslation($this->buildSlugCacheKey($this->getSlug($entity), $locale), $translatedData);
        }

        $this->metrics->increment('localization.update', [
            'type' => $this->getEntityType(),
            'id' => (string) $id,
            'locale' => $locale,
        ]);
    }

    public function getAvailableTranslations(int $id): array
    {
        $translations = [];
        foreach (self::SUPPORTED_LOCALES as $locale) {
            $translations[$locale] = $this->translator->getCachedTranslation($this->buildPrimaryCacheKey($id, $locale)) !== null;
        }
        return $translations;
    }

    public function getMissingTranslations(int $id): array
    {
        return array_keys(array_filter($this->getAvailableTranslations($id), fn($exists) => !$exists));
    }

    public function warmCache(int $id): void
    {
        $entity = $this->findEntity($id);
        if ($entity === null) {
            return;
        }

        foreach (self::SUPPORTED_LOCALES as $locale) {
            $data = $this->translate($entity, $locale);
            $this->translator->cacheTranslation($this->buildPrimaryCacheKey($id, $locale), $data);
        }

        $this->logger->debug('Warmed localization cache', ['entity_type' => $this->getEntityType(), 'id' => $id]);
    }

    public function getLocalizedUrl(int $id, ?string $locale = null): string
    {
        $locale = $this->normalizeLocale($locale);
        $entity = $this->get($id, $locale);

        if ($entity === null) {
            return '/';
        }

        return '/' . $locale . '/' . $this->getEntityType() . '/' . ($entity['slug'] ?? $id);
    }

    protected function normalizeLocale(?string $locale): string
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();
        return $this->isSupportedLocale($locale) ? $locale : self::DEFAULT_LOCALE;
    }

    protected function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    abstract protected function buildPrimaryCacheKey(int $id, string $locale): string;
    abstract protected function buildSlugCacheKey(string $slug, string $locale): string;
    abstract protected function getEntityType(): string;
    abstract protected function findEntity(int $id): ?object;
    abstract protected function findBySlug(string $slug): ?object;
    abstract protected function translate(object $entity, string $locale): array;
    abstract protected function getSlug(object $entity): string;
}
