<?php
declare(strict_types=1);

namespace Scheduling\Shared;

final class AppointmentDurationConstants
{
    public const DEFAULT_DURATION_MINUTES = 30;
    public const MINIMUM_DURATION_MINUTES = 15;
    public const MAXIMUM_DURATION_MINUTES = 120;
    public const EXTENDED_DURATION_MINUTES = 45;
    public const TELEHEALTH_DURATION_MINUTES = 20;
    public const FOLLOWUP_DURATION_MINUTES = 15;
    public const URGENT_DURATION_MINUTES = 30;
    public const NEW_PATIENT_DURATION_MINUTES = 60;
    public const ANNUAL_CHECKUP_DURATION_MINUTES = 45;
    public const CONSULTATION_DURATION_MINUTES = 30;

    public const APPOINTMENT_BUFFER_MINUTES = 10;
    public const PRACTITIONER_BUFFER_MINUTES = 15;
    public const ROOM_TRANSITION_BUFFER_MINUTES = 5;
    public const EQUIPMENT_SETUP_BUFFER_MINUTES = 10;

    private const DURATION_MAP = [
        'telehealth' => self::TELEHEALTH_DURATION_MINUTES,
        'followup' => self::FOLLOWUP_DURATION_MINUTES,
        'urgent' => self::URGENT_DURATION_MINUTES,
        'new_patient' => self::NEW_PATIENT_DURATION_MINUTES,
        'annual_checkup' => self::ANNUAL_CHECKUP_DURATION_MINUTES,
        'consultation' => self::CONSULTATION_DURATION_MINUTES,
        'extended' => self::EXTENDED_DURATION_MINUTES,
    ];

    private const BUFFER_MAP = [
        'telehealth' => self::ROOM_TRANSITION_BUFFER_MINUTES,
        'followup' => self::PRACTITIONER_BUFFER_MINUTES,
        'urgent' => 0,
        'new_patient' => self::ROOM_TRANSITION_BUFFER_MINUTES + self::EQUIPMENT_SETUP_BUFFER_MINUTES,
        'annual_checkup' => self::PRACTITIONER_BUFFER_MINUTES,
    ];

    public static function getDurationForType(string $appointmentType): int
    {
        $duration = self::DURATION_MAP[$appointmentType] ?? self::DEFAULT_DURATION_MINUTES;
        return max(self::MINIMUM_DURATION_MINUTES, min($duration, self::MAXIMUM_DURATION_MINUTES));
    }

    public static function getBufferForType(string $appointmentType): int
    {
        return self::BUFFER_MAP[$appointmentType] ?? self::APPOINTMENT_BUFFER_MINUTES;
    }
}

interface SlotGeneratorInterface
{
    public function generateSlots(\DateTimeImmutable $date): array;
    public function calculateEndTime(\DateTimeImmutable $startTime, string $type): \DateTimeImmutable;
    public function getBufferForType(string $type): int;
}

trait SlotGenerationLogic
{
    private AppointmentDurationConstants $constants;

    protected function computeSlotWindows(
        \DateTimeImmutable $date,
        \DateTimeInterface $workStart,
        \DateTimeInterface $workEnd,
    ): array {
        $windows = [];
        $currentStart = \DateTimeImmutable::createFromMutable($date)->setTime(
            (int)$workStart->format('H'),
            (int)$workStart->format('i'),
        );

        while ($currentStart < $workEnd) {
            $duration = $this->constants::getDurationForType('default');
            $buffer = $this->constants::getBufferForType('default');

            $windows[] = [
                'start' => $currentStart,
                'end' => $currentStart->modify("+{$duration} minutes"),
                'buffer' => $buffer,
            ];

            $currentStart = $currentStart
                ->modify("+{$duration} minutes")
                ->modify("+{$buffer} minutes");
        }

        return $windows;
    }
}
