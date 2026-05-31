<?php

declare(strict_types=1);

namespace App\View\Picker;

use Psr\Log\LoggerInterface;

final class EventCalendarPickerRenderer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function renderEventDatePicker(string $name, string $value, array $options = []): string
    {
        $id = $options['id'] ?? 'event_date_picker';
        $minDate = $options['min_date'] ?? date('Y-m-d');
        $allowRecurring = $options['allow_recurring'] ?? false;

        $html = '<div class="event-date-picker-wrapper">';
        $html .= '<input type="text"';
        $html .= ' id="' . $id . '"';
        $html .= ' name="' . $name . '"';
        $html .= ' value="' . htmlspecialchars($value) . '"';
        $html .= ' class="event-date-input"';
        $html .= ' data-min-date="' . htmlspecialchars($minDate) . '"';
        $html .= '/>';
        $html .= '<div class="event-calendar-popup" id="' . $id . '_popup" hidden>';
        $html .= $this->renderMiniCalendar();
        $html .= '</div>';
        $html .= '</div>';

        if ($allowRecurring) {
            $html .= $this->renderRecurringOptions($id);
        }

        return $html;
    }

    public function renderRecurringOptions(string $pickerId): string
    {
        $html = '<div class="recurring-options" id="' . $pickerId . '_recurring">';
        $html .= '<label class="recurring-label">Repeats:</label>';
        $html .= '<select name="recurring_frequency" class="recurring-frequency">';
        $html .= '<option value="none">Does not repeat</option>';
        $html .= '<option value="daily">Daily</option>';
        $html .= '<option value="weekly">Weekly</option>';
        $html .= '<option value="monthly">Monthly</option>';
        $html .= '<option value="yearly">Yearly</option>';
        $html .= '</select>';
        $html .= '<div class="recurring-end-options">';
        $html .= '<label class="recurring-end-label">End:</label>';
        $html .= '<select name="recurring_end_type" class="recurring-end-type">';
        $html .= '<option value="never">Never</option>';
        $html .= '<option value="after">After</option>';
        $html .= '<option value="on">On</option>';
        $html .= '</select>';
        $html .= '<input type="number" name="recurring_count" value="10" class="recurring-count" min="1" max="100" />';
        $html .= '<input type="date" name="recurring_end_date" class="recurring-end-date" />';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    public function renderEventTimePicker(string $name, string $value, array $options = []): string
    {
        $id = $options['id'] ?? 'event_time_picker';
        $startHour = $options['start_hour'] ?? 0;
        $endHour = $options['end_hour'] ?? 23;
        $duration = $options['duration'] ?? 60;

        $html = '<div class="event-time-picker-wrapper">';
        $html .= '<div class="time-picker-row">';
        $html .= '<div class="time-input-group">';
        $html .= '<label for="' . $id . '_start">Start Time:</label>';
        $html .= '<input type="time" id="' . $id . '_start" name="' . $name . '_start" value="' . htmlspecialchars($value) . '" class="time-input-start" />';
        $html .= '</div>';
        $html .= '<div class="time-input-group">';
        $html .= '<label for="' . $id . '_end">End Time:</label>';
        $html .= '<input type="time" id="' . $id . '_end" name="' . $name . '_end" class="time-input-end" />';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<div class="duration-presets">';
        $html .= '<span class="duration-label">Duration:</span>';
        $html .= '<button type="button" class="duration-preset" data-duration="15">15m</button>';
        $html .= '<button type="button" class="duration-preset" data-duration="30">30m</button>';
        $html .= '<button type="button" class="duration-preset" data-duration="60" data-selected="true">1h</button>';
        $html .= '<button type="button" class="duration-preset" data-duration="120">2h</button>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    private function renderMiniCalendar(): string
    {
        $html = '<div class="mini-calendar">';
        $html .= '<div class="mini-calendar-header">';
        $html .= '<button type="button" class="cal-nav-btn prev-month">‹</button>';
        $html .= '<span class="cal-current-month">May 2024</span>';
        $html .= '<button type="button" class="cal-nav-btn next-month">›</button>';
        $html .= '</div>';
        $html .= '<table class="mini-calendar-grid">';
        $html .= '<thead><tr><th>Su</th><th>Mo</th><th>Tu</th><th>We</th><th>Th</th><th>Fr</th><th>Sa</th></tr></thead>';
        $html .= '<tbody>';
        $html .= '<tr><td class="cal-day disabled">28</td><td class="cal-day disabled">29</td><td class="cal-day disabled">30</td><td class="cal-day disabled">1</td><td class="cal-day">2</td><td class="cal-day">3</td><td class="cal-day">4</td></tr>';
        $html .= '<tr><td class="cal-day">5</td><td class="cal-day">6</td><td class="cal-day">7</td><td class="cal-day">8</td><td class="cal-day">9</td><td class="cal-day">10</td><td class="cal-day">11</td></tr>';
        $html .= '<tr><td class="cal-day">12</td><td class="cal-day">13</td><td class="cal-day">14</td><td class="cal-day today" data-selected="true">15</td><td class="cal-day">16</td><td class="cal-day">17</td><td class="cal-day">18</td></tr>';
        $html .= '</tbody>';
        $html .= '</table>';
        $html .= '</div>';

        return $html;
    }

    public function renderTimezoneSelector(string $name, string $value): string
    {
        $timezones = [
            'America/New_York' => 'Eastern Time (ET)',
            'America/Chicago' => 'Central Time (CT)',
            'America/Denver' => 'Mountain Time (MT)',
            'America/Los_Angeles' => 'Pacific Time (PT)',
            'Europe/London' => 'Greenwich Mean Time (GMT)',
            'Europe/Paris' => 'Central European Time (CET)',
            'Asia/Tokyo' => 'Japan Standard Time (JST)',
        ];

        $html = '<div class="timezone-selector">';
        $html .= '<label for="timezone_select">Timezone:</label>';
        $html .= '<select id="timezone_select" name="' . $name . '" class="timezone-select">';

        foreach ($timezones as $tz => $label) {
            $selected = $value === $tz ? ' selected' : '';
            $html .= '<option value="' . $tz . '"' . $selected . '>' . $label . '</option>';
        }

        $html .= '</select>';
        $html .= '<span class="timezone-current">Current: ' . date('T') . ' (' . date('H:i') . ')</span>';
        $html .= '</div>';

        return $html;
    }
}
