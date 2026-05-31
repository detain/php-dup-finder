<?php
declare(strict_types=1);

namespace App\Localization\Handlers;

use App\Service\TranslationService;
use App\Repository\PromoCodeRepository;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class PromoCodeLocalizationHandler
{
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt'];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly PromoCodeRepository $promoCodeRepository,
        private readonly LocaleService $localeService,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getLocalizedPromoCode(string $code, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildPromoCodeCacheKey($code, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => 'promo_code', 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => 'promo_code', 'locale' => $locale]);

        $promoCode = $this->promoCodeRepository->findByCode($code);

        if ($promoCode === null) {
            return null;
        }

        $data = $this->translatePromoCode($promoCode, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getActivePromoCodes(?string $locale = null): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildActivePromoCodesCacheKey($locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $promoCodes = $this->promoCodeRepository->findActive();

        $results = [];
        foreach ($promoCodes as $promoCode) {
            $results[] = $this->translatePromoCode($promoCode, $locale);
        }

        $this->translator->cacheTranslation($cacheKey, $results);

        return $results;
    }

    public function invalidatePromoCode(string $code): void
    {
        foreach (self::SUPPORTED_LOCALES as $l) {
            $cacheKey = $this->buildPromoCodeCacheKey($code, $l);
            $this->translator->invalidateCache($cacheKey);
        }

        foreach (self::SUPPORTED_LOCALES as $l) {
            $this->translator->invalidateCache($this->buildActivePromoCodesCacheKey($l));
        }

        $this->logger->debug('Invalidated promo code localization', [
            'code' => $code,
        ]);
    }

    public function invalidateAllForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern('promo_code:*:' . $locale);

        $this->logger->info('Invalidated all promo codes for locale', [
            'locale' => $locale,
        ]);
    }

    public function updatePromoCodeTranslation(string $code, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildPromoCodeCacheKey($code, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        $this->metrics->increment('localization.update', [
            'type' => 'promo_code',
            'code' => $code,
            'locale' => $locale,
        ]);
    }

    private function buildPromoCodeCacheKey(string $code, string $locale): string
    {
        return "promo_code:{$code}:{$locale}";
    }

    private function buildActivePromoCodesCacheKey(string $locale): string
    {
        return "promo_code:active:{$locale}";
    }

    private function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    private function translatePromoCode(object $promoCode, string $locale): array
    {
        return [
            'code' => $promoCode->getCode(),
            'type' => $promoCode->getType(),
            'value' => $promoCode->getValue(),
            'description' => $this->translator->translate($promoCode->getDescriptionKey(), $locale),
            'min_purchase' => $promoCode->getMinPurchase(),
            'max_uses' => $promoCode->getMaxUses(),
            'starts_at' => $promoCode->getStartsAt()?->format(\DATE_ATOM),
            'ends_at' => $promoCode->getEndsAt()?->format(\DATE_ATOM),
            'locale' => $locale,
        ];
    }
}
