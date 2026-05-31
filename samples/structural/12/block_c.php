<?php
declare(strict_types=1);

namespace DataExport\Pipeline;

use Psr\Log\LoggerInterface;

final class ProductExportPipeline
{
    private const CHUNK_SIZE = 200;
    private const MAX_WORKERS = 4;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ProductQueryBuilder $queryBuilder,
        private readonly ProductSerializer $serializer,
        private readonly StorageAdapter $storageAdapter,
    ) {}

    public function export(string $outputPath, ExportFilters $filters): ExportResult
    {
        $this->logger->info('Starting product export pipeline', [
            'output' => $outputPath,
            'filters' => $filters,
        ]);

        $query = $this->buildQuery($filters);
        $totalCount = $this->countRecords($query);
        $this->logger->debug('Total records to export', ['count' => $totalCount]);

        $this->storageAdapter->initializeWrite($outputPath, 'product_export');
        $this->writeHeader($outputPath);

        $exportedCount = $this->processChunks($query, $outputPath);
        $this->storageAdapter->finalizeWrite($outputPath);

        $this->logger->info('Product export pipeline completed', [
            'total' => $totalCount,
            'exported' => $exportedCount,
        ]);

        return new ExportResult(
            entityType: 'product',
            totalRecords: $totalCount,
            exportedRecords: $exportedCount,
            outputPath: $outputPath,
            startedAt: new \DateTimeImmutable(),
            completedAt: new \DateTimeImmutable(),
        );
    }

    private function buildQuery(ExportFilters $filters): Query
    {
        $query = $this->queryBuilder->createQuery(Product::class);

        if ($filters->status !== null) {
            $query->andWhere('status = :status')->setParameter('status', $filters->status);
        }

        if ($filters->createdAfter !== null) {
            $query->andWhere('createdAt >= :after')
                ->setParameter('after', $filters->createdAfter);
        }

        if ($filters->createdBefore !== null) {
            $query->andWhere('createdAt <= :before')
                ->setParameter('before', $filters->createdBefore);
        }

        if ($filters->category !== null) {
            $query->andWhere('category = :category')->setParameter('category', $filters->category);
        }

        if ($filters->minPrice !== null) {
            $query->andWhere('price >= :minPrice')->setParameter('minPrice', $filters->minPrice);
        }

        if ($filters->maxPrice !== null) {
            $query->andWhere('price <= :maxPrice')->setParameter('maxPrice', $filters->maxPrice);
        }

        $query->orderBy('createdAt', 'DESC');

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
        $header = ['id', 'sku', 'name', 'category', 'price', 'cost', 'stock_quantity', 'status', 'created_at'];
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

    private function serializeRecord(Product $product): string
    {
        $data = [
            $product->getId(),
            $this->escapeCsvField($product->getSku()),
            $this->escapeCsvField($product->getName()),
            $this->escapeCsvField($product->getCategory()),
            number_format($product->getPrice(), 2, '.', ''),
            number_format($product->getCost(), 2, '.', ''),
            $product->getStockQuantity(),
            $product->getStatus(),
            $product->getCreatedAt()->format('Y-m-d H:i:s'),
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
