<?php
declare(strict_types=1);

namespace App\Localization;

use App\Service\TranslationService;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

abstract class AbstractMessagingLocalizationHandler
{
    protected const DEFAULT_LOCALE = 'en';
    protected const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt', 'ja', 'zh', 'ko', 'ar'];

    public function __construct(
        protected readonly TranslationService $translator,
        protected readonly LocaleService $localeService,
        protected readonly MetricsService $metrics,
        protected readonly LoggerInterface $logger,
    ) {
    }

    public function get(string|int $id, ?string $locale = null): ?array
    {
        $locale = $this->normalizeLocale($locale);
        $cacheKey = $this->buildPrimaryCacheKey($id, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => $this->getType(), 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => $this->getType(), 'locale' => $locale]);
        $entity = $this->findEntity($id);

        if ($entity === null) {
            return null;
        }

        $data = $this->translate($entity, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);
        return $data;
    }

    public function getByIdentifier(string $identifier, ?string $locale = null): ?array
    {
        $locale = $this->normalizeLocale($locale);
        $cacheKey = $this->buildIdentifierCacheKey($identifier, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $entity = $this->findByIdentifier($identifier);
        if ($entity === null) {
            return null;
        }

        $data = $this->translate($entity, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);
        return $data;
    }

    public function invalidate(string|int $id): void
    {
        foreach (self::SUPPORTED_LOCALES as $locale) {
            $this->translator->invalidateCache($this->buildPrimaryCacheKey($id, $locale));
        }

        if (method_exists($this, 'getIdentifier')) {
            $entity = $this->findEntity($id);
            if ($entity !== null) {
                foreach (self::SUPPORTED_LOCALES as $locale) {
                    $this->translator->invalidateCache($this->buildIdentifierCacheKey($this->getIdentifier($entity), $locale));
                }
            }
        }

        $this->logger->debug('Invalidated localization', ['type' => $this->getType(), 'id' => $id]);
    }

    public function invalidateForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern($this->getType() . ':*:' . $locale);
        $this->logger->info("Invalidated all {$this->getType()} for locale", ['locale' => $locale]);
    }

    public function updateTranslation(string|int $id, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildPrimaryCacheKey($id, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        if (method_exists($this, 'getIdentifier')) {
            $entity = $this->findEntity($id);
            if ($entity !== null) {
                $this->translator->cacheTranslation($this->buildIdentifierCacheKey($this->getIdentifier($entity), $locale), $translatedData);
            }
        }

        $this->metrics->increment('localization.update', ['type' => $this->getType(), 'id' => (string) $id, 'locale' => $locale]);
    }

    public function getAvailableTranslations(string|int $id): array
    {
        $translations = [];
        foreach (self::SUPPORTED_LOCALES as $locale) {
            $translations[$locale] = $this->translator->getCachedTranslation($this->buildPrimaryCacheKey($id, $locale)) !== null;
        }
        return $translations;
    }

    public function warmCache(string|int $id): void
    {
        $entity = $this->findEntity($id);
        if ($entity === null) {
            return;
        }

        foreach (self::SUPPORTED_LOCALES as $locale) {
            $data = $this->translate($entity, $locale);
            $this->translator->cacheTranslation($this->buildPrimaryCacheKey($id, $locale), $data);
        }

        $this->logger->debug('Warmed localization cache', ['type' => $this->getType(), 'id' => $id]);
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

    abstract protected function buildPrimaryCacheKey(string|int $id, string $locale): string;
    abstract protected function buildIdentifierCacheKey(string $identifier, string $locale): string;
    abstract protected function getType(): string;
    abstract protected function findEntity(string|int $id): ?object;
    abstract protected function findByIdentifier(string $identifier): ?object;
    abstract protected function translate(object $entity, string $locale): array;
}
