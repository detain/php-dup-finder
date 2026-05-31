<?php

declare(strict_types=1);

namespace App\Status;

use DateTimeImmutable;
use DateTimeZone;

final class StatusPageWidget
{
    public function renderSupportWidget(): string
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $dow = (int) $now->format('N');
        $hour = (int) $now->format('G');

        $open = $dow >= 1 && $dow <= 5 && $hour >= 9 && $hour < 17;

        $html = '<div class="status-widget">';
        if ($open) {
            $closesAt = $now->setTime(17, 0)->format('H:i \U\T\C');
            $html .= '<span class="status-dot status-dot--green"></span>';
            $html .= '<strong>Support is online.</strong> ';
            $html .= sprintf('We are available until %s today.', $closesAt);
        } else {
            $html .= '<span class="status-dot status-dot--gray"></span>';
            $html .= '<strong>Support is offline.</strong> ';
            $html .= 'Business hours are Monday-Friday, 09:00-17:00 UTC. ';
            $html .= 'Tickets opened now will be handled the next business day.';
        }
        $html .= '</div>';

        $html .= '<table class="hours-table">';
        foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri'] as $day) {
            $html .= sprintf('<tr><th>%s</th><td>09:00 - 17:00 UTC</td></tr>', $day);
        }
        foreach (['Sat', 'Sun'] as $day) {
            $html .= sprintf('<tr><th>%s</th><td class="closed">Closed</td></tr>', $day);
        }
        $html .= '</table>';

        return $html;
    }
}
