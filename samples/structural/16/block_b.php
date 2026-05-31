<?php
declare(strict_types=1);

namespace DataProcessing\Pipeline;

use Psr\Log\LoggerInterface;

final class OrderDataPipeline
{
    private const BUFFER_SIZE = 1000;
    private const FLUSH_INTERVAL_SECONDS = 300;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly OrderValidator $validator,
        private readonly OrderTransformer $transformer,
        private readonly OrderRepository $repository,
    ) {}

    public function process(array $rawRecords): PipelineResult
    {
        $this->logger->info('Starting order data pipeline', [
            'record_count' => count($rawRecords),
        ]);

        $normalizedRecords = $this->normalize($rawRecords);
        $validatedRecords = $this->validate($normalizedRecords);
        $transformedRecords = $this->transform($validatedRecords);
        $enrichedRecords = $this->enrich($transformedRecords);
        $persistedRecords = $this->persist($enrichedRecords);

        $this->logger->info('Order data pipeline completed', [
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
                'order_id' => $this->normalizeIdentifier($record['order_id'] ?? null),
                'order_number' => $this->normalizeOrderNumber($record['order_number'] ?? ''),
                'customer_email' => strtolower(trim($record['customer_email'] ?? '')),
                'items' => $this->normalizeItems($record['items'] ?? []),
                'subtotal' => (float)($record['subtotal'] ?? 0),
                'tax' => (float)($record['tax'] ?? 0),
                'total' => (float)($record['total'] ?? 0),
                'currency' => strtoupper($record['currency'] ?? 'USD'),
                'status' => $this->normalizeStatus($record['status'] ?? 'pending'),
                'order_date' => $this->normalizeTimestamp($record['order_date'] ?? null),
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

            $enrichedRecord['customer_segment'] = $this->calculateCustomerSegment($record);
            $enrichedRecord['item_count'] = count($record['items']);
            $enrichedRecord['average_item_price'] = $this->calculateAverageItemPrice($record);
            $enrichedRecord['is_high_value'] = $record['total'] > 1000;
            $enrichedRecord['fulfillment_priority'] = $this->determineFulfillmentPriority($record);

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

    private function normalizeOrderNumber(string $orderNumber): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/', '', $orderNumber));
    }

    private function normalizeItems(array $items): array
    {
        return array_map(function ($item) {
            return [
                'sku' => strtoupper($item['sku'] ?? ''),
                'quantity' => (int)($item['quantity'] ?? 1),
                'unit_price' => (float)($item['unit_price'] ?? 0),
            ];
        }, $items);
    }

    private function normalizeStatus(string $status): string
    {
        $validStatuses = ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled'];

        return in_array($status, $validStatuses) ? $status : 'pending';
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

    private function calculateCustomerSegment(array $record): string
    {
        $email = $record['customer_email'] ?? '';
        $domain = explode('@', $email)[1] ?? '';

        if (in_array($domain, ['corporate.com', 'enterprise.com'])) {
            return 'b2b';
        }

        return 'b2c';
    }

    private function calculateAverageItemPrice(array $record): float
    {
        $items = $record['items'] ?? [];

        if (count($items) === 0) {
            return 0.0;
        }

        $total = array_sum(array_column($items, 'unit_price'));

        return $total / count($items);
    }

    private function determineFulfillmentPriority(array $record): string
    {
        if ($record['total'] > 5000) {
            return 'critical';
        }

        if ($record['total'] > 1000) {
            return 'high';
        }

        return 'normal';
    }

    private function gatherMetrics(array $records): array
    {
        $totalValue = array_sum(array_column($records, 'total'));
        $highValueCount = count(array_filter($records, fn($r) => $r['is_high_value'] ?? false));

        return [
            'total_value' => $totalValue,
            'high_value_orders' => $highValueCount,
            'average_order_value' => count($records) > 0 ? $totalValue / count($records) : 0,
        ];
    }
}
