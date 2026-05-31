<?php
declare(strict_types=1);

namespace Acme\Persistence;

final class DeadlockRetry
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly int $maxAttempts = 5,
    ) {
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function run(callable $work): mixed
    {
        $attempt = 0;
        $lastError = null;
        while ($attempt < $this->maxAttempts) {
            $attempt++;
            try {
                $this->pdo->beginTransaction();
                $result = $work();
                $this->pdo->commit();
                return $result;
            } catch (\PDOException $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $code = (int) ($e->errorInfo[1] ?? 0);
                if ($code !== 1213 && $code !== 1205) {
                    throw $e;
                }
                $lastError = $e;
                usleep((int) (50_000 * (2 ** ($attempt - 1)) + random_int(0, 25_000)));
            }
        }
        throw new \RuntimeException("Exceeded deadlock retries", 0, $lastError);
    }
}

final class InventoryAdjustmentRepository
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly DeadlockRetry $retry,
    ) {
    }

    public function adjust(int $sku, int $delta, string $reason): void
    {
        if ($delta === 0) {
            return;
        }
        $this->retry->run(function () use ($sku, $delta): void {
            $stmt = $this->pdo->prepare('UPDATE inventory SET on_hand = on_hand + :delta WHERE sku_id = :sku');
            $stmt->execute(['delta' => $delta, 'sku' => $sku]);
        });
        error_log("adjusted sku={$sku} delta={$delta} reason={$reason}");
    }
}
