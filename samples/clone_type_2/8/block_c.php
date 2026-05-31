<?php

declare(strict_types=1);

namespace App\Reporting;

use App\Entity\UserReport;
use App\Repository\UserRepository;
use App\Service\ChartGenerator;
use App\Service\PdfGenerator;
use Psr\Log\LoggerInterface;

final class UserReportGenerator
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly ChartGenerator $chartGenerator,
        private readonly PdfGenerator $pdfGenerator,
        private readonly LoggerInterface $logger,
    ) {}

    public function generateDailyReport(\DateTimeInterface $date): UserReport
    {
        $startOfDay = (clone $date)->setTime(0, 0, 0);
        $endOfDay = (clone $date)->setTime(23, 59, 59);

        $users = $this->userRepository->findByDateRange($startOfDay, $endOfDay);
        $newUsers = $this->filterNewUsers($users);
        $returningUsers = $this->filterReturningUsers($users);
        $totalUsers = count($users);

        $newUserRate = $totalUsers > 0 ? count($newUsers) / $totalUsers : 0;
        $topReferrers = $this->calculateTopReferrers($users, 10);
        $usersByHour = $this->calculateUsersByHour($users);

        $chart = $this->chartGenerator->generateLineChart($usersByHour, 'New Users by Hour');

        $report = new UserReport(
            'Daily User Report',
            $date,
            count($newUsers),
            count($returningUsers),
            $newUserRate,
            $topReferrers,
            $usersByHour,
            $chart
        );

        $this->logger->info('Daily user report generated', [
            'date' => $date->format('Y-m-d'),
            'new_users' => count($newUsers),
            'returning_users' => count($returningUsers),
        ]);

        return $report;
    }

    public function generateWeeklyReport(\DateTimeInterface $weekStart): UserReport
    {
        $startOfWeek = (clone $weekStart)->setTime(0, 0, 0);
        $endOfWeek = (clone $weekStart)->modify('+6 days')->setTime(23, 59, 59);

        $users = $this->userRepository->findByDateRange($startOfWeek, $endOfWeek);
        $newUsers = $this->filterNewUsers($users);
        $returningUsers = $this->filterReturningUsers($users);
        $totalUsers = count($users);

        $newUserRate = $totalUsers > 0 ? count($newUsers) / $totalUsers : 0;
        $topReferrers = $this->calculateTopReferrers($users, 10);
        $usersByDay = $this->calculateUsersByDay($users);

        $chart = $this->chartGenerator->generateBarChart($usersByDay, 'New Users by Day');

        $report = new UserReport(
            'Weekly User Report',
            $weekStart,
            count($newUsers),
            count($returningUsers),
            $newUserRate,
            $topReferrers,
            $usersByDay,
            $chart
        );

        $this->logger->info('Weekly user report generated', [
            'week_start' => $weekStart->format('Y-m-d'),
            'new_users' => count($newUsers),
            'returning_users' => count($returningUsers),
        ]);

        return $report;
    }

    public function generateMonthlyReport(int $year, int $month): UserReport
    {
        $startOfMonth = new \DateTime("{$year}-{$month}-01");
        $endOfMonth = (clone $startOfMonth)->modify('last day of this month')->setTime(23, 59, 59);

        $users = $this->userRepository->findByDateRange($startOfMonth, $endOfMonth);
        $newUsers = $this->filterNewUsers($users);
        $returningUsers = $this->filterReturningUsers($users);
        $totalUsers = count($users);

        $newUserRate = $totalUsers > 0 ? count($newUsers) / $totalUsers : 0;
        $topReferrers = $this->calculateTopReferrers($users, 10);
        $usersByWeek = $this->calculateUsersByWeek($users);

        $chart = $this->chartGenerator->generateLineChart($usersByWeek, 'New Users by Week');

        $report = new UserReport(
            'Monthly User Report',
            $startOfMonth,
            count($newUsers),
            count($returningUsers),
            $newUserRate,
            $topReferrers,
            $usersByWeek,
            $chart
        );

        $this->logger->info('Monthly user report generated', [
            'year' => $year,
            'month' => $month,
            'new_users' => count($newUsers),
            'returning_users' => count($returningUsers),
        ]);

        return $report;
    }

    private function filterNewUsers(array $users): array
    {
        return array_filter($users, fn($user) => $user->getCreatedAt()->diffInDays($user->getLastLoginAt()) <= 1);
    }

    private function filterReturningUsers(array $users): array
    {
        return array_filter($users, fn($user) => $user->getCreatedAt()->diffInDays($user->getLastLoginAt()) > 1);
    }

    private function calculateTopReferrers(array $users, int $limit): array
    {
        $referrerCounts = [];
        foreach ($users as $user) {
            $referrer = $user->getReferrerSource();
            if ($referrer !== null) {
                $referrerCounts[$referrer] = ($referrerCounts[$referrer] ?? 0) + 1;
            }
        }

        arsort($referrerCounts);
        return array_slice($referrerCounts, 0, $limit, true);
    }

    private function calculateUsersByHour(array $users): array
    {
        $byHour = array_fill(0, 24, 0);
        foreach ($users as $user) {
            $hour = (int)$user->getCreatedAt()->format('G');
            $byHour[$hour]++;
        }
        return $byHour;
    }

    private function calculateUsersByDay(array $users): array
    {
        $byDay = [];
        foreach ($users as $user) {
            $day = $user->getCreatedAt()->format('Y-m-d');
            $byDay[$day] = ($byDay[$day] ?? 0) + 1;
        }
        return $byDay;
    }

    private function calculateUsersByWeek(array $users): array
    {
        $byWeek = [];
        foreach ($users as $user) {
            $week = $user->getCreatedAt()->format('Y-W');
            $byWeek[$week] = ($byWeek[$week] ?? 0) + 1;
        }
        return $byWeek;
    }
}
