<?php

declare(strict_types=1);

namespace App\View\Picker;

use Psr\Log\LoggerInterface;

final class ReportPeriodPickerRenderer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function renderPeriodPicker(string $name, string $value, array $options = []): string
    {
        $id = $options['id'] ?? 'period_picker_' . uniqid();

        $html = '<div class="period-picker-component" id="' . $id . '">';
        $html .= '<div class="period-preset-buttons">';
        $html .= '<button type="button" class="period-preset" data-period="today">Today</button>';
        $html .= '<button type="button" class="period-preset" data-period="yesterday">Yesterday</button>';
        $html .= '<button type="button" class="period-preset" data-period="last_7_days">Last 7 Days</button>';
        $html .= '<button type="button" class="period-preset" data-period="last_30_days">Last 30 Days</button>';
        $html .= '<button type="button" class="period-preset" data-period="this_month">This Month</button>';
        $html .= '<button type="button" class="period-preset" data-period="last_month">Last Month</button>';
        $html .= '<button type="button" class="period-preset" data-period="custom">Custom</button>';
        $html .= '</div>';
        $html .= '<div class="period-custom-range" id="' . $id . '_custom">';
        $html .= '<div class="custom-range-inputs">';
        $html .= '<label for="' . $id . '_start">From:</label>';
        $html .= '<input type="date" id="' . $id . '_start" name="' . $name . '_start" class="period-date-input" />';
        $html .= '<label for="' . $id . '_end">To:</label>';
        $html .= '<input type="date" id="' . $id . '_end" name="' . $name . '_end" class="period-date-input" />';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars($value) . '" class="period-hidden-input" />';
        $html .= '</div>';

        return $html;
    }

    public function renderQuickSelectPeriod(string $name, string $value): string
    {
        $id = 'quick_period_' . uniqid();
        $presets = [
            'today' => 'Today',
            'yesterday' => 'Yesterday',
            'this_week' => 'This Week',
            'last_week' => 'Last Week',
            'this_month' => 'This Month',
            'last_month' => 'Last Month',
            'this_quarter' => 'This Quarter',
            'this_year' => 'This Year',
        ];

        $html = '<div class="quick-period-picker">';
        $html .= '<select id="' . $id . '" name="' . $name . '" class="quick-period-select">';

        foreach ($presets as $presetValue => $presetLabel) {
            $selected = $value === $presetValue ? ' selected' : '';
            $html .= '<option value="' . $presetValue . '"' . $selected . '>' . $presetLabel . '</option>';
        }

        $html .= '</select>';
        $html .= '</div>';

        return $html;
    }

    public function renderDateRangePicker(string $startName, string $endName, string $startValue, string $endValue): string
    {
        $id = 'range_picker_' . uniqid();

        $html = '<div class="date-range-picker-container" id="' . $id . '">';
        $html .= '<div class="range-input-group">';
        $html .= '<label for="' . $id . '_start">Start Date:</label>';
        $html .= '<input type="date" id="' . $id . '_start" name="' . $startName . '" value="' . htmlspecialchars($startValue) . '" class="range-start-date" />';
        $html .= '<span class="range-separator">—</span>';
        $html .= '<label for="' . $id . '_end">End Date:</label>';
        $html .= '<input type="date" id="' . $id . '_end" name="' . $endName . '" value="' . htmlspecialchars($endValue) . '" class="range-end-date" />';
        $html .= '</div>';
        $html .= '<div class="range-presets">';
        $html .= '<button type="button" class="range-preset-btn" data-range="7d">7 Days</button>';
        $html .= '<button type="button" class="range-preset-btn" data-range="30d">30 Days</button>';
        $html .= '<button type="button" class="range-preset-btn" data-range="90d">90 Days</button>';
        $html .= '<button type="button" class="range-preset-btn" data-range="ytd">YTD</button>';
        $html .= '<button type="button" class="range-preset-btn" data-range="1y">1 Year</button>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    public function renderFiscalYearPicker(string $name, string $value, string $fiscalYearStart = '10-01'): string
    {
        $currentYear = (int) date('Y');
        $html = '<div class="fiscal-year-picker">';
        $html .= '<select name="' . $name . '" class="fiscal-year-select">';

        for ($year = $currentYear; $year >= $currentYear - 5; $year--) {
            $fyStart = date_create($year . '-' . $fiscalYearStart);
            $fyEnd = date_create(($year + 1) . '-' . $fiscalYearStart)->modify('-1 day');
            $label = $fyStart->format('M Y') . ' - ' . $fyEnd->format('M Y');
            $selected = $value === (string) $year ? ' selected' : '';

            $html .= '<option value="' . $year . '"' . $selected . '>FY ' . $year . '</option>';
        }

        $html .= '</select>';
        $html .= '</div>';

        return $html;
    }
}
