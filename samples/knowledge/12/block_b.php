<?php
declare(strict_types=1);

namespace App\Banking\API;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class TransferRequestValidator
{
    public const MAX_DAILY_TRANSFER = 10000.00;
    public const MAX_MONTHLY_TRANSFER = 100000.00;
    public const MAX_SINGLE_TRANSFER = 5000.00;

    public function validate(array $data, ExecutionContextInterface $context): void
    {
        $this->validateSingleTransferLimit($data['amount'] ?? 0, $context);
        $this->validateDailyLimit($data['account_id'] ?? '', $data['amount'] ?? 0, $context);
        $this->validateMonthlyLimit($data['account_id'] ?? '', $data['amount'] ?? 0, $context);
        $this->validateSufficientBalance($data['account_id'] ?? '', $data['amount'] ?? 0, $context);
    }

    private function validateSingleTransferLimit(float $amount, ExecutionContextInterface $context): void
    {
        if ($amount > self::MAX_SINGLE_TRANSFER) {
            $context->buildViolation('Single transfer cannot exceed {{ limit }}')
                ->setParameter('{{ limit }}', number_format(self::MAX_SINGLE_TRANSFER, 2))
                ->atPath('amount')
                ->addViolation();
        }

        if ($amount < 0.01) {
            $context->buildViolation('Transfer amount must be at least 0.01')
                ->atPath('amount')
                ->addViolation();
        }
    }

    private function validateDailyLimit(string $accountId, float $amount, ExecutionContextInterface $context): void
    {
        if (empty($accountId)) {
            return;
        }

        $todayTotal = $this->getTodayTotal($accountId);
        if (($todayTotal + $amount) > self::MAX_DAILY_TRANSFER) {
            $context->buildViolation(
                'Daily transfer limit of {{ limit }} would be exceeded. ' .
                'Current: {{ current }}, Attempting: {{ amount }}'
            )
                ->setParameter('{{ limit }}', number_format(self::MAX_DAILY_TRANSFER, 2))
                ->setParameter('{{ current }}', number_format($todayTotal, 2))
                ->setParameter('{{ amount }}', number_format($amount, 2))
                ->atPath('amount')
                ->addViolation();
        }
    }

    private function validateMonthlyLimit(string $accountId, float $amount, ExecutionContextInterface $context): void
    {
        if (empty($accountId)) {
            return;
        }

        $monthTotal = $this->getMonthTotal($accountId);
        if (($monthTotal + $amount) > self::MAX_MONTHLY_TRANSFER) {
            $context->buildViolation(
                'Monthly transfer limit of {{ limit }} would be exceeded'
            )
                ->setParameter('{{ limit }}', number_format(self::MAX_MONTHLY_TRANSFER, 2))
                ->atPath('amount')
                ->addViolation();
        }
    }

    private function validateSufficientBalance(string $accountId, float $amount, ExecutionContextInterface $context): void
    {
        if (empty($accountId)) {
            return;
        }

        $balance = $this->getAccountBalance($accountId);
        if ($balance < $amount) {
            $context->buildViolation(
                'Insufficient funds. Available: {{ available }}, Required: {{ required }}'
            )
                ->setParameter('{{ available }}', number_format($balance, 2))
                ->setParameter('{{ required }}', number_format($amount, 2))
                ->atPath('amount')
                ->addViolation();
        }
    }

    private function getTodayTotal(string $accountId): float
    {
        return 0.0;
    }

    private function getMonthTotal(string $accountId): float
    {
        return 0.0;
    }

    private function getAccountBalance(string $accountId): float
    {
        return 0.0;
    }
}
