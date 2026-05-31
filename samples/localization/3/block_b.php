<?php
declare(strict_types=1);

namespace App\Localization\Handlers;

use App\Service\TranslationService;
use App\Repository\FaqItemRepository;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class FaqLocalizationHandler
{
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt', 'ja', 'zh'];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly FaqItemRepository $faqRepository,
        private readonly LocaleService $localeService,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getLocalizedFaqItem(int $faqId, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildFaqCacheKey($faqId, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => 'faq_item', 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => 'faq_item', 'locale' => $locale]);

        $faq = $this->faqRepository->find($faqId);

        if ($faq === null) {
            return null;
        }

        $data = $this->translateFaqItem($faq, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getAllFaqItems(?string $locale = null): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildAllFaqCacheKey($locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $faqs = $this->faqRepository->findAllOrdered();

        $results = [];
        foreach ($faqs as $faq) {
            $results[] = $this->translateFaqItem($faq, $locale);
        }

        $this->translator->cacheTranslation($cacheKey, $results);

        return $results;
    }

    public function getFaqItemsByCategory(string $categorySlug, ?string $locale = null): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildCategoryFaqCacheKey($categorySlug, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $faqs = $this->faqRepository->findByCategorySlug($categorySlug);

        $results = [];
        foreach ($faqs as $faq) {
            $results[] = $this->translateFaqItem($faq, $locale);
        }

        $this->translator->cacheTranslation($cacheKey, $results);

        return $results;
    }

    public function invalidateFaqItem(int $faqId): void
    {
        foreach (self::SUPPORTED_LOCALES as $locale) {
            $cacheKey = $this->buildFaqCacheKey($faqId, $locale);
            $this->translator->invalidateCache($cacheKey);
        }

        foreach (self::SUPPORTED_LOCALES as $locale) {
            $allKey = $this->buildAllFaqCacheKey($locale);
            $this->translator->invalidateCache($allKey);
        }

        $faq = $this->faqRepository->find($faqId);
        if ($faq !== null) {
            foreach (self::SUPPORTED_LOCALES as $locale) {
                $categoryKey = $this->buildCategoryFaqCacheKey($faq->getCategorySlug(), $locale);
                $this->translator->invalidateCache($categoryKey);
            }
        }

        $this->logger->debug('Invalidated faq item localization', [
            'faq_id' => $faqId,
        ]);
    }

    public function invalidateAllForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern('faq_item:*:' . $locale);

        $this->logger->info('Invalidated all faq items for locale', [
            'locale' => $locale,
        ]);
    }

    public function updateFaqTranslation(int $faqId, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildFaqCacheKey($faqId, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        foreach (self::SUPPORTED_LOCALES as $l) {
            $this->translator->invalidateCache($this->buildAllFaqCacheKey($l));
        }

        $faq = $this->faqRepository->find($faqId);
        if ($faq !== null) {
            $this->translator->invalidateCache($this->buildCategoryFaqCacheKey($faq->getCategorySlug(), $locale));
        }

        $this->metrics->increment('localization.update', [
            'type' => 'faq_item',
            'faq_id' => (string) $faqId,
            'locale' => $locale,
        ]);
    }

    public function getAvailableTranslations(int $faqId): array
    {
        $translations = [];

        foreach (self::SUPPORTED_LOCALES as $locale) {
            $cacheKey = $this->buildFaqCacheKey($faqId, $locale);
            $cached = $this->translator->getCachedTranslation($cacheKey);
            $translations[$locale] = $cached !== null;
        }

        return $translations;
    }

    public function getMissingTranslations(int $faqId): array
    {
        $available = $this->getAvailableTranslations($faqId);
        $missing = [];

        foreach ($available as $locale => $exists) {
            if (!$exists) {
                $missing[] = $locale;
            }
        }

        return $missing;
    }

    public function warmCacheForFaq(int $faqId): void
    {
        $faq = $this->faqRepository->find($faqId);

        if ($faq === null) {
            return;
        }

        foreach (self::SUPPORTED_LOCALES as $locale) {
            $data = $this->translateFaqItem($faq, $locale);
            $this->translator->cacheTranslation($this->buildFaqCacheKey($faqId, $locale), $data);
        }

        $this->logger->debug('Warmed localization cache for faq item', [
            'faq_id' => $faqId,
        ]);
    }

    private function buildFaqCacheKey(int $faqId, string $locale): string
    {
        return "faq_item:{$faqId}:{$locale}";
    }

    private function buildAllFaqCacheKey(string $locale): string
    {
        return "faq_item:all:{$locale}";
    }

    private function buildCategoryFaqCacheKey(string $categorySlug, string $locale): string
    {
        return "faq_item:category:{$categorySlug}:{$locale}";
    }

    private function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    private function translateFaqItem(object $faq, string $locale): array
    {
        return [
            'id' => $faq->getId(),
            'category_slug' => $faq->getCategorySlug(),
            'question' => $this->translator->translate($faq->getQuestionKey(), $locale),
            'answer' => $this->translator->translate($faq->getAnswerKey(), $locale),
            'order' => $faq->getOrder(),
            'locale' => $locale,
        ];
    }
}
