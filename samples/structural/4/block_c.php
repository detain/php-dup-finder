<?php

declare(strict_types=1);

namespace Acme\Etl\Products;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final class ProductXmlIngestor
{
    private const BATCH_SIZE = 250;

    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function ingest(string $path): int
    {
        $reader = new \XMLReader();
        if (!$reader->open($path)) {
            throw new \RuntimeException("cannot open {$path}");
        }

        $buffer = [];
        $total = 0;

        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->name !== 'product') {
                continue;
            }
            $node = simplexml_load_string($reader->readOuterXml());
            $mapped = [
                'sku' => (string) $node->sku,
                'name' => (string) $node->name,
                'price_cents' => (int) ((float) $node->price * 100),
                'imported_at' => new \DateTimeImmutable(),
            ];
            $buffer[] = $mapped;

            if (count($buffer) >= self::BATCH_SIZE) {
                $this->flush($buffer);
                $total += count($buffer);
                $this->logger->info('product batch flushed', ['count' => count($buffer), 'total' => $total]);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $this->flush($buffer);
            $total += count($buffer);
        }

        $reader->close();
        return $total;
    }

    /** @param list<array<string, mixed>> $rows */
    private function flush(array $rows): void
    {
        foreach ($rows as $r) {
            $this->db->insert('product', $r);
        }
    }
}
