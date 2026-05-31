<?php

declare(strict_types=1);

namespace App\Reporting;

use App\Entity\SalesReport;
use App\Repository\SalesRepository;
use App\Service\ChartGenerator;
use App\Service\PdfGenerator;
use Psr\Log\LoggerInterface;

final class SalesReportGenerator
{
    public function __construct(
        private readonly SalesRepository $salesRepository,
        private readonly ChartGenerator $chartGenerator,
        private readonly PdfGenerator $pdfGenerator,
        private readonly LoggerInterface $logger,
    ) {}

    public function generateDailyReport(\DateTimeInterface $date): SalesReport
    {
        $startOfDay = (clone $date)->setTime(0, 0, 0);
        $endOfDay = (clone $date)->setTime(23, 59, 59);

        $sales = $this->salesRepository->findByDateRange($startOfDay, $endOfDay);
        $totalRevenue = $this->calculateTotalRevenue($sales);
        $totalTransactions = count($sales);
        $averageOrderValue = $totalTransactions > 0 ? $totalRevenue / $totalTransactions : 0;

        $topProducts = $this->calculateTopProducts($sales, 10);
        $salesByHour = $this->calculateSalesByHour($sales);

        $chart = $this->chartGenerator->generateLineChart($salesByHour, 'Sales by Hour');

        $report = new SalesReport(
            'Daily Sales Report',
            $date,
            $totalRevenue,
            $totalTransactions,
            $averageOrderValue,
            $topProducts,
            $salesByHour,
            $chart
        );

        $this->logger->info('Daily sales report generated', [
            'date' => $date->format('Y-m-d'),
            'total_revenue' => $totalRevenue,
            'transactions' => $totalTransactions,
        ]);

        return $report;
    }

    public function generateWeeklyReport(\DateTimeInterface $weekStart): SalesReport
    {
        $startOfWeek = (clone $weekStart)->setTime(0, 0, 0);
        $endOfWeek = (clone $weekStart)->modify('+6 days')->setTime(23, 59, 59);

        $sales = $this->salesRepository->findByDateRange($startOfWeek, $endOfWeek);
        $totalRevenue = $this->calculateTotalRevenue($sales);
        $totalTransactions = count($sales);
        $averageOrderValue = $totalTransactions > 0 ? $totalRevenue / $totalTransactions : 0;

        $topProducts = $this->calculateTopProducts($sales, 10);
        $salesByDay = $this->calculateSalesByDay($sales);

        $chart = $this->chartGenerator->generateBarChart($salesByDay, 'Sales by Day');

        $report = new SalesReport(
            'Weekly Sales Report',
            $weekStart,
            $totalRevenue,
            $totalTransactions,
            $averageOrderValue,
            $topProducts,
            $salesByDay,
            $chart
        );

        $this->logger->info('Weekly sales report generated', [
            'week_start' => $weekStart->format('Y-m-d'),
            'total_revenue' => $totalRevenue,
            'transactions' => $totalTransactions,
        ]);

        return $report;
    }

    public function generateMonthlyReport(int $year, int $month): SalesReport
    {
        $startOfMonth = new \DateTime("{$year}-{$month}-01");
        $endOfMonth = (clone $startOfMonth)->modify('last day of this month')->setTime(23, 59, 59);

        $sales = $this->salesRepository->findByDateRange($startOfMonth, $endOfMonth);
        $totalRevenue = $this->calculateTotalRevenue($sales);
        $totalTransactions = count($sales);
        $averageOrderValue = $totalTransactions > 0 ? $totalRevenue / $totalTransactions : 0;

        $topProducts = $this->calculateTopProducts($sales, 10);
        $salesByWeek = $this->calculateSalesByWeek($sales);

        $chart = $this->chartGenerator->generateLineChart($salesByWeek, 'Sales by Week');

        $report = new SalesReport(
            'Monthly Sales Report',
            $startOfMonth,
            $totalRevenue,
            $totalTransactions,
            $averageOrderValue,
            $topProducts,
            $salesByWeek,
            $chart
        );

        $this->logger->info('Monthly sales report generated', [
            'year' => $year,
            'month' => $month,
            'total_revenue' => $totalRevenue,
            'transactions' => $totalTransactions,
        ]);

        return $report;
    }

    private function calculateTotalRevenue(array $sales): float
    {
        return array_reduce($sales, fn(float $total, $sale) => $total + $sale->getAmount(), 0.0);
    }

    private function calculateTopProducts(array $sales, int $limit): array
    {
        $productSales = [];
        foreach ($sales as $sale) {
            foreach ($sale->getItems() as $item) {
                $productId = $item->getProductId();
                $productSales[$productId] = ($productSales[$productId] ?? 0) + $item->getTotal();
            }
        }

        arsort($productSales);
        return array_slice($productSales, 0, $limit, true);
    }

    private function calculateSalesByHour(array $sales): array
    {
        $byHour = array_fill(0, 24, 0.0);
        foreach ($sales as $sale) {
            $hour = (int)$sale->getCreatedAt()->format('G');
            $byHour[$hour] += $sale->getAmount();
        }
        return $byHour;
    }

    private function calculateSalesByDay(array $sales): array
    {
        $byDay = [];
        foreach ($sales as $sale) {
            $day = $sale->getCreatedAt()->format('Y-m-d');
            $byDay[$day] = ($byDay[$day] ?? 0) + $sale->getAmount();
        }
        return $byDay;
    }

    private function calculateSalesByWeek(array $sales): array
    {
        $byWeek = [];
        foreach ($sales as $sale) {
            $week = $sale->getCreatedAt()->format('Y-W');
            $byWeek[$week] = ($byWeek[$week] ?? 0) + $sale->getAmount();
        }
        return $byWeek;
    }
}
