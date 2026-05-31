<?php
declare(strict_types=1);

namespace Acme\Inventory\Persistence;

final class InventoryAdjustmentRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function adjust(int $sku, int $delta, string $reason): void
    {
        if ($delta === 0) {
            return;
        }
        $sql = 'UPDATE inventory SET on_hand = on_hand + :delta WHERE sku_id = :sku';

        // ---- BEGIN copy-pasted deadlock-retry block ----
        $maxAttempts = 5;
        $attempt = 0;
        $lastError = null;
        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
                $this->pdo->beginTransaction();
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute(['delta' => $delta, 'sku' => $sku]);
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

        error_log("adjusted sku={$sku} delta={$delta} reason={$reason}");
    }
}
