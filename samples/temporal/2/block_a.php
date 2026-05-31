<?php
declare(strict_types=1);

namespace Banking\Ledger\Transfers;

use PDO;
use Psr\Log\LoggerInterface;

final class FundsTransferService
{
    public function __construct(private PDO $pdo, private LoggerInterface $log) {}

    public function transfer(int $fromAccount, int $toAccount, int $cents, string $memo): string
    {
        $reference = 'TX-' . bin2hex(random_bytes(6));
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT balance_cents FROM accounts WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $fromAccount]);
            $balance = (int) $stmt->fetchColumn();
            if ($balance < $cents) {
                throw new \DomainException('insufficient_funds');
            }
            $this->pdo->prepare('UPDATE accounts SET balance_cents = balance_cents - :c WHERE id = :id')
                ->execute(['c' => $cents, 'id' => $fromAccount]);
            $this->pdo->prepare('UPDATE accounts SET balance_cents = balance_cents + :c WHERE id = :id')
                ->execute(['c' => $cents, 'id' => $toAccount]);
            $this->pdo->prepare('INSERT INTO ledger (ref, src, dst, cents, memo, created_at) VALUES (?,?,?,?,?,NOW())')
                ->execute([$reference, $fromAccount, $toAccount, $cents, $memo]);
            $this->pdo->commit();
            $this->log->info('transfer.ok', ['ref' => $reference]);
            return $reference;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $this->log->error('transfer.fail', ['err' => $e->getMessage()]);
            throw $e;
        }
    }
}
