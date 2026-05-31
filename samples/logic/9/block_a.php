<?php

declare(strict_types=1);

namespace App\Commission;

use App\Entity\Transaction;
use App\Repository\TransactionRepository;
use Psr\Log\LoggerInterface;

final class CommissionCalculatorService
{
    public function __construct(
        private readonly TransactionRepository $transactionRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function calculateCommission(int $transactionId): int
    {
        $transaction = $this->transactionRepository->findById($transactionId);

        if ($transaction === null) {
            throw new \RuntimeException('Transaction not found');
        }

        $amount = $transaction->getAmount();
        $transactionType = $transaction->getType();
        $userTier = $transaction->getUserTier();

        if ($transactionType === 'buy') {
            return $this->calculateBuyCommission($amount, $userTier);
        }

        if ($transactionType === 'sell') {
            return $this->calculateSellCommission($amount, $userTier);
        }

        if ($transactionType === 'transfer') {
            return $this->calculateTransferCommission($amount, $userTier);
        }

        throw new \InvalidArgumentException('Unknown transaction type');
    }

    private function calculateBuyCommission(int $amount, string $tier): int
    {
        if ($amount <= 1000) {
            $rate = 0.015;
        } elseif ($amount <= 10000) {
            $rate = 0.0125;
        } elseif ($amount <= 50000) {
            $rate = 0.01;
        } else {
            $rate = 0.0075;
        }

        if ($tier === 'premium') {
            $rate *= 0.8;
        } elseif ($tier === 'vip') {
            $rate *= 0.6;
        }

        return (int) round($amount * $rate);
    }

    private function calculateSellCommission(int $amount, string $tier): int
    {
        if ($amount <= 1000) {
            $rate = 0.02;
        } elseif ($amount <= 10000) {
            $rate = 0.0175;
        } elseif ($amount <= 50000) {
            $rate = 0.015;
        } else {
            $rate = 0.01;
        }

        if ($tier === 'premium') {
            $rate *= 0.75;
        } elseif ($tier === 'vip') {
            $rate *= 0.5;
        }

        return (int) round($amount * $rate);
    }

    private function calculateTransferCommission(int $amount, string $tier): int
    {
        if ($amount <= 100) {
            $flatFee = 1;
        } elseif ($amount <= 1000) {
            $flatFee = 5;
        } elseif ($amount <= 10000) {
            $flatFee = 10;
        } else {
            $flatFee = 25;
        }

        if ($tier === 'premium') {
            $flatFee = (int) round($flatFee * 0.5);
        } elseif ($tier === 'vip') {
            $flatFee = 0;
        }

        return $flatFee;
    }

    public function calculateMonthlyCommission(int $userId, string $month): int
    {
        $transactions = $this->transactionRepository->findByUserAndMonth($userId, $month);
        $totalCommission = 0;

        foreach ($transactions as $transaction) {
            $totalCommission += $this->calculateCommission($transaction->getId());
        }

        return $totalCommission;
    }
}
