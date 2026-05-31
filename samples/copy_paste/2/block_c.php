<?php
declare(strict_types=1);

namespace Acme\Reports;

final class CsvExporter
{
    public function exportRow(array $row, User $viewer): array
    {
        $eventUtc = $row['event_at'] instanceof \DateTimeImmutable
            ? $row['event_at']
            : new \DateTimeImmutable((string) $row['event_at']);

        // ---- BEGIN copy-pasted timezone conversion ----
        $tzName = $viewer->timezone() ?: 'UTC';
        try {
            $targetTz = new \DateTimeZone($tzName);
        } catch (\Exception $e) {
            $targetTz = new \DateTimeZone('UTC');
        }
        $local = $eventUtc->setTimezone($targetTz);
        $iso = $local->format(\DateTimeInterface::ATOM);
        $human = $local->format('M j, Y \a\t g:i A T');
        $epoch = $local->getTimestamp();
        $offsetSeconds = $targetTz->getOffset($local);
        $offsetLabel = sprintf('%+03d:%02d', intdiv($offsetSeconds, 3600), abs($offsetSeconds % 3600) / 60);
        // ---- END copy-pasted timezone conversion ----

        return [
            'id' => $row['id'],
            'event_iso' => $iso,
            'event_display' => $human,
            'event_epoch' => $epoch,
            'offset' => $offsetLabel,
        ];
    }
}
