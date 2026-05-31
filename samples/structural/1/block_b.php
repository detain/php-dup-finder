<?php

declare(strict_types=1);

namespace Acme\Billing\Reports;

use Acme\Billing\Repository\SubscriptionRepository;
use Acme\Common\Money;
use Psr\Log\LoggerInterface;

final class SubscriptionRenewalReport
{
    public function __construct(
        private readonly SubscriptionRepository $subs,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function generate(int $year, int $quarter): array
    {
        $rows = [];
        $mrrCents = 0;
        $churnedCents = 0;
        $count = 0;

        $records = $this->subs->renewedInQuarter($year, $quarter);
        foreach ($records as $sub) {
            $line = [
                'id' => $sub->id(),
                'plan' => $sub->planCode(),
                'mrr' => $sub->mrrCents(),
                'churned' => $sub->churnedCents(),
                'renewed_at' => $sub->renewedAt()->format('Y-m-d'),
            ];
            $rows[] = $line;
            $mrrCents += $sub->mrrCents();
            $churnedCents += $sub->churnedCents();
            $count++;
        }

        $totals = [
            'count' => $count,
            'mrr' => Money::fromCents($mrrCents)->format(),
            'churned' => Money::fromCents($churnedCents)->format(),
            'net_mrr' => Money::fromCents($mrrCents - $churnedCents)->format(),
        ];

        $this->logger->info('renewal report generated', ['year' => $year, 'quarter' => $quarter, 'count' => $count]);

        return ['rows' => $rows, 'totals' => $totals];
    }
}
