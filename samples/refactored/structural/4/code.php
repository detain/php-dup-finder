<?php

declare(strict_types=1);

namespace Acme\Etl\Common;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final class BatchedIngestor
{
    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param iterable<array<string, mixed>> $records
     */
    public function ingest(string $table, iterable $records, int $batchSize, string $label): int
    {
        $buffer = [];
        $total = 0;

        foreach ($records as $row) {
            $buffer[] = $row;
            if (count($buffer) >= $batchSize) {
                $this->flush($table, $buffer);
                $total += count($buffer);
                $this->logger->info("{$label} batch flushed", ['count' => count($buffer), 'total' => $total]);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $this->flush($table, $buffer);
            $total += count($buffer);
        }

        return $total;
    }

    /** @param list<array<string, mixed>> $rows */
    private function flush(string $table, array $rows): void
    {
        foreach ($rows as $r) {
            $this->db->insert($table, $r);
        }
    }
}

// Each ingestor now becomes a generator that yields mapped rows,
// while BatchedIngestor owns the batching + flush + logging skeleton.
