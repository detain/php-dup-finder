<?php
declare(strict_types=1);

namespace App\Jobs\Cleanup;

use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class CleanupArchivedOrdersJob
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LoggerInterface $logger
    ) {
    }

    public function run(int $retentionDays = 90, int $batchSize = 1000): int
    {
        if ($retentionDays < 1) {
            throw new \InvalidArgumentException('retentionDays must be >= 1');
        }

        $total = 0;
        $sql = 'DELETE FROM orders
                WHERE status = :status
                  AND archived_at < (NOW() - INTERVAL :days DAY)
                LIMIT :batch';

        while (true) {
            try {
                $this->pdo->beginTransaction();
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindValue(':status', 'archived');
                $stmt->bindValue(':days', $retentionDays, PDO::PARAM_INT);
                $stmt->bindValue(':batch', $batchSize, PDO::PARAM_INT);
                $stmt->execute();
                $affected = $stmt->rowCount();
                $this->pdo->commit();
            } catch (PDOException $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $this->logger->error('Archived order cleanup failed', [
                    'error' => $e->getMessage(),
                ]);
                throw new RuntimeException('Order cleanup failed', 0, $e);
            }

            $total += $affected;
            if ($affected < $batchSize) {
                break;
            }
        }

        $this->logger->info('Archived order cleanup complete', ['removed' => $total]);
        return $total;
    }
}
