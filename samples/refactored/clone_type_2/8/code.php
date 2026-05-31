<?php

declare(strict_types=1);

namespace App\Reporting;

use App\Entity\ReportInterface;
use App\Repository\ReportableRepositoryInterface;
use App\Service\ChartGenerator;
use App\Service\PdfGenerator;
use Psr\Log\LoggerInterface;

interface ReportGeneratorInterface
{
    public function generateDaily(\DateTimeInterface $date): ReportInterface;
    public function generateWeekly(\DateTimeInterface $weekStart): ReportInterface;
    public function generateMonthly(int $year, int $month): ReportInterface;
}

abstract class AbstractReportGenerator implements ReportGeneratorInterface
{
    public function __construct(
        protected readonly ReportableRepositoryInterface $repository,
        protected readonly ChartGenerator $chartGenerator,
        protected readonly LoggerInterface $logger,
    ) {}

    public function generateDaily(\DateTimeInterface $date): ReportInterface
    {
        $startOfDay = (clone $date)->setTime(0, 0, 0);
        $endOfDay = (clone $date)->setTime(23, 59, 59);

        $records = $this->repository->findByDateRange($startOfDay, $endOfDay);
        $metrics = $this->calculateMetrics($records);

        $chart = $this->chartGenerator->generateLineChart(
            $this->aggregateByHour($records),
            $this->getHourlyChartTitle()
        );

        $report = $this->buildReport(
            $this->getDailyReportTitle(),
            $date,
            $metrics,
            $this->aggregateByHour($records),
            $chart
        );

        $this->logger->info('Daily report generated', [
            'type' => $this->getReportType(),
            'date' => $date->format('Y-m-d'),
        ]);

        return $report;
    }

    public function generateWeekly(\DateTimeInterface $weekStart): ReportInterface
    {
        $startOfWeek = (clone $weekStart)->setTime(0, 0, 0);
        $endOfWeek = (clone $weekStart)->modify('+6 days')->setTime(23, 59, 59);

        $records = $this->repository->findByDateRange($startOfWeek, $endOfWeek);
        $metrics = $this->calculateMetrics($records);

        $chart = $this->chartGenerator->generateBarChart(
            $this->aggregateByDay($records),
            $this->getDailyChartTitle()
        );

        $report = $this->buildReport(
            $this->getWeeklyReportTitle(),
            $weekStart,
            $metrics,
            $this->aggregateByDay($records),
            $chart
        );

        $this->logger->info('Weekly report generated', [
            'type' => $this->getReportType(),
            'week_start' => $weekStart->format('Y-m-d'),
        ]);

        return $report;
    }

    public function generateMonthly(int $year, int $month): ReportInterface
    {
        $startOfMonth = new \DateTime("{$year}-{$month}-01");
        $endOfMonth = (clone $startOfMonth)->modify('last day of this month')->setTime(23, 59, 59);

        $records = $this->repository->findByDateRange($startOfMonth, $endOfMonth);
        $metrics = $this->calculateMetrics($records);

        $chart = $this->chartGenerator->generateLineChart(
            $this->aggregateByWeek($records),
            $this->getWeeklyChartTitle()
        );

        $report = $this->buildReport(
            $this->getMonthlyReportTitle(),
            $startOfMonth,
            $metrics,
            $this->aggregateByWeek($records),
            $chart
        );

        $this->logger->info('Monthly report generated', [
            'type' => $this->getReportType(),
            'year' => $year,
            'month' => $month,
        ]);

        return $report;
    }

    abstract protected function getReportType(): string;
    abstract protected function calculateMetrics(array $records): array;
    abstract protected function buildReport(string $title, \DateTimeInterface $date, array $metrics, array $aggregates, mixed $chart): ReportInterface;

    abstract protected function getDailyReportTitle(): string;
    abstract protected function getWeeklyReportTitle(): string;
    abstract protected function getMonthlyReportTitle(): string;
    abstract protected function getHourlyChartTitle(): string;
    abstract protected function getDailyChartTitle(): string;
    abstract protected function getWeeklyChartTitle(): string;

    abstract protected function aggregateByHour(array $records): array;
    abstract protected function aggregateByDay(array $records): array;
    abstract protected function aggregateByWeek(array $records): array;
}
