<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Entity\Transaction;
use Psr\Log\LoggerInterface;

final class TransactionAggregatorB
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Groups transactions by customer using array_reduce.
     *
     * Transforms the collection into a grouped array using
     * functional programming style reduction.
     */
    public function groupByCustomer(iterable $transactions): array
    {
        $grouped = array_reduce(
            iterator_to_array($transactions),
            function (array $carry, Transaction $transaction) {
                $customerId = $transaction->getCustomerId();

                if (!isset($carry[$customerId])) {
                    $carry[$customerId] = [];
                }

                $carry[$customerId][] = $transaction;

                return $carry;
            },
            []
        );

        $this->logger->debug('Transactions grouped by customer', [
            'customer_count' => count($grouped),
        ]);

        return $grouped;
    }

    /**
     * Filters transactions by minimum amount using array_filter.
     */
    public function filterByMinAmount(iterable $transactions, int $minAmount): array
    {
        $filtered = array_values(
            array_filter(
                iterator_to_array($transactions),
                fn(Transaction $t) => $t->getAmount() >= $minAmount
            )
        );

        $this->logger->debug('Transactions filtered by amount', [
            'min_amount' => $minAmount,
            'result_count' => count($filtered),
        ]);

        return $filtered;
    }

    /**
     * Calculates total amount using array_reduce.
     */
    public function calculateTotalAmount(iterable $transactions): int
    {
        return array_reduce(
            iterator_to_array($transactions),
            fn(int $carry, Transaction $t) => $carry + $t->getAmount(),
            0
        );
    }
}
