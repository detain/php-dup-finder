<?php
declare(strict_types=1);

namespace App\Localization;

use App\Service\TranslationService;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

abstract class AbstractKeyBasedLocalizationHandler
{
    protected const DEFAULT_LOCALE = 'en';
    protected const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt', 'pl', 'ru', 'uk', 'tr'];

    public function __construct(
        protected readonly TranslationService $translator,
        protected readonly LocaleService $localeService,
        protected readonly MetricsService $metrics,
        protected readonly LoggerInterface $logger,
    ) {
    }

    public function get(string $key, ?string $locale = null): ?array
    {
        $locale = $this->normalizeLocale($locale);
        $cacheKey = $this->buildKeyCacheKey($key, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => $this->getType(), 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => $this->getType(), 'locale' => $locale]);
        $entity = $this->findByKey($key);

        if ($entity === null) {
            return null;
        }

        $data = $this->translate($entity, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);
        return $data;
    }

    public function getAll(?string $locale = null): array
    {
        $locale = $this->normalizeLocale($locale);
        $cacheKey = $this->buildAllCacheKey($locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $entities = $this->findAll();
        $results = [];
        foreach ($entities as $entity) {
            $results[$this->getEntityKey($entity)] = $this->translate($entity, $locale);
        }

        $this->translator->cacheTranslation($cacheKey, $results);
        return $results;
    }

    public function invalidate(string $key): void
    {
        foreach (self::SUPPORTED_LOCALES as $l) {
            $this->translator->invalidateCache($this->buildKeyCacheKey($key, $l));
        }

        foreach (self::SUPPORTED_LOCALES as $l) {
            $this->translator->invalidateCache($this->buildAllCacheKey($l));
        }

        $this->logger->debug('Invalidated localization', ['type' => $this->getType(), 'key' => $key]);
    }

    public function invalidateForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern($this->getType() . ':*:' . $locale);
        $this->logger->info("Invalidated all {$this->getType()} for locale", ['locale' => $locale]);
    }

    public function updateTranslation(string $key, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildKeyCacheKey($key, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        $this->metrics->increment('localization.update', ['type' => $this->getType(), 'key' => $key, 'locale' => $locale]);
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

    abstract protected function buildKeyCacheKey(string $key, string $locale): string;
    abstract protected function buildAllCacheKey(string $locale): string;
    abstract protected function getType(): string;
    abstract protected function findByKey(string $key): ?object;
    abstract protected function findAll(): array;
    abstract protected function translate(object $entity, string $locale): array;
    abstract protected function getEntityKey(object $entity): string;
}
