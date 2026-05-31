<?php

declare(strict_types=1);

namespace Acme\Wholesale\Pricing;

use Acme\Wholesale\Cart\LineItem;
use Psr\Log\LoggerInterface;

final class JanitorialSupplyDiscounter
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * @param LineItem[] $items
     */
    public function quote(array $items, string $accountNumber): float
    {
        $subtotal = 0.0;
        $caseCount = 0;
        foreach ($items as $line) {
            $subtotal += $line->unitPrice * $line->quantity;
            $caseCount += $line->quantity;
        }

        $brackets = [
            ['threshold' => 0,   'rate' => 0.0],
            ['threshold' => 10,  'rate' => 0.03],
            ['threshold' => 50,  'rate' => 0.08],
            ['threshold' => 200, 'rate' => 0.15],
            ['threshold' => 750, 'rate' => 0.22],
        ];

        usort($brackets, static fn(array $a, array $b): int => $a['threshold'] <=> $b['threshold']);

        $rate = 0.0;
        foreach ($brackets as $bracket) {
            if ($caseCount >= $bracket['threshold']) {
                $rate = $bracket['rate'];
            }
        }

        $reduction = $subtotal * $rate;
        $netDue = $subtotal - $reduction;

        $this->logger->info('janitorial.tier_applied', [
            'account' => $accountNumber,
            'cases' => $caseCount,
            'rate' => $rate,
            'gross' => $subtotal,
            'net' => $netDue,
        ]);

        return round($netDue, 2);
    }
}
