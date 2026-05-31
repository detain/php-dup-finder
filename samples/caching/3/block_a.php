<?php
declare(strict_types=1);

namespace App\Caching\Handlers;

use App\Service\CacheService;
use App\Service\CacheKeyBuilder;
use App\Repository\CurrencyRateRepository;
use App\Repository\LocaleRepository;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class LocalizationCacheHandler
{
    private const CACHE_PREFIX = 'localization';
    private const DEFAULT_TTL = 86400;
    private const RATE_TTL = 3600;
    private const STALE_TTL = 7200;

    public function __construct(
        private readonly CacheService $cache,
        private readonly CacheKeyBuilder $keyBuilder,
        private readonly CurrencyRateRepository $currencyRateRepository,
        private readonly LocaleRepository $localeRepository,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getCurrencyRates(string $baseCurrency, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildCurrencyRatesCacheKey($baseCurrency);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'currency_rates']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'currency_rates']);

        $rates = $this->currencyRateRepository->findCurrentRates($baseCurrency);

        if ($rates === null) {
            return null;
        }

        $data = $this->serializeCurrencyRates($rates);
        $this->setCurrencyRates($baseCurrency, $data);

        return $data;
    }

    public function setCurrencyRates(string $baseCurrency, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildCurrencyRatesCacheKey($baseCurrency);
        $ttl = $ttl ?? self::RATE_TTL;

        $this->cache->set($cacheKey, $data, $ttl);

        $this->logger->debug('Cached currency rates', [
            'base_currency' => $baseCurrency,
            'ttl' => $ttl,
        ]);
    }

    public function invalidateCurrencyRates(string $baseCurrency): void
    {
        $cacheKey = $this->buildCurrencyRatesCacheKey($baseCurrency);
        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated currency rates cache', [
            'base_currency' => $baseCurrency,
        ]);
    }

    public function refreshCurrencyRates(string $baseCurrency): void
    {
        $rates = $this->currencyRateRepository->findCurrentRates($baseCurrency);

        if ($rates === null) {
            $this->cache->delete($this->buildCurrencyRatesCacheKey($baseCurrency));
            return;
        }

        $data = $this->serializeCurrencyRates($rates);
        $this->setCurrencyRates($baseCurrency, $data);

        $this->logger->debug('Refreshed currency rates cache', [
            'base_currency' => $baseCurrency,
        ]);
    }

    public function warmCurrencyRates(array $currencies): void
    {
        foreach ($currencies as $currency) {
            $rates = $this->currencyRateRepository->findCurrentRates($currency);

            if ($rates !== null) {
                $data = $this->serializeCurrencyRates($rates);
                $this->setCurrencyRates($currency, $data, self::RATE_TTL);
            }
        }

        $this->logger->debug('Warmed currency rates cache', [
            'currencies_warmed' => count($currencies),
        ]);
    }

    public function getLocale(string $localeCode, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildLocaleCacheKey($localeCode);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'locale']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'locale']);

        $locale = $this->localeRepository->findByCode($localeCode);

        if ($locale === null) {
            return null;
        }

        $data = $this->serializeLocale($locale);
        $this->setLocale($localeCode, $data);

        return $data;
    }

    public function setLocale(string $localeCode, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildLocaleCacheKey($localeCode);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        $this->cache->set($cacheKey, $data, $ttl);

        $this->logger->debug('Cached locale', [
            'locale_code' => $localeCode,
            'ttl' => $ttl,
        ]);
    }

    public function invalidateLocale(string $localeCode): void
    {
        $cacheKey = $this->buildLocaleCacheKey($localeCode);
        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated locale cache', [
            'locale_code' => $localeCode,
        ]);
    }

    public function refreshLocale(string $localeCode): void
    {
        $locale = $this->localeRepository->findByCode($localeCode);

        if ($locale === null) {
            $this->cache->delete($this->buildLocaleCacheKey($localeCode));
            return;
        }

        $data = $this->serializeLocale($locale);
        $this->setLocale($localeCode, $data);

        $this->logger->debug('Refreshed locale cache', [
            'locale_code' => $localeCode,
        ]);
    }

    public function warmLocales(array $localeCodes): void
    {
        foreach ($localeCodes as $code) {
            $locale = $this->localeRepository->findByCode($code);

            if ($locale !== null) {
                $data = $this->serializeLocale($locale);
                $this->setLocale($code, $data, self::DEFAULT_TTL);
            }
        }

        $this->logger->debug('Warmed locale cache', [
            'locales_warmed' => count($localeCodes),
        ]);
    }

    public function getTranslations(string $localeCode, string $domain, bool $allowStale = false): ?array
    {
        $cacheKey = $this->buildTranslationsCacheKey($localeCode, $domain);

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('cache.hit', ['type' => 'translations']);
            return $cached;
        }

        $this->metrics->increment('cache.miss', ['type' => 'translations']);

        $translations = $this->localeRepository->findTranslations($localeCode, $domain);

        if ($translations === null) {
            return null;
        }

        $data = $this->serializeTranslations($translations);
        $this->setTranslations($localeCode, $domain, $data);

        return $data;
    }

    public function setTranslations(string $localeCode, string $domain, array $data, ?int $ttl = null): void
    {
        $cacheKey = $this->buildTranslationsCacheKey($localeCode, $domain);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        $this->cache->set($cacheKey, $data, $ttl);

        $this->logger->debug('Cached translations', [
            'locale_code' => $localeCode,
            'domain' => $domain,
            'ttl' => $ttl,
        ]);
    }

    public function invalidateTranslations(string $localeCode, string $domain): void
    {
        $cacheKey = $this->buildTranslationsCacheKey($localeCode, $domain);
        $this->cache->delete($cacheKey);

        $this->logger->debug('Invalidated translations cache', [
            'locale_code' => $localeCode,
            'domain' => $domain,
        ]);
    }

    public function refreshTranslations(string $localeCode, string $domain): void
    {
        $translations = $this->localeRepository->findTranslations($localeCode, $domain);

        if ($translations === null) {
            $this->cache->delete($this->buildTranslationsCacheKey($localeCode, $domain));
            return;
        }

        $data = $this->serializeTranslations($translations);
        $this->setTranslations($localeCode, $domain, $data);

        $this->logger->debug('Refreshed translations cache', [
            'locale_code' => $localeCode,
            'domain' => $domain,
        ]);
    }

    public function warmTranslations(string $localeCode, array $domains): void
    {
        foreach ($domains as $domain) {
            $translations = $this->localeRepository->findTranslations($localeCode, $domain);

            if ($translations !== null) {
                $data = $this->serializeTranslations($translations);
                $this->setTranslations($localeCode, $domain, $data, self::DEFAULT_TTL);
            }
        }

        $this->logger->debug('Warmed translations cache', [
            'locale_code' => $localeCode,
            'domains_warmed' => count($domains),
        ]);
    }

    public function handleCurrencyRateUpdate(string $baseCurrency): void
    {
        $this->invalidateCurrencyRates($baseCurrency);

        $this->metrics->increment('cache.invalidation', [
            'type' => 'currency_rate_update',
            'base_currency' => $baseCurrency,
        ]);

        $this->logger->info('Handled currency rate update cache invalidation', [
            'base_currency' => $baseCurrency,
        ]);
    }

    public function handleLocaleChange(string $localeCode): void
    {
        $this->invalidateLocale($localeCode);

        $domains = $this->localeRepository->findDomainsForLocale($localeCode);
        foreach ($domains as $domain) {
            $this->invalidateTranslations($localeCode, $domain);
        }

        $this->metrics->increment('cache.invalidation', [
            'type' => 'locale_change',
            'locale_code' => $localeCode,
        ]);

        $this->logger->info('Handled locale change cache invalidation', [
            'locale_code' => $localeCode,
        ]);
    }

    public function handleTranslationUpdate(string $localeCode, string $domain): void
    {
        $this->invalidateTranslations($localeCode, $domain);

        $this->metrics->increment('cache.invalidation', [
            'type' => 'translation_update',
            'locale_code' => $localeCode,
            'domain' => $domain,
        ]);

        $this->logger->info('Handled translation update cache invalidation', [
            'locale_code' => $localeCode,
            'domain' => $domain,
        ]);
    }

    public function handleGlobalLocalizationChange(): void
    {
        $pattern = $this->keyBuilder->buildPattern(self::CACHE_PREFIX, 'currency_rates', '*');
        $this->cache->deleteByPattern($pattern);

        $pattern = $this->keyBuilder->buildPattern(self::CACHE_PREFIX, 'locale', '*');
        $this->cache->deleteByPattern($pattern);

        $pattern = $this->keyBuilder->buildPattern(self::CACHE_PREFIX, 'translations', '*');
        $this->cache->deleteByPattern($pattern);

        $this->metrics->increment('cache.invalidation', [
            'type' => 'global_localization_change',
        ]);

        $this->logger->info('Handled global localization change cache invalidation');
    }

    public function setWithStale(string $type, string $key, array $data): void
    {
        $cacheKey = $this->keyBuilder->build(self::CACHE_PREFIX, $type, $key);
        $staleKey = $cacheKey . ':stale';

        $this->cache->set($staleKey, $data, self::DEFAULT_TTL + self::STALE_TTL);
        $this->cache->set($cacheKey, $data, self::DEFAULT_TTL);

        $this->logger->debug('Set localization with stale backup', [
            'type' => $type,
            'key' => $key,
        ]);
    }

    public function getOrSet(string $type, string $key, callable $fetcher, ?int $ttl = null): array
    {
        $cacheKey = $this->keyBuilder->build(self::CACHE_PREFIX, $type, $key);
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $data = $fetcher($key);

        if ($data !== null) {
            $this->cache->set($cacheKey, $data, $ttl ?? self::DEFAULT_TTL);
        }

        return $data;
    }

    private function buildCurrencyRatesCacheKey(string $baseCurrency): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'currency_rates', $baseCurrency);
    }

    private function buildLocaleCacheKey(string $localeCode): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'locale', $localeCode);
    }

    private function buildTranslationsCacheKey(string $localeCode, string $domain): string
    {
        return $this->keyBuilder->build(self::CACHE_PREFIX, 'translations', $localeCode, $domain);
    }

    private function serializeCurrencyRates(array $rates): array
    {
        $result = [];
        foreach ($rates as $rate) {
            $result[$rate->getTargetCurrency()] = [
                'rate' => $rate->getRate(),
                'updated_at' => $rate->getUpdatedAt()?->format(\DATE_ATOM),
            ];
        }
        return $result;
    }

    private function serializeLocale(object $locale): array
    {
        return [
            'code' => $locale->getCode(),
            'name' => $locale->getName(),
            'direction' => $locale->getDirection(),
            'date_format' => $locale->getDateFormat(),
            'time_format' => $locale->getTimeFormat(),
            'decimal_separator' => $locale->getDecimalSeparator(),
            'thousands_separator' => $locale->getThousandsSeparator(),
        ];
    }

    private function serializeTranslations(array $translations): array
    {
        $result = [];
        foreach ($translations as $translation) {
            $result[$translation->getKey()] = $translation->getValue();
        }
        return $result;
    }
}
