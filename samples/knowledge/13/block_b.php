<?php
declare(strict_types=1);

namespace App\Notifications\Service;

use App\Notifications\Repository\NotificationLogRepository;
use App\Notifications\Entity\Notification;
use Psr\Log\LoggerInterface;

final class BusinessHoursNotificationService
{
    public const MONDAY_OPEN = '09:00';
    public const MONDAY_CLOSE = '17:00';

    public const TUESDAY_OPEN = '09:00';
    public const TUESDAY_CLOSE = '17:00';

    public const WEDNESDAY_OPEN = '09:00';
    public const WEDNESDAY_CLOSE = '17:00';

    public const THURSDAY_OPEN = '09:00';
    public const THURSDAY_CLOSE = '17:00';

    public const FRIDAY_OPEN = '09:00';
    public const FRIDAY_CLOSE = '17:00';

    public const SATURDAY_OPEN = '10:00';
    public const SATURDAY_CLOSE = '14:00';

    public const SUNDAY_OPEN = null;
    public const SUNDAY_CLOSE = null;

    private const DAY_SCHEDULES = [
        1 => ['open' => '09:00', 'close' => '17:00'],
        2 => ['open' => '09:00', 'close' => '17:00'],
        3 => ['open' => '09:00', 'close' => '17:00'],
        4 => ['open' => '09:00', 'close' => '17:00'],
        5 => ['open' => '09:00', 'close' => '17:00'],
        6 => ['open' => '10:00', 'close' => '14:00'],
        7 => ['open' => null, 'close' => null],
    ];

    private NotificationLogRepository $notificationRepo;
    private LoggerInterface $logger;

    public function __construct(
        NotificationLogRepository $notificationRepo,
        LoggerInterface $logger
    ) {
        $this->notificationRepo = $notificationRepo;
        $this->logger = $logger;
    }

    public function sendNotification(string $userId, string $message, ?string $channel = 'email'): NotificationResult
    {
        $now = new \DateTimeImmutable();

        if (!$this->isWithinBusinessHours($now)) {
            $scheduledFor = $this->getNextBusinessHourOpening($now);

            $notification = Notification::create([
                'user_id' => $userId,
                'message' => $message,
                'channel' => $channel,
                'status' => 'scheduled',
                'scheduled_for' => $scheduledFor,
                'created_at' => new \DateTimeImmutable()
            ]);

            $this->notificationRepo->save($notification);

            $this->logger->info('Notification scheduled for business hours', [
                'user_id' => $userId,
                'scheduled_for' => $scheduledFor->format('c')
            ]);

            return new NotificationResult([
                'success' => true,
                'scheduled' => true,
                'scheduled_for' => $scheduledFor->format('c')
            ]);
        }

        return $this->deliverNotification($userId, $message, $channel);
    }

    public function isWithinBusinessHours(\DateTimeImmutable $dateTime): bool
    {
        $daySchedule = self::DAY_SCHEDULES[(int) $dateTime->format('N')];

        if ($daySchedule['open'] === null || $daySchedule['close'] === null) {
            return false;
        }

        $openTime = $this->parseTime($daySchedule['open'], $dateTime);
        $closeTime = $this->parseTime($daySchedule['close'], $dateTime);

        return $dateTime >= $openTime && $dateTime < $closeTime;
    }

    public function getNextBusinessHourOpening(\DateTimeImmutable $from): \DateTimeImmutable
    {
        $current = $from->setTime(0, 0, 0);

        for ($i = 0; $i < 7; $i++) {
            $current = $current->modify('+1 day');
            $daySchedule = self::DAY_SCHEDULES[(int) $current->format('N')];

            if ($daySchedule['open'] !== null) {
                $parts = explode(':', $daySchedule['open']);
                return $current->setTime((int) $parts[0], (int) $parts[1]);
            }
        }

        return $from->modify('+1 day')->setTime(9, 0);
    }

    public function getBusinessHoursForDay(int $dayOfWeek): ?array
    {
        $schedule = self::DAY_SCHEDULES[$dayOfWeek] ?? null;

        if ($schedule === null || $schedule['open'] === null) {
            return null;
        }

        return [
            'open' => $schedule['open'],
            'close' => $schedule['close']
        ];
    }

    public function getFullWeeklySchedule(): array
    {
        $schedule = [];

        foreach (self::DAY_SCHEDULES as $day => $hours) {
            $dayName = $this->getDayName($day);
            $schedule[$dayName] = $hours['open'] !== null
                ? "{$hours['open']} - {$hours['close']}"
                : 'Closed';
        }

        return $schedule;
    }

    private function deliverNotification(string $userId, string $message, string $channel): NotificationResult
    {
        $notification = Notification::create([
            'user_id' => $userId,
            'message' => $message,
            'channel' => $channel,
            'status' => 'sent',
            'sent_at' => new \DateTimeImmutable(),
            'created_at' => new \DateTimeImmutable()
        ]);

        $this->notificationRepo->save($notification);

        return new NotificationResult([
            'success' => true,
            'notification_id' => $notification->getId(),
            'sent_at' => (new \DateTimeImmutable())->format('c')
        ]);
    }

    private function parseTime(string $timeString, \DateTimeImmutable $date): \DateTimeImmutable
    {
        $parts = explode(':', $timeString);
        return $date->setTime((int) $parts[0], (int) $parts[1]);
    }

    private function getDayName(int $day): string
    {
        return match ($day) {
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
            7 => 'Sunday',
            default => 'Unknown'
        };
    }
}
