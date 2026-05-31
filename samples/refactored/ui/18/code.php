<?php

declare(strict_types=1);

namespace App\View;

use Psr\Log\LoggerInterface;

final class UnifiedFilterRenderer
{
    /** @var array<string, callable(array, FilterContext): string> */
    private array $filterRenderers = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        $this->registerRenderers();
    }

    private function registerRenderers(): void
    {
        $this->filterRenderers['text'] = fn($filters, $context) => $this->renderTextFilter($filters);
        $this->filterRenderers['select'] = fn($filters, $context) => $this->renderSelectFilter($filters);
        $this->filterRenderers['multiselect'] = fn($filters, $context) => $this->renderMultiSelectFilter($filters);
        $this->filterRenderers['range'] = fn($filters, $context) => $this->renderRangeFilter($filters);
        $this->filterRenderers['date_range'] = fn($filters, $context) => $this->renderDateRangeFilter($filters);
    }

    public function render(array $filterConfig, array $activeFilters, FilterContext $context): string
    {
        $html = '<div class="unified-filter-bar">';
        $html .= '<form method="GET" class="filter-bar-form" action="' . htmlspecialchars($context->actionUrl) . '">';
        $html .= '<div class="filter-controls">';

        foreach ($filterConfig as $config) {
            $type = $config['type'] ?? 'text';
            $renderer = $this->filterRenderers[$type] ?? $this->filterRenderers['text'];
            $html .= $renderer($config, $context);
        }

        $html .= '</div>';
        $html .= '<div class="filter-actions">';
        $html .= '<button type="submit" class="filter-submit">Apply Filters</button>';
        $html .= '<a href="' . htmlspecialchars($context->resetUrl) . '" class="filter-reset">Reset</a>';
        $html .= '</div>';
        $html .= '</form>';
        $html .= '</div>';

        $this->logger->debug('Rendered unified filter bar');

        return $html;
    }

    private function renderTextFilter(array $config): string
    {
        $name = $config['name'];
        $value = $config['value'] ?? '';
        $placeholder = $config['placeholder'] ?? '';
        $label = $config['label'] ?? '';

        $html = '<div class="filter-control filter-text">';
        if ($label) {
            $html .= '<label class="filter-label">' . htmlspecialchars($label) . '</label>';
        }
        $html .= '<input type="text" name="' . $name . '" value="' . htmlspecialchars($value) . '" placeholder="' . htmlspecialchars($placeholder) . '" class="filter-input" />';
        $html .= '</div>';

        return $html;
    }

    private function renderSelectFilter(array $config): string
    {
        $name = $config['name'];
        $value = $config['value'] ?? '';
        $options = $config['options'] ?? [];
        $label = $config['label'] ?? '';

        $html = '<div class="filter-control filter-select">';
        if ($label) {
            $html .= '<label class="filter-label">' . htmlspecialchars($label) . '</label>';
        }
        $html .= '<select name="' . $name . '" class="filter-select-input">';
        $html .= '<option value="">Any ' . htmlspecialchars($label) . '</option>';

        foreach ($options as $option) {
            $selected = $option['value'] === $value ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($option['value']) . '"' . $selected . '>';
            $html .= htmlspecialchars($option['label']);
            $html .= '</option>';
        }

        $html .= '</select>';
        $html .= '</div>';

        return $html;
    }

    private function renderMultiSelectFilter(array $config): string
    {
        $name = $config['name'];
        $values = $config['values'] ?? [];
        $options = $config['options'] ?? [];
        $label = $config['label'] ?? '';

        $html = '<div class="filter-control filter-multiselect">';
        if ($label) {
            $html .= '<label class="filter-label">' . htmlspecialchars($label) . '</label>';
        }
        $html .= '<select name="' . $name . '[]" class="filter-multiselect-input" multiple>';

        foreach ($options as $option) {
            $selected = in_array($option['value'], $values, true) ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($option['value']) . '"' . $selected . '>';
            $html .= htmlspecialchars($option['label']);
            $html .= '</option>';
        }

        $html .= '</select>';
        $html .= '</div>';

        return $html;
    }

    private function renderRangeFilter(array $config): string
    {
        $name = $config['name'];
        $min = $config['min'] ?? '';
        $max = $config['max'] ?? '';
        $label = $config['label'] ?? '';

        $html = '<div class="filter-control filter-range">';
        if ($label) {
            $html .= '<label class="filter-label">' . htmlspecialchars($label) . '</label>';
        }
        $html .= '<div class="range-inputs">';
        $html .= '<input type="number" name="' . $name . '_min" value="' . htmlspecialchars($min) . '" placeholder="Min" class="range-min-input" />';
        $html .= '<span class="range-separator">-</span>';
        $html .= '<input type="number" name="' . $name . '_max" value="' . htmlspecialchars($max) . '" placeholder="Max" class="range-max-input" />';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    private function renderDateRangeFilter(array $config): string
    {
        $minName = $config['min_name'] ?? 'date_min';
        $maxName = $config['max_name'] ?? 'date_max';
        $min = $config['min'] ?? '';
        $max = $config['max'] ?? '';
        $label = $config['label'] ?? '';

        $html = '<div class="filter-control filter-date-range">';
        if ($label) {
            $html .= '<label class="filter-label">' . htmlspecialchars($label) . '</label>';
        }
        $html .= '<div class="date-range-inputs">';
        $html .= '<input type="date" name="' . $minName . '" value="' . htmlspecialchars($min) . '" class="date-input" />';
        $html .= '<span class="range-separator">to</span>';
        $html .= '<input type="date" name="' . $maxName . '" value="' . htmlspecialchars($max) . '" class="date-input" />';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }
}
