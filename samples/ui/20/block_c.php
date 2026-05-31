<?php

declare(strict_types=1);

namespace App\View\Search;

use Psr\Log\LoggerInterface;

final class ProductSearchRenderer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function renderProductSearchBar(string $query = '', array $options = []): string
    {
        $placeholder = $options['placeholder'] ?? 'Search for products, brands, categories...';
        $showCategories = $options['show_categories'] ?? true;

        $html = '<div class="product-search-wrapper">';
        $html .= '<form class="product-search-form" method="GET" action="/products/search" novalidate>';
        $html .= '<div class="product-search-row">';

        if ($showCategories) {
            $html .= '<div class="category-dropdown-wrapper">';
            $html .= '<select name="category" class="category-select" aria-label="Filter by category">';
            $html .= '<option value="">All Categories</option>';
            $html .= '<option value="electronics">Electronics</option>';
            $html .= '<option value="clothing">Clothing & Apparel</option>';
            $html .= '<option value="home">Home & Garden</option>';
            $html .= '<option value="sports">Sports & Outdoors</option>';
            $html .= '<option value="books">Books & Media</option>';
            $html .= '</select>';
            $html .= '</div>';
        }

        $html .= '<div class="product-search-input-area">';
        $html .= '<input type="search"';
        $html .= ' name="q"';
        $html .= ' value="' . htmlspecialchars($query) . '"';
        $html .= ' class="product-search-input"';
        $html .= ' placeholder="' . htmlspecialchars($placeholder) . '"';
        $html .= ' aria-label="Product search"';
        $html .= ' autocomplete="off"';
        $html .= '/>';
        $html .= '<button type="submit" class="product-search-button" aria-label="Search products">';
        $html .= '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>';
        $html .= '</button>';
        $html .= '</div>';

        $html .= '</div>';

        if ($options['show_popular'] ?? false) {
            $html .= $this->renderPopularSearches();
        }

        $html .= '</form>';
        $html .= '</div>';

        $this->logger->debug('Rendered product search bar');

        return $html;
    }

    private function renderPopularSearches(): string
    {
        $popularSearches = ['iPhone', 'Running Shoes', 'Laptop', 'Winter Jacket', 'Coffee Maker'];

        $html = '<div class="popular-searches">';
        $html .= '<span class="popular-label">Popular:</span>';
        $html .= '<ul class="popular-list">';

        foreach ($popularSearches as $search) {
            $html .= '<li><a href="/products/search?q=' . urlencode($search) . '" class="popular-link">' . htmlspecialchars($search) . '</a></li>';
        }

        $html .= '</ul>';
        $html .= '</div>';

        return $html;
    }

    public function renderProductSuggestionBox(array $products, string $query): string
    {
        if (empty($products)) {
            return '<div class="product-suggestion-box empty"><p>No product suggestions for "' . htmlspecialchars($query) . '"</p></div>';
        }

        $html = '<div class="product-suggestion-box" role="listbox" aria-label="Product suggestions">';

        foreach ($products as $product) {
            $html .= '<div class="product-suggestion-item" role="option">';
            $html .= '<a href="/products/' . $product['slug'] . '" class="suggestion-link">';
            $html .= '<img src="' . htmlspecialchars($product['thumbnail']) . '" alt="" class="suggestion-thumbnail" loading="lazy" />';
            $html .= '<div class="suggestion-details">';
            $html .= '<span class="suggestion-name">' . htmlspecialchars($product['name']) . '</span>';
            $html .= '<span class="suggestion-price">$' . number_format($product['price'], 2) . '</span>';
            $html .= '</div>';
            $html .= '</a>';
            $html .= '</div>';
        }

        $html .= '<div class="suggestion-footer">';
        $html .= '<a href="/products/search?q=' . urlencode($query) . '" class="see-all-products">See all results for "' . htmlspecialchars($query) . '"</a>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    public function renderSearchRefinementBar(array $facets, string $query): string
    {
        $html = '<div class="search-refinement-bar">';
        $html .= '<div class="refinement-section">';
        $html .= '<span class="refinement-label">Filter by:</span>';

        foreach ($facets as $facet) {
            $html .= '<div class="facet-group">';
            $html .= '<span class="facet-label">' . htmlspecialchars($facet['name']) . ':</span>';
            $html .= '<div class="facet-values">';

            foreach ($facet['values'] as $value) {
                $checked = $value['selected'] ? ' checked' : '';
                $html .= '<label class="facet-checkbox">';
                $html .= '<input type="checkbox" name="facet_' . $facet['key'] . '[]" value="' . $value['slug'] . '"' . $checked . ' />';
                $html .= '<span class="facet-value-label">' . htmlspecialchars($value['label']) . '</span>';
                $html .= '<span class="facet-count">(' . $value['count'] . ')</span>';
                $html .= '</label>';
            }

            $html .= '</div>';
            $html .= '</div>';
        }

        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }
}
