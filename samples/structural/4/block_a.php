<?php

declare(strict_types=1);

namespace Acme\Etl\Customers;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final class CustomerCsvIngestor
{
    private const BATCH_SIZE = 500;

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

        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);
            return 0;
        }

        $buffer = [];
        $total = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $assoc = array_combine($headers, $row);
            $mapped = [
                'external_id' => trim((string) $assoc['id']),
                'email' => strtolower(trim((string) $assoc['email'])),
                'name' => trim((string) $assoc['full_name']),
                'created_at' => new \DateTimeImmutable($assoc['created']),
            ];
            $buffer[] = $mapped;

            if (count($buffer) >= self::BATCH_SIZE) {
                $this->flush($buffer);
                $total += count($buffer);
                $this->logger->info('customer batch flushed', ['count' => count($buffer), 'total' => $total]);
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
            $this->db->insert('customer', $r);
        }
    }
}
