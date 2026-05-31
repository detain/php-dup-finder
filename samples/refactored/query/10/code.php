<?php
declare(strict_types=1);

namespace App\Jobs\Cleanup;

use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ArchivedRecordCleanup
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LoggerInterface $logger
    ) {
    }

    public function purge(string $table, int $retentionDays, int $batchSize, bool $dryRun = false): int
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException("Invalid table: {$table}");
        }
        if ($retentionDays < 1 || $batchSize < 1) {
            throw new \InvalidArgumentException('retentionDays and batchSize must be >= 1');
        }

        $sql = $dryRun
            ? "SELECT COUNT(*) AS n FROM {$table} WHERE status = :status AND archived_at < (NOW() - INTERVAL :days DAY) LIMIT :batch"
            : "DELETE FROM {$table} WHERE status = :status AND archived_at < (NOW() - INTERVAL :days DAY) LIMIT :batch";

        $total = 0;
        while (true) {
            try {
                $this->pdo->beginTransaction();
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindValue(':status', 'archived');
                $stmt->bindValue(':days', $retentionDays, PDO::PARAM_INT);
                $stmt->bindValue(':batch', $batchSize, PDO::PARAM_INT);
                $stmt->execute();
                $affected = $dryRun
                    ? (int) ($stmt->fetch(PDO::FETCH_ASSOC)['n'] ?? 0)
                    : $stmt->rowCount();
                $this->pdo->commit();
            } catch (PDOException $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $this->logger->error('Archived record cleanup failed', [
                    'table' => $table,
                    'error' => $e->getMessage(),
                ]);
                throw new RuntimeException("Cleanup failed for {$table}", 0, $e);
            }

            $total += $affected;
            if ($affected < $batchSize || $dryRun) {
                break;
            }
        }

        $this->logger->info('Archived record cleanup complete', [
            'table' => $table, 'removed' => $total, 'dry_run' => $dryRun,
        ]);
        return $total;
    }
}
