<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Entity\Transaction;
use Psr\Log\LoggerInterface;

final class TransactionAggregatorA
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Groups transactions by customer using iterative approach.
     *
     * Builds result array by iterating through transactions
     * and accumulating them by customer ID.
     */
    public function groupByCustomer(iterable $transactions): array
    {
        $grouped = [];

        foreach ($transactions as $transaction) {
            $customerId = $transaction->getCustomerId();

            if (!isset($grouped[$customerId])) {
                $grouped[$customerId] = [];
            }

            $grouped[$customerId][] = $transaction;
        }

        $this->logger->debug('Transactions grouped by customer', [
            'customer_count' => count($grouped),
        ]);

        return $grouped;
    }

    /**
     * Filters transactions by minimum amount using loop.
     */
    public function filterByMinAmount(iterable $transactions, int $minAmount): array
    {
        $filtered = [];

        foreach ($transactions as $transaction) {
            if ($transaction->getAmount() >= $minAmount) {
                $filtered[] = $transaction;
            }
        }

        $this->logger->debug('Transactions filtered by amount', [
            'min_amount' => $minAmount,
            'result_count' => count($filtered),
        ]);

        return $filtered;
    }

    /**
     * Calculates total amount using iterative accumulation.
     */
    public function calculateTotalAmount(iterable $transactions): int
    {
        $total = 0;

        foreach ($transactions as $transaction) {
            $total += $transaction->getAmount();
        }

        return $total;
    }
}
