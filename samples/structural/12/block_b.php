<?php
declare(strict_types=1);

namespace DataExport\Pipeline;

use Psr\Log\LoggerInterface;

final class OrderExportPipeline
{
    private const CHUNK_SIZE = 200;
    private const MAX_WORKERS = 4;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly OrderQueryBuilder $queryBuilder,
        private readonly OrderSerializer $serializer,
        private readonly StorageAdapter $storageAdapter,
    ) {}

    public function export(string $outputPath, ExportFilters $filters): ExportResult
    {
        $this->logger->info('Starting order export pipeline', [
            'output' => $outputPath,
            'filters' => $filters,
        ]);

        $query = $this->buildQuery($filters);
        $totalCount = $this->countRecords($query);
        $this->logger->debug('Total records to export', ['count' => $totalCount]);

        $this->storageAdapter->initializeWrite($outputPath, 'order_export');
        $this->writeHeader($outputPath);

        $exportedCount = $this->processChunks($query, $outputPath);
        $this->storageAdapter->finalizeWrite($outputPath);

        $this->logger->info('Order export pipeline completed', [
            'total' => $totalCount,
            'exported' => $exportedCount,
        ]);

        return new ExportResult(
            entityType: 'order',
            totalRecords: $totalCount,
            exportedRecords: $exportedCount,
            outputPath: $outputPath,
            startedAt: new \DateTimeImmutable(),
            completedAt: new \DateTimeImmutable(),
        );
    }

    private function buildQuery(ExportFilters $filters): Query
    {
        $query = $this->queryBuilder->createQuery(Order::class);

        if ($filters->status !== null) {
            $query->andWhere('status = :status')->setParameter('status', $filters->status);
        }

        if ($filters->createdAfter !== null) {
            $query->andWhere('orderDate >= :after')
                ->setParameter('after', $filters->createdAfter);
        }

        if ($filters->createdBefore !== null) {
            $query->andWhere('orderDate <= :before')
                ->setParameter('before', $filters->createdBefore);
        }

        if ($filters->minAmount !== null) {
            $query->andWhere('total >= :minAmount')->setParameter('minAmount', $filters->minAmount);
        }

        if ($filters->maxAmount !== null) {
            $query->andWhere('total <= :maxAmount')->setParameter('maxAmount', $filters->maxAmount);
        }

        $query->orderBy('orderDate', 'DESC');

        return $query;
    }

    private function countRecords(Query $query): int
    {
        $countQuery = clone $query;
        $countQuery->select('COUNT(*)');

        return (int) $countQuery->getSingleScalarResult();
    }

    private function writeHeader(string $outputPath): void
    {
        $header = ['order_id', 'order_number', 'customer_email', 'item_count', 'subtotal', 'tax', 'total', 'status', 'order_date'];
        $this->storageAdapter->writeLine($outputPath, implode(',', $header));
    }

    private function processChunks(Query $query, string $outputPath): int
    {
        $offset = 0;
        $exported = 0;

        while (true) {
            $chunkQuery = clone $query;
            $chunkQuery->setMaxResults(self::CHUNK_SIZE);
            $chunkQuery->setFirstResult($offset);

            $records = $chunkQuery->getResult();

            if (empty($records)) {
                break;
            }

            foreach ($records as $record) {
                $row = $this->serializeRecord($record);
                $this->storageAdapter->writeLine($outputPath, $row);
                $exported++;
            }

            $this->logger->debug('Chunk processed', [
                'offset' => $offset,
                'chunk_size' => count($records),
            ]);

            $offset += self::CHUNK_SIZE;

            if (count($records) < self::CHUNK_SIZE) {
                break;
            }
        }

        return $exported;
    }

    private function serializeRecord(Order $order): string
    {
        $data = [
            $order->getId(),
            $this->escapeCsvField($order->getOrderNumber()),
            $this->escapeCsvField($order->getCustomerEmail()),
            $order->getItemCount(),
            number_format($order->getSubtotal(), 2, '.', ''),
            number_format($order->getTax(), 2, '.', ''),
            number_format($order->getTotal(), 2, '.', ''),
            $order->getStatus(),
            $order->getOrderDate()->format('Y-m-d H:i:s'),
        ];

        return implode(',', $data);
    }

    private function escapeCsvField(string $field): string
    {
        if (str_contains($field, ',') || str_contains($field, '"') || str_contains($field, "\n")) {
            return '"' . str_replace('"', '""', $field) . '"';
        }

        return $field;
    }
}
