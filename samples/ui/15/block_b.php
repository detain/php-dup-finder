<?php

declare(strict_types=1);

namespace App\View\Navigation;

use App\Entity\SidebarItem;
use Psr\Log\LoggerInterface;

final class SidebarNavigationRenderer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function renderSidebar(array $items, string $currentPath, string $sectionTitle = ''): string
    {
        $html = '<aside class="sidebar" role="navigation" aria-label="Sidebar navigation">';

        if ($sectionTitle !== '') {
            $html .= '<div class="sidebar-header">';
            $html .= '<h2 class="sidebar-title">' . htmlspecialchars($sectionTitle) . '</h2>';
            $html .= '</div>';
        }

        $html .= '<div class="sidebar-content">';
        $html .= '<ul class="sidebar-list">';

        foreach ($items as $item) {
            $html .= $this->renderSidebarItem($item, $currentPath);
        }

        $html .= '</ul>';
        $html .= '</div>';
        $html .= '</aside>';

        return $html;
    }

    private function renderSidebarItem(SidebarItem $item, string $currentPath): string
    {
        $hasChildren = !empty($item->getChildren());
        $isActive = $this->isItemActive($item, $currentPath);
        $isExpanded = $this->shouldExpand($item, $currentPath);

        $itemClass = 'sidebar-item';
        if ($isActive) {
            $itemClass .= ' is-active';
        }
        if ($isExpanded) {
            $itemClass .= ' is-expanded';
        }
        if ($hasChildren) {
            $itemClass .= ' has-children';
        }

        $html = '<li class="' . $itemClass . '">';
        $html .= '<a href="' . htmlspecialchars($item->getUrl()) . '" class="sidebar-link"';

        if ($isActive) {
            $html .= ' aria-current="page"';
        }

        $html .= '>';
        $html .= $this->renderItemIcon($item);
        $html .= '<span class="sidebar-label">' . htmlspecialchars($item->getLabel()) . '</span>';

        if ($hasChildren) {
            $html .= '<span class="sidebar-expand-icon">' . ($isExpanded ? '▲' : '▼') . '</span>';
        }

        $html .= '</a>';

        if ($hasChildren) {
            $html .= '<ul class="sidebar-sublist"';
            if (!$isExpanded) {
                $html .= ' hidden';
            }
            $html .= '>';
            foreach ($item->getChildren() as $child) {
                $html .= $this->renderSidebarItem($child, $currentPath);
            }
            $html .= '</ul>';
        }

        $html .= '</li>';

        return $html;
    }

    public function renderQuickLinks(array $links): string
    {
        $html = '<div class="quick-links">';
        $html .= '<h3 class="quick-links-title">Quick Links</h3>';
        $html .= '<ul class="quick-links-list">';

        foreach ($links as $link) {
            $html .= '<li class="quick-link-item">';
            $html .= '<a href="' . htmlspecialchars($link['url']) . '" class="quick-link">';
            $html .= '<span class="quick-link-icon"></span>';
            $html .= '<span class="quick-link-label">' . htmlspecialchars($link['label']) . '</span>';
            $html .= '</a>';
            $html .= '</li>';
        }

        $html .= '</ul>';
        $html .= '</div>';

        return $html;
    }

    private function isItemActive(SidebarItem $item, string $currentPath): bool
    {
        if ($item->getUrl() === $currentPath) {
            return true;
        }

        foreach ($item->getChildren() as $child) {
            if ($this->isItemActive($child, $currentPath)) {
                return true;
            }
        }

        return false;
    }

    private function shouldExpand(SidebarItem $item, string $currentPath): bool
    {
        foreach ($item->getChildren() as $child) {
            if ($this->isItemActive($child, $currentPath)) {
                return true;
            }
        }
        return false;
    }

    private function renderItemIcon(SidebarItem $item): string
    {
        $icon = $item->getIcon();
        if ($icon === null) {
            return '<span class="sidebar-icon-default"></span>';
        }
        return '<span class="sidebar-icon icon-' . htmlspecialchars($icon) . '"></span>';
    }
}
