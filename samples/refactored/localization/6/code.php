<?php
declare(strict_types=1);

namespace App\Localization;

use App\Service\TranslationService;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

abstract class AbstractDocumentLocalizationHandler
{
    protected const DEFAULT_LOCALE = 'en';
    protected const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt'];

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
        $cacheKey = $this->buildIdCacheKey($id, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => $this->getType(), 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => $this->getType(), 'locale' => $locale]);
        $entity = $this->findById($id);

        if ($entity === null) {
            return null;
        }

        $data = $this->translate($entity, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);
        return $data;
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
        foreach (self::SUPPORTED_LOCALES as $l) {
            $this->translator->invalidateCache($this->buildIdCacheKey($id, $l));
        }

        $entity = $this->findById($id);
        if ($entity !== null) {
            foreach (self::SUPPORTED_LOCALES as $l) {
                $this->translator->invalidateCache($this->buildSlugCacheKey($this->getSlug($entity), $l));
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

    public function updateTranslation(int $id, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildIdCacheKey($id, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        $entity = $this->findById($id);
        if ($entity !== null) {
            $this->translator->cacheTranslation($this->buildSlugCacheKey($this->getSlug($entity), $locale), $translatedData);
        }

        $this->metrics->increment('localization.update', ['type' => $this->getType(), 'id' => (string) $id, 'locale' => $locale]);
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

    abstract protected function buildIdCacheKey(int $id, string $locale): string;
    abstract protected function buildSlugCacheKey(string $slug, string $locale): string;
    abstract protected function getType(): string;
    abstract protected function findById(int $id): ?object;
    abstract protected function findBySlug(string $slug): ?object;
    abstract protected function translate(object $entity, string $locale): array;
    abstract protected function getSlug(object $entity): string;
}
