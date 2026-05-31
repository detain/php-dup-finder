<?php

declare(strict_types=1);

namespace App\View\Navigation;

use App\Entity\BreadcrumbItem;
use Psr\Log\LoggerInterface;

final class BreadcrumbNavigationRenderer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function renderBreadcrumbs(array $items, BreadcrumbOptions $options = null): string
    {
        $options ??= new BreadcrumbOptions();
        $separator = $options->separator ?? '›';
        $showHome = $options->showHome ?? true;
        $homeLabel = $options->homeLabel ?? 'Home';

        $html = '<nav class="breadcrumb-nav" aria-label="Breadcrumb navigation">';
        $html .= '<ol class="breadcrumb-list" itemscope itemtype="https://schema.org/BreadcrumbList">';

        $position = 1;

        if ($showHome) {
            $html .= $this->renderBreadcrumbItem(
                $homeLabel,
                '/',
                $position,
                true,
                $options
            );
            $position++;
        }

        $lastIndex = count($items) - 1;
        foreach ($items as $index => $item) {
            $isLast = $index === $lastIndex;
            $html .= $this->renderBreadcrumbItem(
                $item->getLabel(),
                $item->getUrl(),
                $position,
                $isLast,
                $options
            );
            $position++;
        }

        $html .= '</ol>';
        $html .= '</nav>';

        return $html;
    }

    private function renderBreadcrumbItem(
        string $label,
        string $url,
        int $position,
        bool $isLast,
        BreadcrumbOptions $options
    ): string {
        $itemClass = 'breadcrumb-item';
        if ($isLast) {
            $itemClass .= ' is-current';
        }

        $html = '<li class="' . $itemClass . '" itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">';

        if (!$isLast) {
            $html .= '<a href="' . htmlspecialchars($url) . '" class="breadcrumb-link" itemprop="item">';
        }

        $html .= '<span class="breadcrumb-label" itemprop="name">' . htmlspecialchars($label) . '</span>';

        if (!$isLast) {
            $html .= '</a>';
        }

        $html .= '<meta itemprop="position" content="' . $position . '">';
        $html .= '</li>';

        if (!$isLast) {
            $html .= '<li class="breadcrumb-separator" aria-hidden="true">' . htmlspecialchars($options->separator ?? '›') . '</li>';
        }

        return $html;
    }

    public function renderFromPath(string $basePath, string $fullPath, string $homeLabel = 'Home'): string
    {
        $segments = array_filter(explode('/', trim($fullPath, '/')));
        $items = [];
        $cumulativePath = '';

        foreach ($segments as $segment) {
            $cumulativePath .= '/' . $segment;
            $label = $this->formatSegmentLabel($segment);
            $items[] = new BreadcrumbItem($label, $cumulativePath);
        }

        return $this->renderBreadcrumbs($items, new BreadcrumbOptions(homeLabel: $homeLabel));
    }

    private function formatSegmentLabel(string $segment): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $segment));
    }
}
