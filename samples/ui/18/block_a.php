<?php

declare(strict_types=1);

namespace App\View\Filter;

use App\Entity\FilterOption;
use Psr\Log\LoggerInterface;

final class ProductFilterRenderer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function renderFilterBar(array $filters, FilterContext $context): string
    {
        $html = '<div class="filter-bar product-filter-bar">';
        $html .= '<form method="GET" class="filter-form" action="' . htmlspecialchars($context->actionUrl) . '">';
        $html .= '<div class="filter-row">';

        $html .= $this->renderTextFilter(
            'search',
            $filters['search'] ?? '',
            'Search products...',
            'filter-search'
        );

        $html .= $this->renderSelectFilter(
            'category',
            $filters['category'] ?? '',
            $this->getCategoryOptions(),
            'Category'
        );

        $html .= $this->renderSelectFilter(
            'status',
            $filters['status'] ?? '',
            $this->getStatusOptions(),
            'Status'
        );

        $html .= $this->renderRangeFilter(
            'price',
            $filters['price_min'] ?? '',
            $filters['price_max'] ?? '',
            'Price Range'
        );

        $html .= '</div>';
        $html .= '<div class="filter-actions">';
        $html .= '<button type="submit" class="filter-btn filter-apply">Apply Filters</button>';
        $html .= '<a href="' . htmlspecialchars($context->resetUrl) . '" class="filter-btn filter-reset">Reset</a>';
        $html .= '</div>';
        $html .= '</form>';
        $html .= '</div>';

        $this->logger->debug('Rendered product filter bar');

        return $html;
    }

    private function renderTextFilter(string $name, string $value, string $placeholder, string $class): string
    {
        $html = '<div class="filter-field filter-text ' . $class . '">';
        $html .= '<input type="text"';
        $html .= ' name="' . $name . '"';
        $html .= ' value="' . htmlspecialchars($value) . '"';
        $html .= ' placeholder="' . htmlspecialchars($placeholder) . '"';
        $html .= ' class="filter-input"';
        $html .= '/>';
        $html .= '</div>';

        return $html;
    }

    private function renderSelectFilter(string $name, string $value, array $options, string $label): string
    {
        $html = '<div class="filter-field filter-select">';
        $html .= '<label class="filter-label">' . htmlspecialchars($label) . '</label>';
        $html .= '<select name="' . $name . '" class="filter-select-input">';
        $html .= '<option value="">All ' . htmlspecialchars($label) . 's</option>';

        foreach ($options as $option) {
            $selected = $option->value === $value ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($option->value) . '"' . $selected . '>';
            $html .= htmlspecialchars($option->label);
            $html .= '</option>';
        }

        $html .= '</select>';
        $html .= '</div>';

        return $html;
    }

    private function renderRangeFilter(string $name, string $min, string $max, string $label): string
    {
        $html = '<div class="filter-field filter-range">';
        $html .= '<label class="filter-label">' . htmlspecialchars($label) . '</label>';
        $html .= '<div class="range-inputs">';
        $html .= '<input type="number" name="' . $name . '_min" value="' . htmlspecialchars($min) . '" placeholder="Min" class="range-input range-min" />';
        $html .= '<span class="range-separator">-</span>';
        $html .= '<input type="number" name="' . $name . '_max" value="' . htmlspecialchars($max) . '" placeholder="Max" class="range-input range-max" />';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    private function getCategoryOptions(): array
    {
        return [
            new FilterOption('electronics', 'Electronics'),
            new FilterOption('clothing', 'Clothing'),
            new FilterOption('home', 'Home & Garden'),
            new FilterOption('sports', 'Sports'),
            new FilterOption('books', 'Books'),
        ];
    }

    private function getStatusOptions(): array
    {
        return [
            new FilterOption('active', 'Active'),
            new FilterOption('inactive', 'Inactive'),
            new FilterOption('discontinued', 'Discontinued'),
        ];
    }

    public function renderActiveFilters(array $activeFilters): string
    {
        if (empty($activeFilters)) {
            return '';
        }

        $html = '<div class="active-filters">';
        $html .= '<span class="active-filters-label">Active filters:</span>';
        $html .= '<ul class="active-filter-list">';

        foreach ($activeFilters as $key => $value) {
            $html .= '<li class="active-filter-item">';
            $html .= '<span class="filter-name">' . htmlspecialchars(ucfirst($key)) . ':</span>';
            $html .= '<span class="filter-value">' . htmlspecialchars($value) . '</span>';
            $html .= '<a href="#" class="filter-remove" data-filter="' . htmlspecialchars($key) . '">×</a>';
            $html .= '</li>';
        }

        $html .= '</ul>';
        $html .= '</div>';

        return $html;
    }
}
