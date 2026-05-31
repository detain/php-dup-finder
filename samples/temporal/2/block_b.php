<?php
declare(strict_types=1);

namespace Banking\Ledger\Loans;

use PDO;
use Psr\Log\LoggerInterface;

final class LoanDisbursementService
{
    public function __construct(private PDO $pdo, private LoggerInterface $log) {}

    public function disburse(int $loanId, int $accountId, int $cents): string
    {
        $reference = 'LN-' . bin2hex(random_bytes(6));
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT status, principal_cents, disbursed_cents FROM loans WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $loanId]);
            $loan = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$loan || $loan['status'] !== 'approved') {
                throw new \DomainException('loan_not_approved');
            }
            $remaining = (int) $loan['principal_cents'] - (int) $loan['disbursed_cents'];
            if ($cents > $remaining) {
                throw new \DomainException('exceeds_principal');
            }
            $this->pdo->prepare('UPDATE loans SET disbursed_cents = disbursed_cents + :c WHERE id = :id')
                ->execute(['c' => $cents, 'id' => $loanId]);
            $this->pdo->prepare('UPDATE accounts SET balance_cents = balance_cents + :c WHERE id = :id')
                ->execute(['c' => $cents, 'id' => $accountId]);
            $this->pdo->prepare('INSERT INTO ledger (ref, src, dst, cents, memo, created_at) VALUES (?,?,?,?,?,NOW())')
                ->execute([$reference, 0, $accountId, $cents, "loan {$loanId}"]);
            $this->pdo->commit();
            $this->log->info('loan.disburse.ok', ['ref' => $reference]);
            return $reference;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $this->log->error('loan.disburse.fail', ['err' => $e->getMessage()]);
            throw $e;
        }
    }
}
