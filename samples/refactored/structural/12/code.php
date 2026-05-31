<?php
declare(strict_types=1);

namespace DataExport\Shared;

interface ExportSerializer
{
    public function serialize(mixed $record): string;
    public function getHeaders(): array;
}

interface EntityQueryBuilder
{
    public function createQuery(string $entityClass): Query;
}

abstract class BaseExportPipeline
{
    protected LoggerInterface $logger;
    protected EntityQueryBuilder $queryBuilder;
    protected StorageAdapter $storageAdapter;

    private const CHUNK_SIZE = 200;

    public function export(string $outputPath, ExportFilters $filters): ExportResult
    {
        $this->logger->info("Starting {$this->getEntityType()} export pipeline", [
            'output' => $outputPath,
        ]);

        $query = $this->buildQuery($filters);
        $totalCount = $this->countRecords($query);

        $this->storageAdapter->initializeWrite($outputPath, $this->getEntityType() . '_export');
        $this->writeHeader($outputPath);

        $exportedCount = $this->processChunks($query, $outputPath);

        $this->storageAdapter->finalizeWrite($outputPath);

        $this->logger->info("{$this->getEntityType()} export pipeline completed", [
            'total' => $totalCount,
            'exported' => $exportedCount,
        ]);

        return new ExportResult(
            entityType: $this->getEntityType(),
            totalRecords: $totalCount,
            exportedRecords: $exportedCount,
            outputPath: $outputPath,
            startedAt: new \DateTimeImmutable(),
            completedAt: new \DateTimeImmutable(),
        );
    }

    protected function processChunks(Query $query, string $outputPath): int
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
                $this->storageAdapter->writeLine($outputPath, $this->serializeRecord($record));
                $exported++;
            }

            $offset += self::CHUNK_SIZE;

            if (count($records) < self::CHUNK_SIZE) {
                break;
            }
        }

        return $exported;
    }

    protected function escapeCsvField(string $field): string
    {
        if (str_contains($field, ',') || str_contains($field, '"') || str_contains($field, "\n")) {
            return '"' . str_replace('"', '""', $field) . '"';
        }
        return $field;
    }

    abstract protected function getEntityType(): string;
    abstract protected function buildQuery(ExportFilters $filters): Query;
    abstract protected function writeHeader(string $outputPath): void;
    abstract protected function serializeRecord(mixed $record): string;
}

final class CustomerExportPipeline extends BaseExportPipeline
{
    protected function getEntityType(): string
    {
        return 'customer';
    }

    protected function buildQuery(ExportFilters $filters): Query
    {
        $query = $this->queryBuilder->createQuery(Customer::class);

        if ($filters->status !== null) {
            $query->andWhere('status = :status')->setParameter('status', $filters->status);
        }

        $query->orderBy('createdAt', 'DESC');

        return $query;
    }

    protected function writeHeader(string $outputPath): void
    {
        $header = ['id', 'email', 'first_name', 'last_name', 'status', 'created_at'];
        $this->storageAdapter->writeLine($outputPath, implode(',', $header));
    }

    protected function serializeRecord(mixed $record): string
    {
        $data = [
            $record->getId(),
            $this->escapeCsvField($record->getEmail()),
            $this->escapeCsvField($record->getFirstName()),
            $this->escapeCsvField($record->getLastName()),
            $record->getStatus(),
            $record->getCreatedAt()->format('Y-m-d H:i:s'),
        ];

        return implode(',', $data);
    }
}
