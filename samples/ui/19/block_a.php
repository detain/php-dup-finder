<?php

declare(strict_types=1);

namespace App\View\Picker;

use Psr\Log\LoggerInterface;

final class AppointmentPickerRenderer
{
    private const DAYS_TO_SHOW = 30;
    private const SLOTS_PER_DAY = 8;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function renderDatePicker(string $name, string $value, array $options = []): string
    {
        $id = $options['id'] ?? 'datepicker_' . uniqid();
        $minDate = $options['min_date'] ?? date('Y-m-d');
        $maxDate = $options['max_date'] ?? date('Y-m-d', strtotime('+' . self::DAYS_TO_SHOW . ' days'));
        $disabled = $options['disabled'] ?? false;
        $required = $options['required'] ?? false;

        $html = '<div class="date-picker-container appointment-date-picker">';
        $html .= '<input type="text"';
        $html .= ' id="' . $id . '"';
        $html .= ' name="' . $name . '"';
        $html .= ' value="' . htmlspecialchars($value) . '"';
        $html .= ' class="date-picker-input"';
        $html .= ' placeholder="Select appointment date"';
        $html .= ' data-min-date="' . htmlspecialchars($minDate) . '"';
        $html .= ' data-max-date="' . htmlspecialchars($maxDate) . '"';
        $html .= ' data-picker-type="date"';
        if ($disabled) {
            $html .= ' disabled';
        }
        if ($required) {
            $html .= ' required';
        }
        $html .= ' autocomplete="off"';
        $html .= '/>';
        $html .= '<div class="date-picker-calendar" id="' . $id . '_calendar" hidden></div>';
        $html .= '</div>';

        return $html;
    }

    public function renderTimePicker(string $name, string $value, array $options = []): string
    {
        $id = $options['id'] ?? 'timepicker_' . uniqid();
        $interval = $options['interval'] ?? 30;
        $startHour = $options['start_hour'] ?? 9;
        $endHour = $options['end_hour'] ?? 17;
        $disabled = $options['disabled'] ?? false;

        $html = '<div class="time-picker-container appointment-time-picker">';
        $html .= '<input type="text"';
        $html .= ' id="' . $id . '"';
        $html .= ' name="' . $name . '"';
        $html .= ' value="' . htmlspecialchars($value) . '"';
        $html .= ' class="time-picker-input"';
        $html .= ' placeholder="Select time"';
        $html .= ' data-interval="' . $interval . '"';
        $html .= ' data-start-hour="' . $startHour . '"';
        $html .= ' data-end-hour="' . $endHour . '"';
        if ($disabled) {
            $html .= ' disabled';
        }
        $html .= '/>';
        $html .= '<div class="time-picker-dropdown" id="' . $id . '_dropdown" hidden>';
        $html .= $this->renderTimeSlots($interval, $startHour, $endHour);
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    public function renderDateTimePicker(string $name, string $value, array $options = []): string
    {
        $id = $options['id'] ?? 'datetimepicker_' . uniqid();
        $minDate = $options['min_date'] ?? date('Y-m-d');
        $maxDate = $options['max_date'] ?? date('Y-m-d', strtotime('+' . self::DAYS_TO_SHOW . ' days'));
        $interval = $options['interval'] ?? 30;
        $startHour = $options['start_hour'] ?? 9;
        $endHour = $options['end_hour'] ?? 17;

        $html = '<div class="datetime-picker-container appointment-datetime-picker">';
        $html .= '<div class="datetime-input-group">';
        $html .= '<input type="text"';
        $html .= ' id="' . $id . '"';
        $html .= ' name="' . $name . '"';
        $html .= ' value="' . htmlspecialchars($value) . '"';
        $html .= ' class="datetime-picker-input"';
        $html .= ' placeholder="Select date and time"';
        $html .= ' data-min-date="' . htmlspecialchars($minDate) . '"';
        $html .= ' data-max-date="' . htmlspecialchars($maxDate) . '"';
        $html .= ' data-interval="' . $interval . '"';
        $html .= ' data-start-hour="' . $startHour . '"';
        $html .= ' data-end-hour="' . $endHour . '"';
        $html .= '/>';
        $html .= '<button type="button" class="datetime-picker-trigger" aria-label="Open calendar">📅</button>';
        $html .= '</div>';
        $html .= '<div class="datetime-picker-panel" id="' . $id . '_panel" hidden>';
        $html .= '<div class="datetime-calendar-section"></div>';
        $html .= '<div class="datetime-time-section"></div>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    private function renderTimeSlots(int $interval, int $startHour, int $endHour): string
    {
        $html = '<div class="time-slots-grid">';

        for ($hour = $startHour; $hour <= $endHour; $hour++) {
            for ($minute = 0; $minute < 60; $minute += $interval) {
                $time = sprintf('%02d:%02d', $hour, $minute);
                $html .= '<button type="button" class="time-slot" data-time="' . $time . '">' . $time . '</button>';
            }
        }

        $html .= '</div>';
        return $html;
    }

    public function renderAvailabilityCalendar(array $availability, string $selectedDate): string
    {
        $html = '<div class="availability-calendar">';
        $html .= '<table class="availability-table">';
        $html .= '<thead><tr><th>Date</th><th>Available Slots</th><th>Select</th></tr></thead>';
        $html .= '<tbody>';

        foreach ($availability as $date => $slots) {
            $isSelected = $date === $selectedDate;
            $hasSlots = count($slots) > 0;
            $rowClass = $isSelected ? ' selected' : (!$hasSlots ? ' no-slots' : '');

            $html .= '<tr class="availability-row' . $rowClass . '">';
            $html .= '<td class="date-cell">' . htmlspecialchars($date) . '</td>';
            $html .= '<td class="slots-cell">' . ($hasSlots ? count($slots) . ' slots available' : 'No availability') . '</td>';
            $html .= '<td class="select-cell">';
            if ($hasSlots) {
                $html .= '<input type="radio" name="availability_date" value="' . htmlspecialchars($date) . '"' . ($isSelected ? ' checked' : '') . ' />';
            }
            $html .= '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $html .= '</div>';

        return $html;
    }
}
