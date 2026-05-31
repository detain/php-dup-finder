<?php
declare(strict_types=1);

namespace App\Reporting\Service;

use App\Reporting\Repository\ReportRepository;
use App\Reporting\Entity\ScheduledReport;
use Psr\Log\LoggerInterface;

final class ReportSchedulingService
{
    private ReportRepository $reportRepo;
    private LoggerInterface $logger;

    private const BUSINESS_HOURS = [
        'monday' => ['start' => '09:00', 'end' => '17:00'],
        'tuesday' => ['start' => '09:00', 'end' => '17:00'],
        'wednesday' => ['start' => '09:00', 'end' => '17:00'],
        'thursday' => ['start' => '09:00', 'end' => '17:00'],
        'friday' => ['start' => '09:00', 'end' => '17:00'],
        'saturday' => ['start' => '10:00', 'end' => '14:00'],
        'sunday' => null,
    ];

    public function __construct(
        ReportRepository $reportRepo,
        LoggerInterface $logger
    ) {
        $this->reportRepo = $reportRepo;
        $this->logger = $logger;
    }

    public function scheduleReport(array $reportConfig): ScheduleReportResult
    {
        $schedule = $reportConfig['schedule'];
        $preferredTime = $schedule['time'] ?? '09:00';

        if ($this->wouldRunOutsideBusinessHours($schedule['day'] ?? 'monday', $preferredTime)) {
            throw new \InvalidArgumentException(
                'Scheduled time falls outside business hours'
            );
        }

        $scheduledReport = ScheduledReport::create([
            'name' => $reportConfig['name'],
            'report_type' => $reportConfig['report_type'],
            'schedule_day' => $schedule['day'] ?? 'monday',
            'schedule_time' => $preferredTime,
            'timezone' => $schedule['timezone'] ?? 'UTC',
            'recipients' => json_encode($reportConfig['recipients']),
            'status' => 'active',
            'created_at' => new \DateTimeImmutable()
        ]);

        $savedReport = $this->reportRepo->saveScheduledReport($scheduledReport);

        $this->logger->info('Report scheduled', [
            'report_id' => $savedReport->getId(),
            'day' => $schedule['day'],
            'time' => $preferredTime
        ]);

        return new ScheduleReportResult([
            'success' => true,
            'report_id' => $savedReport->getId(),
            'scheduled_for' => "{$schedule['day']} at {$preferredTime}"
        ]);
    }

    public function wouldRunOutsideBusinessHours(string $day, string $time): bool
    {
        $dayLower = strtolower($day);
        $hours = self::BUSINESS_HOURS[$dayLower] ?? null;

        if ($hours === null) {
            return true;
        }

        return $time < $hours['start'] || $time > $hours['end'];
    }

    public function getNextScheduledRun(ScheduledReport $report): ?\DateTimeImmutable
    {
        $dayOfWeek = array_search($report->getScheduleDay(), array_keys(self::BUSINESS_HOURS), true);

        if ($dayOfWeek === false) {
            return null;
        }

        $currentDayOfWeek = (int) (new \DateTimeImmutable())->format('N');

        $daysUntil = ($dayOfWeek - $currentDayOfWeek + 7) % 7;

        if ($daysUntil === 0) {
            $scheduledTime = $this->parseScheduledTime($report->getScheduleTime());

            if ($scheduledTime <= new \DateTimeImmutable()) {
                $daysUntil = 7;
            }
        }

        $nextRun = (new \DateTimeImmutable())
            ->modify("+{$daysUntil} days")
            ->setTime((int) $scheduledTime->format('H'), (int) $scheduledTime->format('i'));

        return $nextRun;
    }

    public function isWithinBusinessHours(): bool
    {
        $now = new \DateTimeImmutable();
        $dayKey = strtolower($now->format('l'));
        $currentTime = $now->format('H:i');

        $hours = self::BUSINESS_HOURS[$dayKey] ?? null;

        if ($hours === null) {
            return false;
        }

        return $currentTime >= $hours['start'] && $currentTime <= $hours['end'];
    }

    public function getOperatingHours(): array
    {
        $result = [];

        foreach (self::BUSINESS_HOURS as $day => $hours) {
            if ($hours === null) {
                $result[$day] = 'Closed';
            } else {
                $result[$day] = "{$hours['start']} - {$hours['end']}";
            }
        }

        return $result;
    }

    public function getNextOpening(): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable();

        for ($i = 0; $i <= 7; $i++) {
            $checkDay = $now->modify("+{$i} days");
            $dayKey = strtolower($checkDay->format('l'));
            $hours = self::BUSINESS_HOURS[$dayKey];

            if ($hours !== null) {
                $parts = explode(':', $hours['start']);
                return $checkDay->setTime((int) $parts[0], (int) $parts[1]);
            }
        }

        return $now->modify('+1 day')->setTime(9, 0);
    }

    private function parseScheduledTime(string $time): \DateTimeImmutable
    {
        $parts = explode(':', $time);
        return (new \DateTimeImmutable())->setTime((int) $parts[0], (int) $parts[1]);
    }
}
