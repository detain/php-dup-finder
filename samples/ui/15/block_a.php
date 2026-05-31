<?php

declare(strict_types=1);

namespace App\View\Navigation;

use App\Entity\MenuItem;
use App\Entity\User;
use Psr\Log\LoggerInterface;

final class AdminNavigationRenderer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function renderMainMenu(array $items, string $currentPath): string
    {
        $html = '<nav class="admin-nav main-nav" aria-label="Main navigation">';
        $html .= '<ul class="nav-list nav-level-0">';

        foreach ($items as $item) {
            $html .= $this->renderMenuItem($item, $currentPath, 0);
        }

        $html .= '</ul>';
        $html .= '</nav>';

        return $html;
    }

    private function renderMenuItem(MenuItem $item, string $currentPath, int $level): string
    {
        $hasChildren = !empty($item->getChildren());
        $isActive = $this->isItemActive($item, $currentPath);
        $isExpanded = $this->isItemExpanded($item, $currentPath);

        $activeClass = $isActive ? ' nav-item-active' : '';
        $expandedClass = $isExpanded ? ' nav-item-expanded' : '';
        $hasChildrenClass = $hasChildren ? ' nav-item-has-children' : '';

        $html = '<li class="nav-item nav-level-' . $level . $activeClass . $expandedClass . $hasChildrenClass . '">';
        $html .= '<a href="' . htmlspecialchars($item->getUrl()) . '" class="nav-link"';

        if ($isActive) {
            $html .= ' aria-current="page"';
        }

        $html .= '>';
        $html .= $this->renderItemIcon($item);
        $html .= '<span class="nav-label">' . htmlspecialchars($item->getLabel()) . '</span>';

        if ($hasChildren) {
            $html .= '<span class="nav-toggle" aria-expanded="' . ($isExpanded ? 'true' : 'false') . '">▼</span>';
        }

        $html .= '</a>';

        if ($hasChildren) {
            $html .= '<ul class="nav-list nav-level-' . ($level + 1) . '">';
            foreach ($item->getChildren() as $child) {
                $html .= $this->renderMenuItem($child, $currentPath, $level + 1);
            }
            $html .= '</ul>';
        }

        $html .= '</li>';

        return $html;
    }

    public function renderUserMenu(User $user, string $currentPath): string
    {
        $html = '<div class="user-menu-container">';
        $html .= '<button type="button" class="user-menu-trigger" aria-expanded="false" aria-haspopup="true">';
        $html .= '<span class="user-avatar">' . $this->getInitials($user->getFullName()) . '</span>';
        $html .= '<span class="user-name">' . htmlspecialchars($user->getFullName()) . '</span>';
        $html .= '<span class="user-role">' . htmlspecialchars($user->getRole()) . '</span>';
        $html .= '</button>';
        $html .= '<ul class="user-dropdown" role="menu">';

        $menuItems = [
            ['url' => '/profile', 'label' => 'My Profile', 'icon' => 'user'],
            ['url' => '/settings', 'label' => 'Settings', 'icon' => 'settings'],
            ['url' => '/help', 'label' => 'Help & Support', 'icon' => 'help'],
        ];

        foreach ($menuItems as $menuItem) {
            $isActive = $currentPath === $menuItem['url'];
            $activeClass = $isActive ? ' active' : '';
            $html .= '<li class="dropdown-item' . $activeClass . '" role="menuitem">';
            $html .= '<a href="' . htmlspecialchars($menuItem['url']) . '" class="dropdown-link">';
            $html .= '<span class="dropdown-icon icon-' . $menuItem['icon'] . '"></span>';
            $html .= '<span class="dropdown-label">' . htmlspecialchars($menuItem['label']) . '</span>';
            $html .= '</a>';
            $html .= '</li>';
        }

        $html .= '<li class="dropdown-divider" role="separator"></li>';
        $html .= '<li class="dropdown-item" role="menuitem">';
        $html .= '<a href="/logout" class="dropdown-link text-danger">';
        $html .= '<span class="dropdown-icon icon-logout"></span>';
        $html .= '<span class="dropdown-label">Sign Out</span>';
        $html .= '</a>';
        $html .= '</li>';
        $html .= '</ul>';
        $html .= '</div>';

        return $html;
    }

    private function isItemActive(MenuItem $item, string $currentPath): bool
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

    private function isItemExpanded(MenuItem $item, string $currentPath): bool
    {
        foreach ($item->getChildren() as $child) {
            if ($this->isItemActive($child, $currentPath)) {
                return true;
            }
        }
        return false;
    }

    private function renderItemIcon(MenuItem $item): string
    {
        $icon = $item->getIcon();
        if ($icon === null) {
            return '';
        }
        return '<span class="nav-icon icon-' . htmlspecialchars($icon) . '"></span>';
    }

    private function getInitials(string $name): string
    {
        $parts = explode(' ', $name);
        $initials = '';
        foreach ($parts as $part) {
            $initials .= strtoupper(substr($part, 0, 1));
        }
        return substr($initials, 0, 2);
    }
}
