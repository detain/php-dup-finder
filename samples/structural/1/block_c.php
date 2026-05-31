<?php

declare(strict_types=1);

namespace Acme\Billing\Reports;

use Acme\Billing\Repository\RefundRepository;
use Acme\Common\Money;
use Psr\Log\LoggerInterface;

final class RefundActivityReport
{
    public function __construct(
        private readonly RefundRepository $refunds,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function generate(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = [];
        $amountCents = 0;
        $feeCents = 0;
        $count = 0;

        $records = $this->refunds->between($from, $to);
        foreach ($records as $refund) {
            $line = [
                'id' => $refund->id(),
                'reason' => $refund->reasonCode(),
                'amount' => $refund->amountCents(),
                'fee' => $refund->feeCents(),
                'processed_at' => $refund->processedAt()->format('Y-m-d'),
            ];
            $rows[] = $line;
            $amountCents += $refund->amountCents();
            $feeCents += $refund->feeCents();
            $count++;
        }

        $totals = [
            'count' => $count,
            'refunded' => Money::fromCents($amountCents)->format(),
            'fees' => Money::fromCents($feeCents)->format(),
            'net_loss' => Money::fromCents($amountCents + $feeCents)->format(),
        ];

        $this->logger->info('refund report generated', ['from' => $from->format('c'), 'to' => $to->format('c'), 'count' => $count]);

        return ['rows' => $rows, 'totals' => $totals];
    }
}
