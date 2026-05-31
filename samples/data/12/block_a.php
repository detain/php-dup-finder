<?php
declare(strict_types=1);

namespace ClinicFlow\Appointment\Scheduler;

use Psr\Log\LoggerInterface;
use ClinicFlow\Appointment\Entities\AppointmentSlot;
use ClinicFlow\Clinic\Repository\LocationRepository;

final class AppointmentSlotGenerator
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
        private readonly LocationRepository $locationRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function generateSlotsForDate(\DateTimeImmutable $date, string $locationId): array
    {
        $location = $this->locationRepository->findById($locationId);
        if ($location === null) {
            $this->logger->error('Location not found for slot generation', [
                'location_id' => $locationId,
                'date' => $date->format('Y-m-d'),
            ]);
            return [];
        }

        $slots = [];
        $startTime = $location->getStartTime();
        $endTime = $location->getEndTime();
        $currentSlotStart = \DateTimeImmutable::createFromMutable($date)->setTime(
            (int)$startTime->format('H'),
            (int)$startTime->format('i'),
        );

        while ($currentSlotStart < $endTime) {
            $slotDuration = self::DEFAULT_APPOINTMENT_DURATION_MINUTES;
            $bufferTime = self::APPOINTMENT_BUFFER_MINUTES;

            $slot = new AppointmentSlot(
                id: $this->generateSlotId($locationId, $currentSlotStart),
                locationId: $locationId,
                startTime: $currentSlotStart,
                endTime: $currentSlotStart->modify("+{$slotDuration} minutes"),
                bufferMinutes: $bufferTime,
                status: 'available',
            );

            $slots[] = $slot;
            $currentSlotStart = $currentSlotStart->modify("+{$slotDuration} minutes")
                ->modify("+{$bufferTime} minutes");
        }

        $this->logger->info('Generated appointment slots', [
            'location_id' => $locationId,
            'date' => $date->format('Y-m-d'),
            'slot_count' => count($slots),
        ]);

        return $slots;
    }

    public function calculateSlotEndTime(\DateTimeImmutable $startTime, string $appointmentType): \DateTimeImmutable
    {
        $duration = match ($appointmentType) {
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

    public function getBufferForAppointmentType(string $appointmentType): int
    {
        return match ($appointmentType) {
            'telehealth' => self::ROOM_TRANSITION_BUFFER_MINUTES,
            'followup' => self::PRACTITIONER_BUFFER_MINUTES,
            'urgent' => 0,
            'new_patient' => self::ROOM_TRANSITION_BUFFER_MINUTES + self::EQUIPMENT_SETUP_BUFFER_MINUTES,
            'annual_checkup' => self::PRACTITIONER_BUFFER_MINUTES,
            default => self::APPOINTMENT_BUFFER_MINUTES,
        };
    }

    private function generateSlotId(string $locationId, \DateTimeImmutable $startTime): string
    {
        return sprintf('%s_%s_%s', $locationId, $startTime->format('YmdHis'), bin2hex(random_bytes(4)));
    }
}
