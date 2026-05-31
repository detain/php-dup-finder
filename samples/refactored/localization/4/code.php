<?php
declare(strict_types=1);

namespace App\Localization;

use App\Service\TranslationService;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

abstract class AbstractMarketingLocalizationHandler
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
        $entity = $this->find($id);

        if ($entity === null) {
            return null;
        }

        $data = $this->translate($entity, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);
        return $data;
    }

    public function getActive(?string $locale = null): array
    {
        $locale = $this->normalizeLocale($locale);
        $cacheKey = $this->buildActiveCacheKey($locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $entities = $this->findActive();
        $results = [];
        foreach ($entities as $entity) {
            $results[] = $this->translate($entity, $locale);
        }

        $this->translator->cacheTranslation($cacheKey, $results);
        return $results;
    }

    public function invalidate(string|int $id): void
    {
        foreach (self::SUPPORTED_LOCALES as $l) {
            $this->translator->invalidateCache($this->buildPrimaryCacheKey($id, $l));
        }

        foreach (self::SUPPORTED_LOCALES as $l) {
            $this->translator->invalidateCache($this->buildActiveCacheKey($l));
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

    abstract protected function buildPrimaryCacheKey(string|int $id, string $locale): string;
    abstract protected function buildActiveCacheKey(string $locale): string;
    abstract protected function getType(): string;
    abstract protected function find(string|int $id): ?object;
    abstract protected function findActive(): array;
    abstract protected function translate(object $entity, string $locale): array;
}
