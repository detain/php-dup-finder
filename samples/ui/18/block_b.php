<?php

declare(strict_types=1);

namespace App\View\Filter;

use App\Entity\FilterOption;
use Psr\Log\LoggerInterface;

final class UserFilterRenderer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function renderFilterBar(array $filters, FilterContext $context): string
    {
        $html = '<div class="filter-bar user-filter-bar">';
        $html .= '<form method="GET" class="filter-form" action="' . htmlspecialchars($context->actionUrl) . '">';
        $html .= '<div class="filter-controls-grid">';

        $html .= $this->renderTextFilter(
            'query',
            $filters['query'] ?? '',
            'Search by name or email...',
            'filter-search'
        );

        $html .= $this->renderSelectFilter(
            'role',
            $filters['role'] ?? '',
            $this->getRoleOptions(),
            'Role'
        );

        $html .= $this->renderSelectFilter(
            'account_status',
            $filters['account_status'] ?? '',
            $this->getAccountStatusOptions(),
            'Account Status'
        );

        $html .= $this->renderDateRangeFilter(
            'created_after',
            'created_before',
            $filters['created_after'] ?? '',
            $filters['created_before'] ?? '',
            'Created Between'
        );

        $html .= $this->renderSelectFilter(
            'verified',
            $filters['verified'] ?? '',
            $this->getVerifiedOptions(),
            'Verification'
        );

        $html .= '</div>';
        $html .= '<div class="filter-button-row">';
        $html .= '<button type="submit" class="btn btn-apply">Apply</button>';
        $html .= '<button type="button" class="btn btn-clear">Clear</button>';
        $html .= '</div>';
        $html .= '</form>';
        $html .= '</div>';

        $this->logger->debug('Rendered user filter bar');

        return $html;
    }

    private function renderTextFilter(string $name, string $value, string $placeholder, string $class): string
    {
        $html = '<div class="filter-control ' . $class . '">';
        $html .= '<input type="text"';
        $html .= ' name="' . $name . '"';
        $html .= ' value="' . htmlspecialchars($value) . '"';
        $html .= ' placeholder="' . htmlspecialchars($placeholder) . '"';
        $html .= ' class="filter-text-input"';
        $html .= '/>';
        $html .= '</div>';

        return $html;
    }

    private function renderSelectFilter(string $name, string $value, array $options, string $label): string
    {
        $html = '<div class="filter-control filter-dropdown">';
        $html .= '<label class="filter-control-label">' . htmlspecialchars($label) . '</label>';
        $html .= '<select name="' . $name . '" class="filter-dropdown-select">';
        $html .= '<option value="">Any ' . htmlspecialchars($label) . '</option>';

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

    private function renderDateRangeFilter(string $minName, string $maxName, string $minVal, string $maxVal, string $label): string
    {
        $html = '<div class="filter-control filter-date-range">';
        $html .= '<label class="filter-control-label">' . htmlspecialchars($label) . '</label>';
        $html .= '<div class="date-range-inputs">';
        $html .= '<input type="date" name="' . $minName . '" value="' . htmlspecialchars($minVal) . '" class="date-input date-from" />';
        $html .= '<span class="date-range-separator">to</span>';
        $html .= '<input type="date" name="' . $maxName . '" value="' . htmlspecialchars($maxVal) . '" class="date-input date-to" />';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    private function getRoleOptions(): array
    {
        return [
            new FilterOption('admin', 'Administrator'),
            new FilterOption('editor', 'Editor'),
            new FilterOption('author', 'Author'),
            new FilterOption('viewer', 'Viewer'),
            new FilterOption('guest', 'Guest'),
        ];
    }

    private function getAccountStatusOptions(): array
    {
        return [
            new FilterOption('active', 'Active'),
            new FilterOption('inactive', 'Inactive'),
            new FilterOption('suspended', 'Suspended'),
            new FilterOption('pending', 'Pending'),
        ];
    }

    private function getVerifiedOptions(): array
    {
        return [
            new FilterOption('verified', 'Verified'),
            new FilterOption('unverified', 'Unverified'),
        ];
    }

    public function renderActiveFilters(array $activeFilters): string
    {
        if (empty($activeFilters)) {
            return '';
        }

        $html = '<div class="active-user-filters">';
        $html .= '<div class="active-filter-chips">';

        foreach ($activeFilters as $key => $value) {
            $html .= '<span class="filter-chip">';
            $html .= '<span class="chip-key">' . htmlspecialchars(ucfirst(str_replace('_', ' ', $key))) . ':</span>';
            $html .= '<span class="chip-value">' . htmlspecialchars($value) . '</span>';
            $html .= '<button type="button" class="chip-remove" aria-label="Remove filter">×</button>';
            $html .= '</span>';
        }

        $html .= '</div>';
        $html .= '<button type="button" class="clear-all-filters">Clear All</button>';
        $html .= '</div>';

        return $html;
    }
}
