<?php
declare(strict_types=1);

namespace DataProcessing\Pipeline;

use Psr\Log\LoggerInterface;

final class ProductDataPipeline
{
    private const BUFFER_SIZE = 1000;
    private const FLUSH_INTERVAL_SECONDS = 300;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ProductValidator $validator,
        private readonly ProductTransformer $transformer,
        private readonly ProductRepository $repository,
    ) {}

    public function process(array $rawRecords): PipelineResult
    {
        $this->logger->info('Starting product data pipeline', [
            'record_count' => count($rawRecords),
        ]);

        $normalizedRecords = $this->normalize($rawRecords);
        $validatedRecords = $this->validate($normalizedRecords);
        $transformedRecords = $this->transform($validatedRecords);
        $enrichedRecords = $this->enrich($transformedRecords);
        $persistedRecords = $this->persist($enrichedRecords);

        $this->logger->info('Product data pipeline completed', [
            'processed' => count($persistedRecords),
            'failed' => count($rawRecords) - count($persistedRecords),
        ]);

        return new PipelineResult(
            inputCount: count($rawRecords),
            outputCount: count($persistedRecords),
            failedCount: count($rawRecords) - count($persistedRecords),
            metrics: $this->gatherMetrics($persistedRecords),
        );
    }

    private function normalize(array $rawRecords): array
    {
        $normalized = [];

        foreach ($rawRecords as $record) {
            $cleanRecord = [
                'product_id' => $this->normalizeIdentifier($record['product_id'] ?? null),
                'sku' => strtoupper(trim($record['sku'] ?? '')),
                'name' => $this->normalizeName($record['name'] ?? ''),
                'description' => trim($record['description'] ?? ''),
                'price' => (float)($record['price'] ?? 0),
                'cost' => (float)($record['cost'] ?? 0),
                'category' => $this->normalizeCategory($record['category'] ?? ''),
                'inventory_count' => (int)($record['inventory_count'] ?? 0),
                'status' => $this->normalizeStatus($record['status'] ?? 'draft'),
                'created_at' => $this->normalizeTimestamp($record['created_at'] ?? null),
            ];

            $normalized[] = $cleanRecord;
        }

        return $normalized;
    }

    private function validate(array $records): array
    {
        $validRecords = [];
        $errors = [];

        foreach ($records as $index => $record) {
            $violations = $this->validator->validate($record);

            if (count($violations) > 0) {
                $errors[$index] = $violations;
                continue;
            }

            $validRecords[$index] = $record;
        }

        $this->logger->debug('Validation completed', [
            'valid' => count($validRecords),
            'invalid' => count($errors),
        ]);

        return $validRecords;
    }

    private function transform(array $records): array
    {
        $transformed = [];

        foreach ($records as $record) {
            $transformed[] = $this->transformer->transform($record);
        }

        return $transformed;
    }

    private function enrich(array $records): array
    {
        $enriched = [];

        foreach ($records as $record) {
            $enrichedRecord = $record;

            $enrichedRecord['margin'] = $this->calculateMargin($record);
            $enrichedRecord['margin_percent'] = $this->calculateMarginPercent($record);
            $enrichedRecord['inventory_value'] = $record['inventory_count'] * $record['cost'];
            $enrichedRecord['is_low_stock'] = $record['inventory_count'] < 10;
            $enrichedRecord['is_profitable'] = $record['margin'] > 0;
            $enrichedRecord['stock_status'] = $this->determineStockStatus($record);

            $enriched[] = $enrichedRecord;
        }

        return $enriched;
    }

    private function persist(array $records): array
    {
        $persisted = [];
        $buffer = [];
        $lastFlush = time();

        foreach ($records as $record) {
            $buffer[] = $record;

            if (count($buffer) >= self::BUFFER_SIZE || (time() - $lastFlush) >= self::FLUSH_INTERVAL_SECONDS) {
                $this->flushBuffer($buffer);
                $persisted = array_merge($persisted, $buffer);
                $buffer = [];
                $lastFlush = time();
            }
        }

        if (count($buffer) > 0) {
            $this->flushBuffer($buffer);
            $persisted = array_merge($persisted, $buffer);
        }

        return $persisted;
    }

    private function flushBuffer(array $buffer): void
    {
        foreach ($buffer as $record) {
            try {
                $this->repository->upsert($record);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to persist record', [
                    'error' => $e->getMessage(),
                    'record' => $record,
                ]);
            }
        }
    }

    private function normalizeIdentifier(?string $id): ?string
    {
        if ($id === null || $id === '') {
            return bin2hex(random_bytes(8));
        }

        return preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    }

    private function normalizeName(string $name): string
    {
        return ucwords(strtolower(trim($name)));
    }

    private function normalizeCategory(string $category): string
    {
        return ucwords(strtolower(trim($category)));
    }

    private function normalizeStatus(string $status): string
    {
        $validStatuses = ['draft', 'active', 'discontinued', 'out_of_stock'];

        return in_array($status, $validStatuses) ? $status : 'draft';
    }

    private function normalizeTimestamp(?string $timestamp): \DateTimeImmutable
    {
        if ($timestamp === null) {
            return new \DateTimeImmutable();
        }

        try {
            return new \DateTimeImmutable($timestamp);
        } catch (\Throwable) {
            return new \DateTimeImmutable();
        }
    }

    private function calculateMargin(array $record): float
    {
        return $record['price'] - $record['cost'];
    }

    private function calculateMarginPercent(array $record): float
    {
        if ($record['price'] <= 0) {
            return 0.0;
        }

        return (($record['price'] - $record['cost']) / $record['price']) * 100;
    }

    private function determineStockStatus(array $record): string
    {
        $count = $record['inventory_count'];

        if ($count === 0) {
            return 'out_of_stock';
        }

        if ($count < 10) {
            return 'low_stock';
        }

        if ($count < 50) {
            return 'medium_stock';
        }

        return 'in_stock';
    }

    private function gatherMetrics(array $records): array
    {
        $totalInventoryValue = array_sum(array_column($records, 'inventory_value'));
        $lowStockCount = count(array_filter($records, fn($r) => $r['is_low_stock'] ?? false));
        $avgMargin = count($records) > 0
            ? array_sum(array_column($records, 'margin')) / count($records)
            : 0;

        return [
            'total_inventory_value' => $totalInventoryValue,
            'low_stock_products' => $lowStockCount,
            'average_margin' => $avgMargin,
        ];
    }
}
