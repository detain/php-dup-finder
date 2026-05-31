<?php

declare(strict_types=1);

namespace App\View;

use Psr\Log\LoggerInterface;

final class UnifiedNavigationRenderer
{
    /** @var array<string, callable> */
    private array $itemRenderers = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        $this->registerRenderers();
    }

    private function registerRenderers(): void
    {
        $this->itemRenderers['nav'] = fn($item, $currentPath, $level) => $this->renderNavItem($item, $currentPath, $level);
        $this->itemRenderers['sidebar'] = fn($item, $currentPath, $level) => $this->renderSidebarItem($item, $currentPath, $level);
        $this->itemRenderers['breadcrumb'] = fn($item, $currentPath, $level) => $this->renderBreadcrumbItem($item, $currentPath, $level);
    }

    public function renderNavigation(string $type, array $items, string $currentPath, array $options = []): string
    {
        $renderer = $this->itemRenderers[$type] ?? null;

        if ($renderer === null) {
            $this->logger->warning('Unknown navigation type', ['type' => $type]);
            return '';
        }

        $html = '<nav class="' . $type . '-nav" aria-label="' . ucfirst($type) . ' navigation">';
        $html .= '<ul class="' . $type . '-list">';

        foreach ($items as $item) {
            $html .= $renderer($item, $currentPath, 0);
        }

        $html .= '</ul>';
        $html .= '</nav>';

        return $html;
    }

    private function renderNavItem(object $item, string $currentPath, int $level): string
    {
        $hasChildren = method_exists($item, 'getChildren') && !empty($item->getChildren());
        $isActive = $this->isActive($item, $currentPath);
        $isExpanded = $this->isExpanded($item, $currentPath);

        $html = '<li class="nav-item level-' . $level . ($isActive ? ' active' : '') . ($hasChildren ? ' has-children' : '') . '">';
        $html .= '<a href="' . htmlspecialchars($item->getUrl()) . '" class="nav-link"' . ($isActive ? ' aria-current="page"' : '') . '>';
        $html .= $this->renderIcon($item);
        $html .= '<span>' . htmlspecialchars($item->getLabel()) . '</span>';
        $html .= '</a>';

        if ($hasChildren) {
            $html .= '<ul class="nav-sublist">';
            foreach ($item->getChildren() as $child) {
                $html .= $this->renderNavItem($child, $currentPath, $level + 1);
            }
            $html .= '</ul>';
        }

        $html .= '</li>';
        return $html;
    }

    private function renderSidebarItem(object $item, string $currentPath, int $level): string
    {
        $hasChildren = method_exists($item, 'getChildren') && !empty($item->getChildren());
        $isActive = $this->isActive($item, $currentPath);
        $isExpanded = $this->isExpanded($item, $currentPath);

        $html = '<li class="sidebar-item' . ($isActive ? ' active' : '') . ($hasChildren ? ' has-children' : '') . '">';
        $html .= '<a href="' . htmlspecialchars($item->getUrl()) . '" class="sidebar-link"' . ($isActive ? ' aria-current="page"' : '') . '>';
        $html .= $this->renderIcon($item);
        $html .= '<span>' . htmlspecialchars($item->getLabel()) . '</span>';
        $html .= '</a>';

        if ($hasChildren) {
            $html .= '<ul class="sidebar-sublist"' . (!$isExpanded ? ' hidden' : '') . '>';
            foreach ($item->getChildren() as $child) {
                $html .= $this->renderSidebarItem($child, $currentPath, $level + 1);
            }
            $html .= '</ul>';
        }

        $html .= '</li>';
        return $html;
    }

    private function renderBreadcrumbItem(object $item, string $currentPath, int $level): string
    {
        $isActive = $item->getUrl() === $currentPath;

        $html = '<li class="breadcrumb-item' . ($isActive ? ' current' : '') . '">';
        if (!$isActive) {
            $html .= '<a href="' . htmlspecialchars($item->getUrl()) . '" class="breadcrumb-link">';
        }
        $html .= '<span>' . htmlspecialchars($item->getLabel()) . '</span>';
        if (!$isActive) {
            $html .= '</a>';
        }
        $html .= '</li>';

        return $html;
    }

    private function isActive(object $item, string $currentPath): bool
    {
        if ($item->getUrl() === $currentPath) {
            return true;
        }

        if (method_exists($item, 'getChildren')) {
            foreach ($item->getChildren() as $child) {
                if ($this->isActive($child, $currentPath)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isExpanded(object $item, string $currentPath): bool
    {
        if (!method_exists($item, 'getChildren')) {
            return false;
        }

        foreach ($item->getChildren() as $child) {
            if ($this->isActive($child, $currentPath)) {
                return true;
            }
        }

        return false;
    }

    private function renderIcon(object $item): string
    {
        if (!method_exists($item, 'getIcon') || $item->getIcon() === null) {
            return '';
        }
        return '<span class="nav-icon icon-' . htmlspecialchars($item->getIcon()) . '"></span>';
    }
}
