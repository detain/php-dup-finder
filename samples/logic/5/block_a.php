<?php

declare(strict_types=1);

namespace App\Banking;

use App\Entity\BankAccount;
use App\Repository\BankAccountRepository;
use App\Service\TransactionLogger;
use Psr\Log\LoggerInterface;

final class BankTransferService
{
    public function __construct(
        private readonly BankAccountRepository $accountRepository,
        private readonly TransactionLogger $transactionLogger,
        private readonly LoggerInterface $logger,
    ) {}

    public function initiateTransfer(int $fromAccountId, int $toAccountId, int $amount): array
    {
        $fromAccount = $this->accountRepository->findById($fromAccountId);
        $toAccount = $this->accountRepository->findById($toAccountId);

        if ($fromAccount === null || $toAccount === null) {
            throw new \RuntimeException('One or both accounts not found');
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Transfer amount must be positive');
        }

        if ($amount > 1000000) {
            throw new \InvalidArgumentException('Single transfers cannot exceed 1,000,000');
        }

        if ($amount > 500000 && !$fromAccount->isVerified()) {
            throw new \InvalidArgumentException('Transfers over 500,000 require verified account');
        }

        if ($amount > 250000 && $fromAccount->getRiskScore() > 50) {
            throw new \InvalidArgumentException('High-risk accounts have reduced transfer limits');
        }

        if ($fromAccount->isLocked()) {
            throw new \InvalidArgumentException('Account is locked');
        }

        if ($fromAccount->isSuspended()) {
            throw new \InvalidArgumentException('Account is suspended');
        }

        if ($fromAccount->getBalance() < $amount) {
            throw new \InvalidArgumentException('Insufficient funds');
        }

        if ($fromAccount->getDailyTransferTotal() + $amount > $fromAccount->getDailyLimit()) {
            throw new \InvalidArgumentException('Daily transfer limit exceeded');
        }

        if ($fromAccount->getMonthlyTransferTotal() + $amount > $fromAccount->getMonthlyLimit()) {
            throw new \InvalidArgumentException('Monthly transfer limit exceeded');
        }

        if ($fromAccount->getStatus() !== 'active') {
            throw new \InvalidArgumentException('Account must be active to initiate transfers');
        }

        if ($toAccount->getStatus() !== 'active') {
            throw new \InvalidArgumentException('Recipient account must be active');
        }

        $fromAccount->setBalance($fromAccount->getBalance() - $amount);
        $fromAccount->setDailyTransferTotal($fromAccount->getDailyTransferTotal() + $amount);
        $fromAccount->setMonthlyTransferTotal($fromAccount->getMonthlyTransferTotal() + $amount);

        $toAccount->setBalance($toAccount->getBalance() + $amount);

        $transactionId = $this->transactionLogger->logTransfer([
            'from_account' => $fromAccountId,
            'to_account' => $toAccountId,
            'amount' => $amount,
            'timestamp' => new \DateTimeImmutable(),
        ]);

        $this->accountRepository->save($fromAccount);
        $this->accountRepository->save($toAccount);

        $this->logger->info('Transfer initiated', [
            'transaction_id' => $transactionId,
            'from' => $fromAccountId,
            'to' => $toAccountId,
            'amount' => $amount,
        ]);

        return [
            'transaction_id' => $transactionId,
            'from_account' => $fromAccountId,
            'to_account' => $toAccountId,
            'amount' => $amount,
            'new_balance' => $fromAccount->getBalance(),
        ];
    }

    public function reverseTransfer(int $transactionId): bool
    {
        $transaction = $this->transactionLogger->findTransaction($transactionId);

        if ($transaction === null) {
            throw new \RuntimeException('Transaction not found');
        }

        if (!$transaction->isReversible()) {
            throw new \InvalidArgumentException('Transaction is not reversible');
        }

        if ($transaction->getReversedAt() !== null) {
            throw new \InvalidArgumentException('Transaction has already been reversed');
        }

        if ($transaction->getAmount() > 500000 && !$transaction->isVerified()) {
            throw new \InvalidArgumentException('Reversals over 500,000 require additional verification');
        }

        $fromAccount = $this->accountRepository->findById($transaction->getFromAccountId());
        $toAccount = $this->accountRepository->findById($transaction->getToAccountId());

        if ($fromAccount === null || $toAccount === null) {
            throw new \RuntimeException('Account(s) not found');
        }

        if ($toAccount->getBalance() < $transaction->getAmount()) {
            throw new \InvalidArgumentException('Insufficient funds in recipient account');
        }

        $toAccount->setBalance($toAccount->getBalance() - $transaction->getAmount());
        $fromAccount->setBalance($fromAccount->getBalance() + $transaction->getAmount());

        $transaction->setReversedAt(new \DateTimeImmutable());
        $transactionLogger->save($transaction);

        $this->accountRepository->save($fromAccount);
        $this->accountRepository->save($toAccount);

        $this->logger->info('Transfer reversed', [
            'transaction_id' => $transactionId,
        ]);

        return true;
    }
}
