<?php

declare(strict_types=1);

namespace Acme\Etl\Events;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final class EventNdjsonIngestor
{
    private const BATCH_SIZE = 1000;

    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function ingest(string $path): int
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("cannot open {$path}");
        }

        $buffer = [];
        $total = 0;

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $assoc = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $mapped = [
                'event_id' => (string) $assoc['id'],
                'type' => (string) $assoc['event_type'],
                'payload' => json_encode($assoc['data'] ?? [], JSON_THROW_ON_ERROR),
                'occurred_at' => new \DateTimeImmutable($assoc['ts']),
            ];
            $buffer[] = $mapped;

            if (count($buffer) >= self::BATCH_SIZE) {
                $this->flush($buffer);
                $total += count($buffer);
                $this->logger->info('event batch flushed', ['count' => count($buffer), 'total' => $total]);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $this->flush($buffer);
            $total += count($buffer);
        }

        fclose($handle);
        return $total;
    }

    /** @param list<array<string, mixed>> $rows */
    private function flush(array $rows): void
    {
        foreach ($rows as $r) {
            $this->db->insert('event_log', $r);
        }
    }
}
