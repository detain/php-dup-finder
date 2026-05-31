<?php

declare(strict_types=1);

namespace App\View\Search;

use Psr\Log\LoggerInterface;

final class GlobalSearchRenderer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function renderSearchBar(string $query = '', array $options = []): string
    {
        $placeholder = $options['placeholder'] ?? 'Search the site...';
        $autofocus = $options['autofocus'] ?? false;
        $showFilters = $options['show_filters'] ?? true;

        $html = '<div class="global-search-container">';
        $html .= '<form class="global-search-form" role="search" action="/search" method="GET">';
        $html .= '<div class="search-input-wrapper">';
        $html .= '<span class="search-icon">🔍</span>';
        $html .= '<input type="search"';
        $html .= ' name="q"';
        $html .= ' value="' . htmlspecialchars($query) . '"';
        $html .= ' class="global-search-input"';
        $html .= ' placeholder="' . htmlspecialchars($placeholder) . '"';
        $html .= ' aria-label="Search query"';
        if ($autofocus) {
            $html .= ' autofocus';
        }
        $html .= ' autocomplete="off"';
        $html .= ' spellcheck="false"';
        $html .= '/>';
        $html .= '<button type="button" class="search-clear-btn" aria-label="Clear search" hidden>×</button>';
        $html .= '</div>';

        if ($showFilters) {
            $html .= '<div class="search-filter-bar">';
            $html .= $this->renderFilterChips();
            $html .= '</div>';
        }

        $html .= '<button type="submit" class="global-search-submit">Search</button>';
        $html .= '</form>';
        $html .= '<div class="search-suggestions" hidden></div>';
        $html .= '</div>';

        $this->logger->debug('Rendered global search bar');

        return $html;
    }

    private function renderFilterChips(): string
    {
        $filters = [
            ['key' => 'all', 'label' => 'All', 'selected' => true],
            ['key' => 'pages', 'label' => 'Pages', 'selected' => false],
            ['key' => 'articles', 'label' => 'Articles', 'selected' => false],
            ['key' => 'products', 'label' => 'Products', 'selected' => false],
            ['key' => 'users', 'label' => 'Users', 'selected' => false],
        ];

        $html = '<div class="search-filter-chips" role="group" aria-label="Search filters">';

        foreach ($filters as $filter) {
            $selectedClass = $filter['selected'] ? ' chip-selected' : '';
            $html .= '<button type="button" class="filter-chip' . $selectedClass . '" data-filter="' . $filter['key'] . '">';
            $html .= htmlspecialchars($filter['label']);
            $html .= '</button>';
        }

        $html .= '</div>';
        return $html;
    }

    public function renderAutocompleteDropdown(array $suggestions, string $query): string
    {
        if (empty($suggestions)) {
            return '<div class="search-no-results">No results found for "' . htmlspecialchars($query) . '"</div>';
        }

        $html = '<div class="search-autocomplete-dropdown" role="listbox">';

        foreach ($suggestions as $category => $items) {
            $html .= '<div class="autocomplete-category">';
            $html .= '<span class="category-label">' . htmlspecialchars(ucfirst($category)) . '</span>';
            $html .= '<ul class="category-results">';

            foreach ($items as $item) {
                $html .= '<li class="result-item" role="option">';
                $html .= '<a href="' . htmlspecialchars($item['url']) . '" class="result-link">';
                $html .= '<span class="result-title">' . htmlspecialchars($item['title']) . '</span>';
                if (isset($item['snippet'])) {
                    $html .= '<span class="result-snippet">' . htmlspecialchars($item['snippet']) . '</span>';
                }
                $html .= '</a>';
                $html .= '</li>';
            }

            $html .= '</ul>';
            $html .= '</div>';
        }

        $html .= '<div class="autocomplete-footer">';
        $html .= '<a href="/search?q=' . urlencode($query) . '" class="view-all-results">View all results for "' . htmlspecialchars($query) . '"</a>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    public function renderSearchResultsHeader(int $totalResults, string $query, int $page, int $perPage): string
    {
        $from = (($page - 1) * $perPage) + 1;
        $to = min($page * $perPage, $totalResults);

        $html = '<div class="search-results-header">';
        $html .= '<div class="results-info">';
        $html .= '<span class="results-count">Found ' . number_format($totalResults) . ' results</span>';
        $html .= '<span class="results-query">for "<strong>' . htmlspecialchars($query) . '</strong>"</span>';
        $html .= '</div>';
        $html .= '<div class="results-pagination-info">';
        $html .= 'Showing ' . $from . '-' . $to . ' of ' . number_format($totalResults);
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }
}
