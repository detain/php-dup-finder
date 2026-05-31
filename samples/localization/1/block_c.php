<?php
declare(strict_types=1);

namespace App\Localization\Handlers;

use App\Service\TranslationService;
use App\Repository\CategoryRepository;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class CategoryLocalizationHandler
{
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt', 'ja', 'zh'];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly CategoryRepository $categoryRepository,
        private readonly LocaleService $localeService,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getLocalizedCategory(int $categoryId, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildCategoryCacheKey($categoryId, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => 'category', 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => 'category', 'locale' => $locale]);

        $category = $this->categoryRepository->find($categoryId);

        if ($category === null) {
            return null;
        }

        $data = $this->translateCategory($category, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getLocalizedCategoryList(array $categoryIds, ?string $locale = null): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $results = [];

        foreach ($categoryIds as $categoryId) {
            $localized = $this->getLocalizedCategory($categoryId, $locale);
            if ($localized !== null) {
                $results[$categoryId] = $localized;
            }
        }

        return $results;
    }

    public function getCategoryBySlug(string $slug, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildSlugCacheKey($slug, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $category = $this->categoryRepository->findBySlug($slug);

        if ($category === null) {
            return null;
        }

        $data = $this->translateCategory($category, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getCategoryTree(?string $locale = null, ?int $parentId = null): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $categories = $this->categoryRepository->findByParentId($parentId);

        $results = [];
        foreach ($categories as $category) {
            $localized = $this->translateCategory($category, $locale);
            $localized['children'] = $this->getCategoryTree($locale, $category->getId());
            $results[] = $localized;
        }

        return $results;
    }

    public function invalidateCategoryLocalization(int $categoryId): void
    {
        foreach (self::SUPPORTED_LOCALES as $locale) {
            $cacheKey = $this->buildCategoryCacheKey($categoryId, $locale);
            $this->translator->invalidateCache($cacheKey);
        }

        $category = $this->categoryRepository->find($categoryId);
        if ($category !== null) {
            $slugCacheKey = $this->buildSlugCacheKey($category->getSlug(), $locale ?? self::DEFAULT_LOCALE);
            $this->translator->invalidateCache($slugCacheKey);
        }

        $this->logger->debug('Invalidated category localization', [
            'category_id' => $categoryId,
        ]);
    }

    public function invalidateAllForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern('category:*:' . $locale);

        $this->logger->info('Invalidated all categories for locale', [
            'locale' => $locale,
        ]);
    }

    public function updateCategoryTranslation(int $categoryId, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildCategoryCacheKey($categoryId, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        $category = $this->categoryRepository->find($categoryId);
        if ($category !== null) {
            $slugCacheKey = $this->buildSlugCacheKey($category->getSlug(), $locale);
            $this->translator->cacheTranslation($slugCacheKey, $translatedData);
        }

        $this->metrics->increment('localization.update', [
            'type' => 'category',
            'category_id' => (string) $categoryId,
            'locale' => $locale,
        ]);
    }

    public function getAvailableTranslations(int $categoryId): array
    {
        $translations = [];

        foreach (self::SUPPORTED_LOCALES as $locale) {
            $cacheKey = $this->buildCategoryCacheKey($categoryId, $locale);
            $cached = $this->translator->getCachedTranslation($cacheKey);
            $translations[$locale] = $cached !== null;
        }

        return $translations;
    }

    public function getMissingTranslations(int $categoryId): array
    {
        $available = $this->getAvailableTranslations($categoryId);
        $missing = [];

        foreach ($available as $locale => $exists) {
            if (!$exists) {
                $missing[] = $locale;
            }
        }

        return $missing;
    }

    public function warmCacheForCategory(int $categoryId): void
    {
        $category = $this->categoryRepository->find($categoryId);

        if ($category === null) {
            return;
        }

        foreach (self::SUPPORTED_LOCALES as $locale) {
            $data = $this->translateCategory($category, $locale);
            $cacheKey = $this->buildCategoryCacheKey($categoryId, $locale);
            $this->translator->cacheTranslation($cacheKey, $data);
        }

        $this->logger->debug('Warmed localization cache for category', [
            'category_id' => $categoryId,
        ]);
    }

    public function getLocalizedBreadcrumbs(int $categoryId, ?string $locale = null): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();
        $category = $this->getLocalizedCategory($categoryId, $locale);

        if ($category === null) {
            return [];
        }

        $breadcrumbs = [];
        $currentId = $category['parent_id'] ?? null;

        while ($currentId !== null) {
            $parent = $this->getLocalizedCategory($currentId, $locale);
            if ($parent !== null) {
                array_unshift($breadcrumbs, $parent);
                $currentId = $parent['parent_id'] ?? null;
            } else {
                break;
            }
        }

        $breadcrumbs[] = $category;

        return $breadcrumbs;
    }

    public function getLocalizedCategoryUrl(int $categoryId, ?string $locale = null): string
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();
        $category = $this->getLocalizedCategory($categoryId, $locale);

        if ($category === null) {
            return '/';
        }

        return '/' . $locale . '/category/' . ($category['slug'] ?? $categoryId);
    }

    private function buildCategoryCacheKey(int $categoryId, string $locale): string
    {
        return "category:{$categoryId}:{$locale}";
    }

    private function buildSlugCacheKey(string $slug, string $locale): string
    {
        return "category:slug:{$slug}:{$locale}";
    }

    private function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    private function translateCategory(object $category, string $locale): array
    {
        $data = [
            'id' => $category->getId(),
            'slug' => $category->getSlug(),
            'name' => $this->translator->translate($category->getNameKey(), $locale),
            'description' => $this->translator->translate($category->getDescriptionKey(), $locale),
            'meta_title' => $this->translator->translate($category->getMetaTitleKey(), $locale),
            'meta_description' => $this->translator->translate($category->getMetaDescriptionKey(), $locale),
            'parent_id' => $category->getParentId(),
            'locale' => $locale,
            'created_at' => $category->getCreatedAt()?->format(\DATE_ATOM),
            'updated_at' => $category->getUpdatedAt()?->format(\DATE_ATOM),
        ];

        return $data;
    }
}
