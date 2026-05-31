<?php

declare(strict_types=1);

namespace Health\Appointments\Availability;

use Doctrine\DBAL\Connection;
use Clinic\Schedule\BusinessHours;
use Clinic\Schedule\BlackoutCalendar;

final class AppointmentSlotGuard
{
    public function __construct(
        private Connection $db,
        private BusinessHours $hours,
        private BlackoutCalendar $blackouts,
    ) {
    }

    public function canBook(int $providerId, \DateTimeImmutable $start, \DateTimeImmutable $end): bool
    {
        if ($end <= $start) {
            return false;
        }

        if (!$this->hours->coversInterval($providerId, $start, $end)) {
            return false;
        }

        if ($this->blackouts->intersects($providerId, $start, $end)) {
            return false;
        }

        $conflictCount = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM appointments
             WHERE provider_id = :pid
               AND status <> :cancelled
               AND starts_at < :end
               AND ends_at   > :start',
            [
                'pid'       => $providerId,
                'cancelled' => 'cancelled',
                'end'       => $end->format('Y-m-d H:i:s'),
                'start'     => $start->format('Y-m-d H:i:s'),
            ],
        );

        return $conflictCount === 0;
    }
}
