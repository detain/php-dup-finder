<?php

declare(strict_types=1);

namespace App\View;

use Psr\Log\LoggerInterface;

final class UnifiedSearchRenderer
{
    /** @var array<string, SearchBarConfig> */
    private array $barConfigs = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        $this->initializeConfigs();
    }

    private function initializeConfigs(): void
    {
        $this->barConfigs['global'] = new SearchBarConfig(
            action: '/search',
            placeholder: 'Search...',
            showFilters: true,
            showSuggestions: true,
            filters: ['all', 'pages', 'articles', 'products'],
        );

        $this->barConfigs['admin'] = new SearchBarConfig(
            action: '/admin/search',
            placeholder: 'Search users, orders, content...',
            showFilters: true,
            showSuggestions: false,
            filters: ['all', 'users', 'orders', 'content'],
        );

        $this->barConfigs['product'] = new SearchBarConfig(
            action: '/products/search',
            placeholder: 'Search products...',
            showFilters: true,
            showSuggestions: true,
            filters: ['relevance', 'price_asc', 'price_desc', 'newest'],
        );
    }

    public function render(string $type, string $query = '', array $options = []): string
    {
        $config = $this->barConfigs[$type] ?? $this->barConfigs['global'];

        $placeholder = $options['placeholder'] ?? $config->placeholder;
        $showFilters = $options['show_filters'] ?? $config->showFilters;
        $showSuggestions = $options['show_suggestions'] ?? $config->showSuggestions;

        $html = '<div class="unified-search search-type-' . $type . '">';
        $html .= '<form class="search-form" action="' . htmlspecialchars($config->action) . '" method="GET" role="search">';
        $html .= '<div class="search-input-container">';

        if ($type === 'product' && ($options['show_category_dropdown'] ?? true)) {
            $html .= $this->renderCategoryDropdown();
        }

        $html .= '<div class="search-input-wrapper">';
        $html .= '<span class="search-icon" aria-hidden="true">🔍</span>';
        $html .= '<input type="search"';
        $html .= ' name="q"';
        $html .= ' value="' . htmlspecialchars($query) . '"';
        $html .= ' class="search-input"';
        $html .= ' placeholder="' . htmlspecialchars($placeholder) . '"';
        $html .= ' aria-label="Search"';
        $html .= ' autocomplete="off"';
        $html .= '/>';
        $html .= '</div>';

        if ($showFilters) {
            $html .= $this->renderFilterChips($config->filters, $options['active_filter'] ?? 'all');
        }

        $html .= '<button type="submit" class="search-submit-btn">Search</button>';
        $html .= '</div>';
        $html .= '</form>';

        if ($showSuggestions) {
            $html .= '<div class="search-suggestions-panel" hidden></div>';
        }

        $html .= '</div>';

        $this->logger->debug('Rendered unified search bar', ['type' => $type]);

        return $html;
    }

    private function renderCategoryDropdown(): string
    {
        $categories = [
            'electronics' => 'Electronics',
            'clothing' => 'Clothing',
            'home' => 'Home & Garden',
            'sports' => 'Sports',
            'books' => 'Books',
        ];

        $html = '<div class="search-category-dropdown">';
        $html .= '<select name="category" class="category-select" aria-label="Category filter">';
        $html .= '<option value="">All Categories</option>';

        foreach ($categories as $value => $label) {
            $html .= '<option value="' . $value . '">' . $label . '</option>';
        }

        $html .= '</select>';
        $html .= '</div>';

        return $html;
    }

    private function renderFilterChips(array $filters, string $activeFilter): string
    {
        $html = '<div class="search-filter-chips" role="group" aria-label="Search filters">';

        foreach ($filters as $filter) {
            $selected = $filter === $activeFilter ? ' chip-active' : '';
            $html .= '<button type="button" class="filter-chip' . $selected . '" data-filter="' . $filter . '">';
            $html .= htmlspecialchars(ucfirst(str_replace('_', ' ', $filter)));
            $html .= '</button>';
        }

        $html .= '</div>';

        return $html;
    }

    public function renderSuggestions(array $suggestions, string $query): string
    {
        if (empty($suggestions)) {
            return '<div class="no-suggestions">No suggestions for "' . htmlspecialchars($query) . '"</div>';
        }

        $html = '<div class="suggestions-dropdown" role="listbox">';

        foreach ($suggestions as $group => $items) {
            $html .= '<div class="suggestion-group">';
            $html .= '<span class="suggestion-group-label">' . htmlspecialchars(ucfirst($group)) . '</span>';
            $html .= '<ul class="suggestion-items">';

            foreach ($items as $item) {
                $html .= '<li class="suggestion-item" role="option">';
                $html .= '<a href="' . htmlspecialchars($item['url']) . '" class="suggestion-link">';
                $html .= '<span class="suggestion-text">' . htmlspecialchars($item['title']) . '</span>';
                if (isset($item['subtitle'])) {
                    $html .= '<span class="suggestion-subtitle">' . htmlspecialchars($item['subtitle']) . '</span>';
                }
                $html .= '</a>';
                $html .= '</li>';
            }

            $html .= '</ul>';
            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    public function renderResultsInfo(int $total, string $query, int $page, int $perPage): string
    {
        $from = (($page - 1) * $perPage) + 1;
        $to = min($page * $perPage, $total);

        $html = '<div class="search-results-info">';
        $html .= '<span class="results-total">' . number_format($total) . ' results</span>';
        $html .= '<span class="results-query">for "<strong>' . htmlspecialchars($query) . '</strong>"</span>';
        $html .= '<span class="results-range">' . $from . '-' . $to . '</span>';
        $html .= '</div>';

        return $html;
    }
}

final class SearchBarConfig
{
    public function __construct(
        public readonly string $action,
        public readonly string $placeholder,
        public readonly bool $showFilters,
        public readonly bool $showSuggestions,
        public readonly array $filters,
    ) {}
}
