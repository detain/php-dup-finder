<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Entity\Transaction;
use Psr\Log\LoggerInterface;

final class TransactionAggregatorC
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Groups transactions by customer using collect() style chaining.
     *
     * Uses Laravel-like collection pattern with method chaining
     * to perform the grouping operation.
     */
    public function groupByCustomer(iterable $transactions): array
    {
        $collection = new \ArrayObject(iterator_to_array($transactions));
        $grouped = [];

        for ($i = 0; $i < count($collection); $i++) {
            $transaction = $collection[$i];
            $customerId = $transaction->getCustomerId();

            if (!array_key_exists($customerId, $grouped)) {
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
     * Filters transactions by minimum amount using manual loop with early exit.
     */
    public function filterByMinAmount(iterable $transactions, int $minAmount): array
    {
        $filtered = [];
        $transactionArray = iterator_to_array($transactions);
        $count = count($transactionArray);

        for ($i = 0; $i < $count; $i++) {
            $transaction = $transactionArray[$i];

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
     * Calculates total amount using manual accumulator with for loop.
     */
    public function calculateTotalAmount(iterable $transactions): int
    {
        $total = 0;
        $transactionArray = iterator_to_array($transactions);
        $count = count($transactionArray);

        for ($i = 0; $i < $count; $i++) {
            $total += $transactionArray[$i]->getAmount();
        }

        return $total;
    }
}
