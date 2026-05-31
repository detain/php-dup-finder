<?php
declare(strict_types=1);

namespace App\Banking\Service;

use App\Banking\Repository\AccountRepository;
use App\Banking\Entity\Account;
use App\Banking\Entity\Transaction;
use App\Banking\Exception\TransactionException;
use Psr\Log\LoggerInterface;

final class PaymentProcessingService
{
    private const DAILY_LIMIT_DEFAULT = 10000.00;
    private const DAILY_LIMIT_PREMIUM = 50000.00;
    private const SINGLE_TRANSACTION_LIMIT = 5000.00;
    private const MONTHLY_LIMIT_DEFAULT = 100000.00;

    private AccountRepository $accountRepo;
    private LoggerInterface $logger;

    public function __construct(
        AccountRepository $accountRepo,
        LoggerInterface $logger
    ) {
        $this->accountRepo = $accountRepo;
        $this->logger = $logger;
    }

    public function processPayment(string $accountId, float $amount, string $description): PaymentResult
    {
        $account = $this->accountRepo->findById($accountId);
        if ($account === null) {
            throw new TransactionException("Account not found: {$accountId}");
        }

        $this->validateSingleTransactionLimit($amount);

        $dailyLimit = $this->getDailyLimit($account);
        $todayTotal = $this->calculateDailyTotal($accountId);

        if (($todayTotal + $amount) > $dailyLimit) {
            throw new TransactionException(
                "Daily transfer limit of {$dailyLimit} exceeded. " .
                "Current daily total: {$todayTotal}, Attempted: {$amount}"
            );
        }

        $monthlyTotal = $this->calculateMonthlyTotal($accountId);
        $monthlyLimit = $this->getMonthlyLimit($account);

        if (($monthlyTotal + $amount) > $monthlyLimit) {
            throw new TransactionException(
                "Monthly transfer limit of {$monthlyLimit} exceeded"
            );
        }

        if (!$account->hasSufficientBalance($amount)) {
            throw new TransactionException(
                "Insufficient funds. Available: {$account->getAvailableBalance()}, Required: {$amount}"
            );
        }

        $transaction = $this->executeTransaction($account, $amount, $description);

        $this->logger->info('Payment processed successfully', [
            'account_id' => $accountId,
            'transaction_id' => $transaction->getId(),
            'amount' => $amount,
            'daily_total' => $todayTotal + $amount
        ]);

        return new PaymentResult([
            'success' => true,
            'transaction_id' => $transaction->getId(),
            'new_balance' => $account->getAvailableBalance() - $amount
        ]);
    }

    private function validateSingleTransactionLimit(float $amount): void
    {
        if ($amount > self::SINGLE_TRANSACTION_LIMIT) {
            throw new TransactionException(
                "Single transaction limit of " . self::SINGLE_TRANSACTION_LIMIT . " exceeded"
            );
        }

        if ($amount <= 0) {
            throw new TransactionException("Transaction amount must be positive");
        }
    }

    private function getDailyLimit(Account $account): float
    {
        return $account->isPremium() ? self::DAILY_LIMIT_PREMIUM : self::DAILY_LIMIT_DEFAULT;
    }

    private function getMonthlyLimit(Account $account): float
    {
        return self::MONTHLY_LIMIT_DEFAULT;
    }

    private function calculateDailyTotal(string $accountId): float
    {
        $startOfDay = (new \DateTimeImmutable())->setTime(0, 0, 0);
        return $this->accountRepo->getTransactionTotalSince($accountId, $startOfDay);
    }

    private function calculateMonthlyTotal(string $accountId): float
    {
        $startOfMonth = (new \DateTimeImmutable())->modify('first day of this month')->setTime(0, 0, 0);
        return $this->accountRepo->getTransactionTotalSince($accountId, $startOfMonth);
    }

    private function executeTransaction(Account $account, float $amount, string $description): Transaction
    {
        $transaction = Transaction::create([
            'account_id' => $account->getId(),
            'amount' => $amount,
            'type' => 'debit',
            'description' => $description,
            'status' => 'completed',
            'processed_at' => new \DateTimeImmutable()
        ]);

        return $this->accountRepo->saveTransaction($transaction);
    }
}
