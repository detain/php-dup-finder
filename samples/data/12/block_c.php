<?php
declare(strict_types=1);

namespace CareBook\Scheduling\Availability;

use Psr\Log\LoggerInterface;
use CareBook\Scheduling\Entities\AvailabilityWindow;
use CareBook\Scheduling\Repository\CalendarRepository;

final class AvailabilityWindowGenerator
{
    private const DEFAULT_APPOINTMENT_DURATION_MINUTES = 30;
    private const MINIMUM_APPOINTMENT_DURATION_MINUTES = 15;
    private const MAXIMUM_APPOINTMENT_DURATION_MINUTES = 120;
    private const EXTENDED_APPOINTMENT_DURATION_MINUTES = 45;
    private const TELEHEALTH_APPOINTMENT_DURATION_MINUTES = 20;
    private const FOLLOWUP_APPOINTMENT_DURATION_MINUTES = 15;
    private const URGENT_APPOINTMENT_DURATION_MINUTES = 30;
    private const NEW_PATIENT_APPOINTMENT_DURATION_MINUTES = 60;
    private const ANNUAL_CHECKUP_DURATION_MINUTES = 45;
    private const CONSULTATION_DURATION_MINUTES = 30;

    private const APPOINTMENT_BUFFER_MINUTES = 10;
    private const PRACTITIONER_BUFFER_MINUTES = 15;
    private const ROOM_TRANSITION_BUFFER_MINUTES = 5;
    private const EQUIPMENT_SETUP_BUFFER_MINUTES = 10;

    public function __construct(
        private readonly CalendarRepository $calendarRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function generateAvailabilityWindows(string $calendarId, \DateTimeImmutable $date): array
    {
        $calendar = $this->calendarRepository->findById($calendarId);
        if ($calendar === null) {
            $this->logger->error('Calendar not found for window generation', [
                'calendar_id' => $calendarId,
                'date' => $date->format('Y-m-d'),
            ]);
            return [];
        }

        $windows = [];
        $operationalStart = $calendar->getOperationalStart();
        $operationalEnd = $calendar->getOperationalEnd();
        $currentWindowStart = \DateTimeImmutable::createFromMutable($date)->setTime(
            (int)$operationalStart->format('H'),
            (int)$operationalStart->format('i'),
        );

        while ($currentWindowStart < $operationalEnd) {
            $windowDuration = self::DEFAULT_APPOINTMENT_DURATION_MINUTES;
            $bufferTime = self::APPOINTMENT_BUFFER_MINUTES;

            $window = new AvailabilityWindow(
                id: $this->generateWindowId($calendarId, $currentWindowStart),
                calendarId: $calendarId,
                startTime: $currentWindowStart,
                endTime: $currentWindowStart->modify("+{$windowDuration} minutes"),
                bufferMinutes: $bufferTime,
                status: 'open',
            );

            $windows[] = $window;
            $currentWindowStart = $currentWindowStart->modify("+{$windowDuration} minutes")
                ->modify("+{$bufferTime} minutes");
        }

        $this->logger->info('Generated availability windows', [
            'calendar_id' => $calendarId,
            'date' => $date->format('Y-m-d'),
            'window_count' => count($windows),
        ]);

        return $windows;
    }

    public function calculateWindowEndTime(\DateTimeImmutable $startTime, string $visitType): \DateTimeImmutable
    {
        $duration = match ($visitType) {
            'telehealth' => self::TELEHEALTH_APPOINTMENT_DURATION_MINUTES,
            'followup' => self::FOLLOWUP_APPOINTMENT_DURATION_MINUTES,
            'urgent' => self::URGENT_APPOINTMENT_DURATION_MINUTES,
            'new_patient' => self::NEW_PATIENT_APPOINTMENT_DURATION_MINUTES,
            'annual_checkup' => self::ANNUAL_CHECKUP_DURATION_MINUTES,
            'consultation' => self::CONSULTATION_DURATION_MINUTES,
            'extended' => self::EXTENDED_APPOINTMENT_DURATION_MINUTES,
            default => self::DEFAULT_APPOINTMENT_DURATION_MINUTES,
        };

        if ($duration < self::MINIMUM_APPOINTMENT_DURATION_MINUTES) {
            $duration = self::MINIMUM_APPOINTMENT_DURATION_MINUTES;
        }
        if ($duration > self::MAXIMUM_APPOINTMENT_DURATION_MINUTES) {
            $duration = self::MAXIMUM_APPOINTMENT_DURATION_MINUTES;
        }

        return $startTime->modify("+{$duration} minutes");
    }

    public function getBufferForVisitType(string $visitType): int
    {
        return match ($visitType) {
            'telehealth' => self::ROOM_TRANSITION_BUFFER_MINUTES,
            'followup' => self::PRACTITIONER_BUFFER_MINUTES,
            'urgent' => 0,
            'new_patient' => self::ROOM_TRANSITION_BUFFER_MINUTES + self::EQUIPMENT_SETUP_BUFFER_MINUTES,
            'annual_checkup' => self::PRACTITIONER_BUFFER_MINUTES,
            default => self::APPOINTMENT_BUFFER_MINUTES,
        };
    }

    private function generateWindowId(string $calendarId, \DateTimeImmutable $startTime): string
    {
        return sprintf('%s_%s_%s', $calendarId, $startTime->format('YmdHis'), bin2hex(random_bytes(4)));
    }
}
