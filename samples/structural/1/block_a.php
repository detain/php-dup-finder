<?php

declare(strict_types=1);

namespace Acme\Billing\Reports;

use Acme\Billing\Repository\OrderRepository;
use Acme\Common\Money;
use Psr\Log\LoggerInterface;

final class MonthlySalesReport
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function generate(int $year, int $month): array
    {
        $rows = [];
        $grossCents = 0;
        $taxCents = 0;
        $count = 0;

        $records = $this->orders->findInMonth($year, $month);
        foreach ($records as $order) {
            $line = [
                'id' => $order->id(),
                'customer' => $order->customerName(),
                'gross' => $order->grossCents(),
                'tax' => $order->taxCents(),
                'placed_at' => $order->placedAt()->format('Y-m-d'),
            ];
            $rows[] = $line;
            $grossCents += $order->grossCents();
            $taxCents += $order->taxCents();
            $count++;
        }

        $totals = [
            'count' => $count,
            'gross' => Money::fromCents($grossCents)->format(),
            'tax' => Money::fromCents($taxCents)->format(),
            'net' => Money::fromCents($grossCents - $taxCents)->format(),
        ];

        $this->logger->info('sales report generated', ['year' => $year, 'month' => $month, 'count' => $count]);

        return ['rows' => $rows, 'totals' => $totals];
    }
}
