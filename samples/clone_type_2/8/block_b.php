<?php

declare(strict_types=1);

namespace App\Reporting;

use App\Entity\InventoryReport;
use App\Repository\InventoryRepository;
use App\Service\ChartGenerator;
use App\Service\PdfGenerator;
use Psr\Log\LoggerInterface;

final class InventoryReportGenerator
{
    public function __construct(
        private readonly InventoryRepository $inventoryRepository,
        private readonly ChartGenerator $chartGenerator,
        private readonly PdfGenerator $pdfGenerator,
        private readonly LoggerInterface $logger,
    ) {}

    public function generateDailyReport(\DateTimeInterface $date): InventoryReport
    {
        $startOfDay = (clone $date)->setTime(0, 0, 0);
        $endOfDay = (clone $date)->setTime(23, 59, 59);

        $movements = $this->inventoryRepository->findMovementsByDateRange($startOfDay, $endOfDay);
        $totalStockIn = $this->calculateTotalStockIn($movements);
        $totalStockOut = $this->calculateTotalStockOut($movements);
        $netChange = $totalStockIn - $totalStockOut;

        $lowStockItems = $this->calculateLowStockItems($movements, 10);
        $movementsByHour = $this->calculateMovementsByHour($movements);

        $chart = $this->chartGenerator->generateLineChart($movementsByHour, 'Stock Movements by Hour');

        $report = new InventoryReport(
            'Daily Inventory Report',
            $date,
            $totalStockIn,
            $totalStockOut,
            $netChange,
            $lowStockItems,
            $movementsByHour,
            $chart
        );

        $this->logger->info('Daily inventory report generated', [
            'date' => $date->format('Y-m-d'),
            'stock_in' => $totalStockIn,
            'stock_out' => $totalStockOut,
            'net_change' => $netChange,
        ]);

        return $report;
    }

    public function generateWeeklyReport(\DateTimeInterface $weekStart): InventoryReport
    {
        $startOfWeek = (clone $weekStart)->setTime(0, 0, 0);
        $endOfWeek = (clone $weekStart)->modify('+6 days')->setTime(23, 59, 59);

        $movements = $this->inventoryRepository->findMovementsByDateRange($startOfWeek, $endOfWeek);
        $totalStockIn = $this->calculateTotalStockIn($movements);
        $totalStockOut = $this->calculateTotalStockOut($movements);
        $netChange = $totalStockIn - $totalStockOut;

        $lowStockItems = $this->calculateLowStockItems($movements, 10);
        $movementsByDay = $this->calculateMovementsByDay($movements);

        $chart = $this->chartGenerator->generateBarChart($movementsByDay, 'Stock Movements by Day');

        $report = new InventoryReport(
            'Weekly Inventory Report',
            $weekStart,
            $totalStockIn,
            $totalStockOut,
            $netChange,
            $lowStockItems,
            $movementsByDay,
            $chart
        );

        $this->logger->info('Weekly inventory report generated', [
            'week_start' => $weekStart->format('Y-m-d'),
            'stock_in' => $totalStockIn,
            'stock_out' => $totalStockOut,
            'net_change' => $netChange,
        ]);

        return $report;
    }

    public function generateMonthlyReport(int $year, int $month): InventoryReport
    {
        $startOfMonth = new \DateTime("{$year}-{$month}-01");
        $endOfMonth = (clone $startOfMonth)->modify('last day of this month')->setTime(23, 59, 59);

        $movements = $this->inventoryRepository->findMovementsByDateRange($startOfMonth, $endOfMonth);
        $totalStockIn = $this->calculateTotalStockIn($movements);
        $totalStockOut = $this->calculateTotalStockOut($movements);
        $netChange = $totalStockIn - $totalStockOut;

        $lowStockItems = $this->calculateLowStockItems($movements, 10);
        $movementsByWeek = $this->calculateMovementsByWeek($movements);

        $chart = $this->chartGenerator->generateLineChart($movementsByWeek, 'Stock Movements by Week');

        $report = new InventoryReport(
            'Monthly Inventory Report',
            $startOfMonth,
            $totalStockIn,
            $totalStockOut,
            $netChange,
            $lowStockItems,
            $movementsByWeek,
            $chart
        );

        $this->logger->info('Monthly inventory report generated', [
            'year' => $year,
            'month' => $month,
            'stock_in' => $totalStockIn,
            'stock_out' => $totalStockOut,
            'net_change' => $netChange,
        ]);

        return $report;
    }

    private function calculateTotalStockIn(array $movements): int
    {
        return array_reduce($movements, fn(int $total, $movement) => $total + $movement->getStockIn(), 0);
    }

    private function calculateTotalStockOut(array $movements): int
    {
        return array_reduce($movements, fn(int $total, $movement) => $total + $movement->getStockOut(), 0);
    }

    private function calculateLowStockItems(array $movements, int $limit): array
    {
        $productNetChange = [];
        foreach ($movements as $movement) {
            $productId = $movement->getProductId();
            $productNetChange[$productId] = ($productNetChange[$productId] ?? 0) + $movement->getStockIn() - $movement->getStockOut();
        }

        asort($productNetChange);
        return array_slice($productNetChange, 0, $limit, true);
    }

    private function calculateMovementsByHour(array $movements): array
    {
        $byHour = array_fill(0, 24, 0);
        foreach ($movements as $movement) {
            $hour = (int)$movement->getCreatedAt()->format('G');
            $byHour[$hour] += $movement->getStockIn() + $movement->getStockOut();
        }
        return $byHour;
    }

    private function calculateMovementsByDay(array $movements): array
    {
        $byDay = [];
        foreach ($movements as $movement) {
            $day = $movement->getCreatedAt()->format('Y-m-d');
            $byDay[$day] = ($byDay[$day] ?? 0) + $movement->getStockIn() + $movement->getStockOut();
        }
        return $byDay;
    }

    private function calculateMovementsByWeek(array $movements): array
    {
        $byWeek = [];
        foreach ($movements as $movement) {
            $week = $movement->getCreatedAt()->format('Y-W');
            $byWeek[$week] = ($byWeek[$week] ?? 0) + $movement->getStockIn() + $movement->getStockOut();
        }
        return $byWeek;
    }
}
