<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Entity\Transaction;
use Psr\Log\LoggerInterface;

interface TransactionAggregatorInterface
{
    public function groupByCustomer(iterable $transactions): array;
    public function filterByMinAmount(iterable $transactions, int $minAmount): array;
    public function calculateTotalAmount(iterable $transactions): int;
}

abstract class AbstractTransactionAggregator implements TransactionAggregatorInterface
{
    public function __construct(
        protected readonly LoggerInterface $logger,
    ) {}

    protected function logOperation(string $operation, array $context = []): void
    {
        $this->logger->debug("Transaction aggregator: {$operation}", $context);
    }
}

final class LoopBasedAggregator extends AbstractTransactionAggregator
{
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

        $this->logOperation('groupByCustomer', ['customer_count' => count($grouped)]);

        return $grouped;
    }

    public function filterByMinAmount(iterable $transactions, int $minAmount): array
    {
        $filtered = [];

        foreach ($transactions as $transaction) {
            if ($transaction->getAmount() >= $minAmount) {
                $filtered[] = $transaction;
            }
        }

        $this->logOperation('filterByMinAmount', [
            'min_amount' => $minAmount,
            'result_count' => count($filtered),
        ]);

        return $filtered;
    }

    public function calculateTotalAmount(iterable $transactions): int
    {
        $total = 0;

        foreach ($transactions as $transaction) {
            $total += $transaction->getAmount();
        }

        return $total;
    }
}

final class AggregationStrategyFactory
{
    public static function create(string $strategy): TransactionAggregatorInterface
    {
        $logger = new \Psr\Log\NullLogger();

        return match ($strategy) {
            'loop' => new LoopBasedAggregator($logger),
            'functional' => new FunctionalAggregator($logger),
            'array' => new ArrayBasedAggregator($logger),
            default => throw new \InvalidArgumentException("Unknown strategy: {$strategy}"),
        };
    }
}
