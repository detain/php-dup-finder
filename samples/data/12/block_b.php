<?php
declare(strict_types=1);

namespace MedReserve\Scheduling\Consultation;

use Psr\Log\LoggerInterface;
use MedReserve\Scheduling\Entities\TimeBlock;
use MedReserve\Scheduling\Repository\PractitionerRepository;

final class TimeBlockGenerator
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
        private readonly PractitionerRepository $practitionerRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function generateTimeBlocksForPractitioner(string $practitionerId, \DateTimeImmutable $date): array
    {
        $practitioner = $this->practitionerRepository->findById($practitionerId);
        if ($practitioner === null) {
            $this->logger->error('Practitioner not found for block generation', [
                'practitioner_id' => $practitionerId,
                'date' => $date->format('Y-m-d'),
            ]);
            return [];
        }

        $blocks = [];
        $workStart = $practitioner->getWorkStartTime();
        $workEnd = $practitioner->getWorkEndTime();
        $currentBlockStart = \DateTimeImmutable::createFromMutable($date)->setTime(
            (int)$workStart->format('H'),
            (int)$workStart->format('i'),
        );

        while ($currentBlockStart < $workEnd) {
            $blockDuration = self::DEFAULT_APPOINTMENT_DURATION_MINUTES;
            $bufferTime = self::APPOINTMENT_BUFFER_MINUTES;

            $block = new TimeBlock(
                id: $this->generateBlockId($practitionerId, $currentBlockStart),
                practitionerId: $practitionerId,
                startTime: $currentBlockStart,
                endTime: $currentBlockStart->modify("+{$blockDuration} minutes"),
                bufferMinutes: $bufferTime,
                status: 'available',
            );

            $blocks[] = $block;
            $currentBlockStart = $currentBlockStart->modify("+{$blockDuration} minutes")
                ->modify("+{$bufferTime} minutes");
        }

        $this->logger->info('Generated time blocks for practitioner', [
            'practitioner_id' => $practitionerId,
            'date' => $date->format('Y-m-d'),
            'block_count' => count($blocks),
        ]);

        return $blocks;
    }

    public function calculateBlockEndTime(\DateTimeImmutable $startTime, string $consultationType): \DateTimeImmutable
    {
        $duration = match ($consultationType) {
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

    public function getBufferForConsultationType(string $consultationType): int
    {
        return match ($consultationType) {
            'telehealth' => self::ROOM_TRANSITION_BUFFER_MINUTES,
            'followup' => self::PRACTITIONER_BUFFER_MINUTES,
            'urgent' => 0,
            'new_patient' => self::ROOM_TRANSITION_BUFFER_MINUTES + self::EQUIPMENT_SETUP_BUFFER_MINUTES,
            'annual_checkup' => self::PRACTITIONER_BUFFER_MINUTES,
            default => self::APPOINTMENT_BUFFER_MINUTES,
        };
    }

    private function generateBlockId(string $practitionerId, \DateTimeImmutable $startTime): string
    {
        return sprintf('%s_%s_%s', $practitionerId, $startTime->format('YmdHis'), bin2hex(random_bytes(4)));
    }
}
