<?php
declare(strict_types=1);

namespace App\Localization\Handlers;

use App\Service\TranslationService;
use App\Repository\MenuItemRepository;
use App\Service\LocaleService;
use App\Service\MetricsService;
use Psr\Log\LoggerInterface;

final class MenuLocalizationHandler
{
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'es', 'fr', 'de', 'it', 'pt', 'ja', 'zh', 'ko', 'ar'];

    public function __construct(
        private readonly TranslationService $translator,
        private readonly MenuItemRepository $menuItemRepository,
        private readonly LocaleService $localeService,
        private readonly MetricsService $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getLocalizedMenuItem(int $menuItemId, ?string $locale = null): ?array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildMenuItemCacheKey($menuItemId, $locale);
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            $this->metrics->increment('localization.hit', ['type' => 'menu_item', 'locale' => $locale]);
            return $cached;
        }

        $this->metrics->increment('localization.miss', ['type' => 'menu_item', 'locale' => $locale]);

        $menuItem = $this->menuItemRepository->find($menuItemId);

        if ($menuItem === null) {
            return null;
        }

        $data = $this->translateMenuItem($menuItem, $locale);
        $this->translator->cacheTranslation($cacheKey, $data);

        return $data;
    }

    public function getMenuTree(?string $locale = null, ?string $menuLocation = null): array
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();

        if (!$this->isSupportedLocale($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        $cacheKey = $this->buildMenuTreeCacheKey($locale, $menuLocation ?? 'main');
        $cached = $this->translator->getCachedTranslation($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $menuItems = $this->menuItemRepository->findByLocation($menuLocation ?? 'main');

        $results = [];
        foreach ($menuItems as $item) {
            $results[] = $this->translateMenuItem($item, $locale);
        }

        $tree = $this->buildMenuTreeFromFlat($results);

        $this->translator->cacheTranslation($cacheKey, $tree);

        return $tree;
    }

    public function invalidateMenuItem(int $menuItemId): void
    {
        foreach (self::SUPPORTED_LOCALES as $locale) {
            $cacheKey = $this->buildMenuItemCacheKey($menuItemId, $locale);
            $this->translator->invalidateCache($cacheKey);
        }

        $menuItem = $this->menuItemRepository->find($menuItemId);
        if ($menuItem !== null) {
            foreach (self::SUPPORTED_LOCALES as $locale) {
                $treeKey = $this->buildMenuTreeCacheKey($locale, $menuItem->getLocation());
                $this->translator->invalidateCache($treeKey);
            }
        }

        $this->logger->debug('Invalidated menu item localization', [
            'menu_item_id' => $menuItemId,
        ]);
    }

    public function invalidateAllForLocale(string $locale): void
    {
        if (!$this->isSupportedLocale($locale)) {
            return;
        }

        $this->translator->invalidateCacheByPattern('menu_item:*:' . $locale);
        $this->translator->invalidateCacheByPattern('menu_tree:*:' . $locale);

        $this->logger->info('Invalidated all menu items for locale', [
            'locale' => $locale,
        ]);
    }

    public function updateMenuItemTranslation(int $menuItemId, string $locale, array $translatedData): void
    {
        if (!$this->isSupportedLocale($locale)) {
            throw new \InvalidArgumentException("Unsupported locale: {$locale}");
        }

        $cacheKey = $this->buildMenuItemCacheKey($menuItemId, $locale);
        $this->translator->cacheTranslation($cacheKey, $translatedData);

        $menuItem = $this->menuItemRepository->find($menuItemId);
        if ($menuItem !== null) {
            $treeKey = $this->buildMenuTreeCacheKey($locale, $menuItem->getLocation());
            $this->translator->invalidateCache($treeKey);
        }

        $this->metrics->increment('localization.update', [
            'type' => 'menu_item',
            'menu_item_id' => (string) $menuItemId,
            'locale' => $locale,
        ]);
    }

    public function warmCacheForMenuLocation(string $menuLocation): void
    {
        $menuItems = $this->menuItemRepository->findByLocation($menuLocation);

        foreach (self::SUPPORTED_LOCALES as $locale) {
            $itemsData = [];
            foreach ($menuItems as $item) {
                $data = $this->translateMenuItem($item, $locale);
                $itemsData[] = $data;
                $this->translator->cacheTranslation(
                    $this->buildMenuItemCacheKey($item->getId(), $locale),
                    $data
                );
            }

            $tree = $this->buildMenuTreeFromFlat($itemsData);
            $treeKey = $this->buildMenuTreeCacheKey($locale, $menuLocation);
            $this->translator->cacheTranslation($treeKey, $tree);
        }

        $this->logger->debug('Warmed localization cache for menu location', [
            'menu_location' => $menuLocation,
        ]);
    }

    public function getLocalizedLabel(int $menuItemId, ?string $locale = null): ?string
    {
        $menuItem = $this->getLocalizedMenuItem($menuItemId, $locale);
        return $menuItem['label'] ?? null;
    }

    public function getLocalizedUrl(int $menuItemId, ?string $locale = null): string
    {
        $locale = $locale ?? $this->localeService->getCurrentLocale();
        $menuItem = $this->getLocalizedMenuItem($menuItemId, $locale);

        if ($menuItem === null) {
            return '#';
        }

        if (!empty($menuItem['external_url'])) {
            return $menuItem['external_url'];
        }

        return '/' . $locale . '/' . ltrim($menuItem['url'], '/');
    }

    private function buildMenuItemCacheKey(int $menuItemId, string $locale): string
    {
        return "menu_item:{$menuItemId}:{$locale}";
    }

    private function buildMenuTreeCacheKey(string $locale, string $location): string
    {
        return "menu_tree:{$location}:{$locale}";
    }

    private function isSupportedLocale(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED_LOCALES, true);
    }

    private function translateMenuItem(object $menuItem, string $locale): array
    {
        return [
            'id' => $menuItem->getId(),
            'parent_id' => $menuItem->getParentId(),
            'location' => $menuItem->getLocation(),
            'label' => $this->translator->translate($menuItem->getLabelKey(), $locale),
            'url' => $menuItem->getUrl(),
            'external_url' => $menuItem->getExternalUrl(),
            'icon' => $menuItem->getIcon(),
            'order' => $menuItem->getOrder(),
            'locale' => $locale,
        ];
    }

    private function buildMenuTreeFromFlat(array $items): array
    {
        $lookup = [];
        $tree = [];

        foreach ($items as $item) {
            $lookup[$item['id']] = $item;
            $item['children'] = [];
        }

        foreach ($items as $item) {
            if ($item['parent_id'] === null) {
                $tree[] = &$lookup[$item['id']];
            } else {
                if (isset($lookup[$item['parent_id']])) {
                    $lookup[$item['parent_id']]['children'][] = &$lookup[$item['id']];
                }
            }
        }

        return $tree;
    }
}
