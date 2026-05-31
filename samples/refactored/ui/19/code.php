<?php

declare(strict_types=1);

namespace App\View;

use Psr\Log\LoggerInterface;

final class UnifiedDateTimePicker
{
    /** @var array<string, PickerConfig> */
    private array $pickerConfigs = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        $this->initializeConfigs();
    }

    private function initializeConfigs(): void
    {
        $this->pickerConfigs['date'] = new PickerConfig(
            type: 'date',
            defaultFormat: 'Y-m-d',
            placeholder: 'Select date',
        );

        $this->pickerConfigs['time'] = new PickerConfig(
            type: 'time',
            defaultFormat: 'H:i',
            placeholder: 'Select time',
        );

        $this->pickerConfigs['datetime'] = new PickerConfig(
            type: 'datetime',
            defaultFormat: 'Y-m-d H:i',
            placeholder: 'Select date and time',
        );

        $this->pickerConfigs['daterange'] = new PickerConfig(
            type: 'daterange',
            defaultFormat: 'Y-m-d',
            placeholder: 'Select date range',
        );

        $this->pickerConfigs['period'] = new PickerConfig(
            type: 'period',
            defaultFormat: 'period',
            placeholder: 'Select period',
        );
    }

    public function render(string $type, string $name, string $value, array $options = []): string
    {
        $config = $this->pickerConfigs[$type] ?? $this->pickerConfigs['date'];
        $id = $options['id'] ?? $type . '_picker_' . uniqid();

        $html = '<div class="unified-picker picker-type-' . $type . '" id="' . $id . '">';
        $html .= '<input type="text"';
        $html .= ' name="' . $name . '"';
        $html .= ' value="' . htmlspecialchars($value) . '"';
        $html .= ' class="picker-input"';
        $html .= ' placeholder="' . $config->placeholder . '"';
        $html .= ' data-picker-type="' . $type . '"';
        $html .= $this->buildDataAttributes($options);
        $html .= ' />';

        if ($type === 'daterange' || $type === 'period') {
            $html .= $this->renderPresetButtons($type);
        }

        $html .= $this->renderPickerPanel($type, $id, $options);
        $html .= '</div>';

        $this->logger->debug('Rendered unified picker', ['type' => $type, 'id' => $id]);

        return $html;
    }

    private function renderPresetButtons(string $type): string
    {
        $presets = $this->getPresetsForType($type);

        $html = '<div class="picker-presets">';
        foreach ($presets as $preset) {
            $html .= '<button type="button" class="picker-preset-btn" data-preset="' . $preset['value'] . '">';
            $html .= htmlspecialchars($preset['label']);
            $html .= '</button>';
        }
        $html .= '</div>';

        return $html;
    }

    private function renderPickerPanel(string $type, string $id, array $options): string
    {
        $html = '<div class="picker-panel" id="' . $id . '_panel" hidden>';

        if ($type === 'date' || $type === 'datetime') {
            $html .= $this->renderCalendarPanel($options);
        }

        if ($type === 'time' || $type === 'datetime') {
            $html .= $this->renderTimePanel($options);
        }

        if ($type === 'daterange') {
            $html .= $this->renderDateRangePanel($options);
        }

        $html .= '</div>';
        return $html;
    }

    private function renderCalendarPanel(array $options): string
    {
        $html = '<div class="picker-calendar-panel">';
        $html .= '<div class="calendar-nav">';
        $html .= '<button type="button" class="cal-nav-prev">‹</button>';
        $html .= '<span class="calendar-month-year">May 2024</span>';
        $html .= '<button type="button" class="cal-nav-next">›</button>';
        $html .= '</div>';
        $html .= '<table class="calendar-grid"><thead><tr>';
        foreach (['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'] as $day) {
            $html .= '<th>' . $day . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        $html .= '<tr><td class="cal-day">1</td><td class="cal-day">2</td><td class="cal-day">3</td><td class="cal-day">4</td><td class="cal-day">5</td><td class="cal-day">6</td><td class="cal-day">7</td></tr>';
        $html .= '<tr><td class="cal-day">8</td><td class="cal-day">9</td><td class="cal-day">10</td><td class="cal-day selected">11</td><td class="cal-day">12</td><td class="cal-day">13</td><td class="cal-day">14</td></tr>';
        $html .= '</tbody></table>';
        $html .= '</div>';

        return $html;
    }

    private function renderTimePanel(array $options): string
    {
        $interval = $options['interval'] ?? 30;
        $startHour = $options['start_hour'] ?? 0;
        $endHour = $options['end_hour'] ?? 23;

        $html = '<div class="picker-time-panel">';
        $html .= '<div class="time-slots">';

        for ($hour = $startHour; $hour <= $endHour; $hour++) {
            for ($minute = 0; $minute < 60; $minute += $interval) {
                $time = sprintf('%02d:%02d', $hour, $minute);
                $html .= '<button type="button" class="time-slot-btn" data-time="' . $time . '">' . $time . '</button>';
            }
        }

        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    private function renderDateRangePanel(array $options): string
    {
        $html = '<div class="picker-daterange-panel">';
        $html .= '<div class="range-calendar range-calendar-start"></div>';
        $html .= '<div class="range-calendar range-calendar-end"></div>';
        $html .= '</div>';

        return $html;
    }

    private function getPresetsForType(string $type): array
    {
        return match ($type) {
            'daterange' => [
                ['value' => '7d', 'label' => 'Last 7 Days'],
                ['value' => '30d', 'label' => 'Last 30 Days'],
                ['value' => '90d', 'label' => 'Last 90 Days'],
                ['value' => 'ytd', 'label' => 'Year to Date'],
            ],
            'period' => [
                ['value' => 'today', 'label' => 'Today'],
                ['value' => 'this_week', 'label' => 'This Week'],
                ['value' => 'this_month', 'label' => 'This Month'],
                ['value' => 'this_quarter', 'label' => 'This Quarter'],
                ['value' => 'this_year', 'label' => 'This Year'],
            ],
            default => [],
        };
    }

    private function buildDataAttributes(array $options): string
    {
        $attrs = '';
        $dataOptions = ['min_date', 'max_date', 'interval', 'start_hour', 'end_hour'];

        foreach ($dataOptions as $opt) {
            if (isset($options[$opt])) {
                $attrs .= ' data-' . str_replace('_', '-', $opt) . '="' . htmlspecialchars((string) $options[$opt]) . '"';
            }
        }

        return $attrs;
    }
}

final class PickerConfig
{
    public function __construct(
        public readonly string $type,
        public readonly string $defaultFormat,
        public readonly string $placeholder,
    ) {}
}
