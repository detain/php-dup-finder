<?php
declare(strict_types=1);

namespace App\Scheduling\Service;

use App\Scheduling\Repository\AppointmentRepository;
use App\Scheduling\Entity\Appointment;
use App\Scheduling\Exception\SchedulingException;
use Psr\Log\LoggerInterface;

final class AppointmentSchedulingService
{
    public const DEFAULT_OPEN_HOUR = 9;
    public const DEFAULT_CLOSE_HOUR = 17;
    public const DEFAULT_OPEN_MINUTES = 0;
    public const DEFAULT_CLOSE_MINUTES = 0;

    public const SATURDAY_OPEN_HOUR = 10;
    public const SATURDAY_CLOSE_HOUR = 14;

    public const SUNDAY_OPEN_HOUR = 0;
    public const SUNDAY_CLOSE_HOUR = 0;

    private array $holidays = [];

    private AppointmentRepository $appointmentRepo;
    private LoggerInterface $logger;

    public function __construct(
        AppointmentRepository $appointmentRepo,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->appointmentRepo = $appointmentRepo;
        $this->logger = $logger;
        $this->holidays = $config['holidays'] ?? [];
    }

    public function scheduleAppointment(
        string $clientId,
        \DateTimeImmutable $requestedDateTime,
        int $durationMinutes,
        string $serviceType
    ): ScheduleResult {
        $date = $requestedDateTime->setTime(0, 0, 0);

        if ($this->isHoliday($date)) {
            throw new SchedulingException('Cannot schedule appointments on holidays');
        }

        $businessHours = $this->getBusinessHoursForDate($date);
        if ($businessHours === null) {
            throw new SchedulingException('Business is closed on ' . $date->format('l'));
        }

        $appointmentStart = $requestedDateTime;
        $appointmentEnd = $appointmentStart->modify("+{$durationMinutes} minutes");

        if ($appointmentStart < $businessHours['opens']) {
            throw new SchedulingException(
                'Appointment cannot start before business opens at ' . $businessHours['opens']->format('H:i')
            );
        }

        if ($appointmentEnd > $businessHours['closes']) {
            throw new SchedulingException(
                'Appointment would end after business closes at ' . $businessHours['closes']->format('H:i')
            );
        }

        $conflicting = $this->appointmentRepo->findConflictingAppointments(
            $clientId,
            $appointmentStart,
            $appointmentEnd
        );

        if (count($conflicting) > 0) {
            throw new SchedulingException('Time slot conflicts with existing appointment');
        }

        $appointment = Appointment::create([
            'client_id' => $clientId,
            'start_time' => $appointmentStart,
            'end_time' => $appointmentEnd,
            'service_type' => $serviceType,
            'status' => 'confirmed',
            'created_at' => new \DateTimeImmutable()
        ]);

        $savedAppointment = $this->appointmentRepo->save($appointment);

        $this->logger->info('Appointment scheduled', [
            'appointment_id' => $savedAppointment->getId(),
            'client_id' => $clientId,
            'start_time' => $appointmentStart->format('c')
        ]);

        return new ScheduleResult([
            'success' => true,
            'appointment_id' => $savedAppointment->getId(),
            'start_time' => $appointmentStart->format('c'),
            'end_time' => $appointmentEnd->format('c')
        ]);
    }

    public function isWithinBusinessHours(\DateTimeImmutable $dateTime): bool
    {
        $date = $dateTime->setTime(0, 0, 0);

        if ($this->isHoliday($date)) {
            return false;
        }

        $businessHours = $this->getBusinessHoursForDate($date);
        if ($businessHours === null) {
            return false;
        }

        return $dateTime >= $businessHours['opens'] && $dateTime < $businessHours['closes'];
    }

    public function getNextAvailableSlot(
        string $clientId,
        \DateTimeImmutable $preferredDate,
        int $durationMinutes
    ): ?\DateTimeImmutable {
        $currentSlot = $preferredDate->setTime(self::DEFAULT_OPEN_HOUR, self::DEFAULT_OPEN_MINUTES);
        $endOfSearch = $preferredDate->modify('+7 days');

        while ($currentSlot < $endOfSearch) {
            if ($this->isWithinBusinessHours($currentSlot)) {
                $appointmentEnd = $currentSlot->modify("+{$durationMinutes} minutes");

                if ($appointmentEnd <= $this->getClosingTime($currentSlot)) {
                    $conflicting = $this->appointmentRepo->findConflictingAppointments(
                        $clientId,
                        $currentSlot,
                        $appointmentEnd
                    );

                    if (count($conflicting) === 0) {
                        return $currentSlot;
                    }
                }
            }

            $currentSlot = $currentSlot->modify('+30 minutes');

            if ($this->isAfterClosing($currentSlot)) {
                $currentSlot = $this->getNextBusinessDayOpening($currentSlot);
            }
        }

        return null;
    }

    public function getBusinessHoursForDate(\DateTimeImmutable $date): ?array
    {
        $dayOfWeek = (int) $date->format('N');

        if ($dayOfWeek === 7) {
            return null;
        }

        if ($dayOfWeek === 6) {
            return [
                'opens' => $date->setTime(self::SATURDAY_OPEN_HOUR, self::SATURDAY_OPEN_MINUTES),
                'closes' => $date->setTime(self::SATURDAY_CLOSE_HOUR, self::SATURDAY_CLOSE_MINUTES)
            ];
        }

        return [
            'opens' => $date->setTime(self::DEFAULT_OPEN_HOUR, self::DEFAULT_OPEN_MINUTES),
            'closes' => $date->setTime(self::DEFAULT_CLOSE_HOUR, self::DEFAULT_CLOSE_MINUTES)
        ];
    }

    private function isHoliday(\DateTimeImmutable $date): bool
    {
        $dateString = $date->format('Y-m-d');
        return in_array($dateString, $this->holidays, true);
    }

    private function getClosingTime(\DateTimeImmutable $dateTime): \DateTimeImmutable
    {
        $dayOfWeek = (int) $dateTime->format('N');

        if ($dayOfWeek === 6) {
            return $dateTime->setTime(self::SATURDAY_CLOSE_HOUR, self::SATURDAY_CLOSE_MINUTES);
        }

        return $dateTime->setTime(self::DEFAULT_CLOSE_HOUR, self::DEFAULT_CLOSE_MINUTES);
    }

    private function isAfterClosing(\DateTimeImmutable $dateTime): bool
    {
        $closingTime = $this->getClosingTime($dateTime);
        return $dateTime >= $closingTime;
    }

    private function getNextBusinessDayOpening(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $nextDay = $date->modify('+1 day');

        while (!$this->isWithinBusinessHours($nextDay->setTime(self::DEFAULT_OPEN_HOUR, 0))) {
            $nextDay = $nextDay->modify('+1 day');
        }

        return $nextDay->setTime(self::DEFAULT_OPEN_HOUR, self::DEFAULT_OPEN_MINUTES);
    }
}
