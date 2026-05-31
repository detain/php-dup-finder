<?php

declare(strict_types=1);

namespace App\View\Search;

use Psr\Log\LoggerInterface;

final class AdminSearchRenderer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function renderAdminSearchBar(string $query = '', array $options = []): string
    {
        $placeholder = $options['placeholder'] ?? 'Search users, orders, content...';
        $recentSearches = $options['recent_searches'] ?? [];

        $html = '<div class="admin-search-bar-wrapper">';
        $html .= '<form class="admin-search-form" method="GET" action="/admin/search" role="search">';
        $html .= '<div class="admin-search-field-group">';
        $html .= '<select name="search_type" class="search-type-select">';
        $html .= '<option value="all">All</option>';
        $html .= '<option value="users">Users</option>';
        $html .= '<option value="orders">Orders</option>';
        $html .= '<option value="content">Content</option>';
        $html .= '<option value="settings">Settings</option>';
        $html .= '</select>';
        $html .= '<div class="admin-search-input-wrapper">';
        $html .= '<input type="search"';
        $html .= ' name="query"';
        $html .= ' value="' . htmlspecialchars($query) . '"';
        $html .= ' class="admin-search-input"';
        $html .= ' placeholder="' . htmlspecialchars($placeholder) . '"';
        $html .= ' aria-label="Admin search"';
        $html .= '/>';
        $html .= '<svg class="search-input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>';
        $html .= '</div>';
        $html .= '<button type="submit" class="admin-search-btn">Search</button>';
        $html .= '</div>';
        $html .= '</form>';

        if (!empty($recentSearches)) {
            $html .= '<div class="recent-searches">';
            $html .= '<span class="recent-label">Recent:</span>';
            $html .= '<ul class="recent-list">';
            foreach (array_slice($recentSearches, 0, 5) as $recent) {
                $html .= '<li><a href="/admin/search?query=' . urlencode($recent) . '" class="recent-link">' . htmlspecialchars($recent) . '</a></li>';
            }
            $html .= '</ul>';
            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    public function renderAdvancedSearchPanel(array $availableFields): string
    {
        $html = '<div class="advanced-search-panel" id="advanced_search_panel">';
        $html .= '<form method="GET" action="/admin/search/advanced" class="advanced-search-form">';
        $html .= '<div class="advanced-search-grid">';

        foreach ($availableFields as $field) {
            $html .= '<div class="field-group field-type-' . $field['type'] . '">';
            $html .= '<label class="field-group-label">' . htmlspecialchars($field['label']) . '</label>';

            if ($field['type'] === 'text') {
                $html .= '<input type="text" name="' . $field['name'] . '" class="adv-input" />';
            } elseif ($field['type'] === 'select') {
                $html .= '<select name="' . $field['name'] . '" class="adv-select">';
                $html .= '<option value="">Any ' . htmlspecialchars($field['label']) . '</option>';
                foreach ($field['options'] as $opt) {
                    $html .= '<option value="' . htmlspecialchars($opt['value']) . '">' . htmlspecialchars($opt['label']) . '</option>';
                }
                $html .= '</select>';
            } elseif ($field['type'] === 'date_range') {
                $html .= '<div class="adv-date-range">';
                $html .= '<input type="date" name="' . $field['name'] . '_from" class="adv-date-input" />';
                $html .= '<span>to</span>';
                $html .= '<input type="date" name="' . $field['name'] . '_to" class="adv-date-input" />';
                $html .= '</div>';
            }

            $html .= '</div>';
        }

        $html .= '</div>';
        $html .= '<div class="advanced-search-actions">';
        $html .= '<button type="submit" class="adv-search-btn">Run Advanced Search</button>';
        $html .= '<button type="reset" class="adv-reset-btn">Clear Fields</button>';
        $html .= '</div>';
        $html .= '</form>';
        $html .= '</div>';

        return $html;
    }

    public function renderSearchFilters(array $activeFilters): string
    {
        if (empty($activeFilters)) {
            return '';
        }

        $html = '<div class="admin-search-filters">';
        $html .= '<div class="active-filters-header">';
        $html .= '<span class="filters-title">Active Filters:</span>';
        $html .= '<button type="button" class="clear-all-filters-btn">Clear All</button>';
        $html .= '</div>';
        $html .= '<div class="active-filters-list">';

        foreach ($activeFilters as $filter) {
            $html .= '<div class="active-filter-item">';
            $html .= '<span class="filter-label">' . htmlspecialchars($filter['label']) . ':</span>';
            $html .= '<span class="filter-value">' . htmlspecialchars($filter['value']) . '</span>';
            $html .= '<button type="button" class="filter-remove-btn" data-filter-key="' . $filter['key'] . '">×</button>';
            $html .= '</div>';
        }

        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }
}
