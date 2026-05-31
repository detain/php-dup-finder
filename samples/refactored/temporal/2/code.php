<?php
declare(strict_types=1);

namespace Banking\Ledger;

use PDO;
use Psr\Log\LoggerInterface;

final class TransactionRunner
{
    public function __construct(private PDO $pdo, private LoggerInterface $log) {}

    /**
     * @template T
     * @param callable(PDO):T $work
     * @return T
     */
    public function withTransaction(string $opName, callable $work)
    {
        $this->pdo->beginTransaction();
        try {
            $result = $work($this->pdo);
            $this->pdo->commit();
            $this->log->info("{$opName}.ok");
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $this->log->error("{$opName}.fail", ['err' => $e->getMessage()]);
            throw $e;
        }
    }
}

final class FundsTransferService
{
    public function __construct(private TransactionRunner $tx) {}

    public function transfer(int $from, int $to, int $cents, string $memo): string
    {
        return $this->tx->withTransaction('transfer', function (PDO $pdo) use ($from, $to, $cents, $memo) {
            $ref = 'TX-' . bin2hex(random_bytes(6));
            $stmt = $pdo->prepare('SELECT balance_cents FROM accounts WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $from]);
            if ((int) $stmt->fetchColumn() < $cents) {
                throw new \DomainException('insufficient_funds');
            }
            $pdo->prepare('UPDATE accounts SET balance_cents = balance_cents - :c WHERE id = :id')->execute(['c' => $cents, 'id' => $from]);
            $pdo->prepare('UPDATE accounts SET balance_cents = balance_cents + :c WHERE id = :id')->execute(['c' => $cents, 'id' => $to]);
            $pdo->prepare('INSERT INTO ledger (ref, src, dst, cents, memo, created_at) VALUES (?,?,?,?,?,NOW())')
                ->execute([$ref, $from, $to, $cents, $memo]);
            return $ref;
        });
    }
}
