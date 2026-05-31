<?php
declare(strict_types=1);

namespace App\Localization\Handlers;

use App\Service\TranslationService;
use App\Repository\ProductRepository;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class ProductLocalizationHandler
{
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt', 'ja', 'zh'];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly ProductRepository $productRepository,
        private readonly LocaleService $localeService,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getLocalizedProduct(int $productId, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildProductCacheKey($productId, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => 'product', 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => 'product', 'locale' => $locale]);

        $product = $this->productRepository->find($productId);

        if ($product === null) {
            return null;
        }

        $data = $this->translateProduct($product, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getLocalizedProductList(array $productIds, ?string $locale = null): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $results = [];

        foreach ($productIds as $productId) {
            $localized = $this->getLocalizedProduct($productId, $locale);
            if ($localized !== null) {
                $results[$productId] = $localized;
            }
        }

        return $results;
    }

    public function getProductBySku(string $sku, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildSkuCacheKey($sku, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $product = $this->productRepository->findBySku($sku);

        if ($product === null) {
            return null;
        }

        $data = $this->translateProduct($product, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function searchLocalizedProducts(string $query, ?string $locale = null, int $limit = 20): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $products = $this->productRepository->search($query, $limit);

        $results = [];
        foreach ($products as $product) {
            $results[] = $this->translateProduct($product, $locale);
        }

        return $results;
    }

    public function invalidateProductLocalization(int $productId): void
    {
        foreach (self::SUPPORTED_LOCALES as $locale) {
            $cacheKey = $this->buildProductCacheKey($productId, $locale);
            $this->translator->invalidateCache($cacheKey);
        }

        $product = $this->productRepository->find($productId);
        if ($product !== null) {
            $skuCacheKey = $this->buildSkuCacheKey($product->getSku(), $locale ?? self::DEFAULT_LOCALE);
            $this->translator->invalidateCache($skuCacheKey);
        }

        $this->logger->debug('Invalidated product localization', [
            'product_id' => $productId,
        ]);
    }

    public function invalidateAllForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern('product:*:' . $locale);

        $this->logger->info('Invalidated all products for locale', [
            'locale' => $locale,
        ]);
    }

    public function updateProductTranslation(int $productId, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildProductCacheKey($productId, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        $product = $this->productRepository->find($productId);
        if ($product !== null) {
            $skuCacheKey = $this->buildSkuCacheKey($product->getSku(), $locale);
            $this->translator->cacheTranslation($skuCacheKey, $translatedData);
        }

        $this->metrics->increment('localization.update', [
            'type' => 'product',
            'product_id' => (string) $productId,
            'locale' => $locale,
        ]);
    }

    public function getAvailableTranslations(int $productId): array
    {
        $translations = [];

        foreach (self::SUPPORTED_LOCALES as $locale) {
            $cacheKey = $this->buildProductCacheKey($productId, $locale);
            $cached = $this->translator->getCachedTranslation($cacheKey);
            $translations[$locale] = $cached !== null;
        }

        return $translations;
    }

    public function getMissingTranslations(int $productId): array
    {
        $available = $this->getAvailableTranslations($productId);
        $missing = [];

        foreach ($available as $locale => $exists) {
            if (!$exists) {
                $missing[] = $locale;
            }
        }

        return $missing;
    }

    public function warmCacheForProduct(int $productId): void
    {
        $product = $this->productRepository->find($productId);

        if ($product === null) {
            return;
        }

        foreach (self::SUPPORTED_LOCALES as $locale) {
            $data = $this->translateProduct($product, $locale);
            $cacheKey = $this->buildProductCacheKey($productId, $locale);
            $this->translator->cacheTranslation($cacheKey, $data);
        }

        $this->logger->debug('Warmed localization cache for product', [
            'product_id' => $productId,
        ]);
    }

    public function formatLocalizedPrice(int $productId, ?string $locale = null): ?string
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();
        $product = $this->getLocalizedProduct($productId, $locale);

        if ($product === null) {
            return null;
        }

        return $this->translator->formatCurrency($product['price'], $locale, $product['currency'] ?? 'USD');
    }

    public function getLocalizedProductUrl(int $productId, ?string $locale = null): string
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();
        $product = $this->getLocalizedProduct($productId, $locale);

        if ($product === null) {
            return '/';
        }

        return '/' . $locale . '/product/' . ($product['slug'] ?? $productId);
    }

    public function getLocalizedCategoryPath(int $productId, ?string $locale = null): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();
        $product = $this->getLocalizedProduct($productId, $locale);

        if ($product === null || !isset($product['category_path'])) {
            return [];
        }

        return array_map(
            fn($category) => $this->translator->translate($category, $locale),
            $product['category_path']
        );
    }

    private function buildProductCacheKey(int $productId, string $locale): string
    {
        return "product:{$productId}:{$locale}";
    }

    private function buildSkuCacheKey(string $sku, string $locale): string
    {
        return "product:sku:{$sku}:{$locale}";
    }

    private function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    private function translateProduct(object $product, string $locale): array
    {
        $data = [
            'id' => $product->getId(),
            'sku' => $product->getSku(),
            'slug' => $product->getSlug(),
            'name' => $this->translator->translate($product->getNameKey(), $locale),
            'description' => $this->translator->translate($product->getDescriptionKey(), $locale),
            'short_description' => $this->translator->translate($product->getShortDescriptionKey(), $locale),
            'price' => $product->getPrice(),
            'currency' => $product->getCurrency(),
            'category_path' => $product->getCategoryPath(),
            'locale' => $locale,
            'created_at' => $product->getCreatedAt()?->format(\DATE_ATOM),
            'updated_at' => $product->getUpdatedAt()?->format(\DATE_ATOM),
        ];

        return $data;
    }
}
