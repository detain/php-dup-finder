<?php
declare(strict_types=1);

namespace Acme\Wallet\Persistence;

final class BalanceTransferRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function transfer(int $fromAccountId, int $toAccountId, int $amountCents): void
    {
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException("amount must be positive");
        }
        $sql = 'CALL apply_transfer(:from, :to, :amt)';

        // ---- BEGIN copy-pasted deadlock-retry block ----
        $maxAttempts = 5;
        $attempt = 0;
        $lastError = null;
        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
                $this->pdo->beginTransaction();
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute(['from' => $fromAccountId, 'to' => $toAccountId, 'amt' => $amountCents]);
                $this->pdo->commit();
                $lastError = null;
                break;
            } catch (\PDOException $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $code = (int) ($e->errorInfo[1] ?? 0);
                if ($code !== 1213 && $code !== 1205) {
                    throw $e;
                }
                $lastError = $e;
                $sleepMicros = (int) (50_000 * (2 ** ($attempt - 1)) + random_int(0, 25_000));
                usleep($sleepMicros);
            }
        }
        if ($lastError !== null) {
            throw new \RuntimeException("Exceeded deadlock retries", 0, $lastError);
        }
        // ---- END copy-pasted deadlock-retry block ----
    }
}
