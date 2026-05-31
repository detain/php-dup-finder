<?php
declare(strict_types=1);

namespace DataImport\Pipeline;

use Psr\Log\LoggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class OrderImportPipeline
{
    private const BATCH_SIZE = 100;
    private const MAX_RETRIES = 3;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ValidatorInterface $validator,
        private readonly OrderRepository $orderRepository,
        private readonly OrderEventDispatcher $eventDispatcher,
    ) {}

    public function process(string $filePath): ImportResult
    {
        $this->logger->info('Starting order import pipeline', ['file' => $filePath]);

        $rawRecords = $this->extract($filePath);
        $this->logger->debug('Extracted raw records', ['count' => count($rawRecords)]);

        $validatedRecords = $this->validate($rawRecords);
        $this->logger->debug('Validated records', ['count' => count($validatedRecords)]);

        $transformedRecords = $this->transform($validatedRecords);
        $this->logger->debug('Transformed records', ['count' => count($transformedRecords)]);

        $enrichedRecords = $this->enrich($transformedRecords);
        $this->logger->debug('Enriched records', ['count' => count($enrichedRecords)]);

        $persistedRecords = $this->persist($enrichedRecords);
        $this->logger->info('Persisted records', ['count' => count($persistedRecords)]);

        $this->notify($persistedRecords);
        $this->logger->info('Order import pipeline completed');

        return new ImportResult(
            totalRecords: count($rawRecords),
            successfulRecords: count($persistedRecords),
            failedRecords: count($rawRecords) - count($persistedRecords),
            errors: $this->gatherErrors(),
        );
    }

    private function extract(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open file: {$filePath}");
        }

        $headers = fgetcsv($handle);
        if ($headers === false) {
            throw new \RuntimeException('Cannot read CSV headers');
        }

        $records = [];
        while (($row = fgetcsv($handle)) !== false) {
            $record = array_combine($headers, $row);
            $records[] = $record;
        }
        fclose($handle);

        return $records;
    }

    private function validate(array $records): array
    {
        $validated = [];
        $errors = [];

        foreach ($records as $index => $record) {
            $orderDto = $this->mapToOrderDto($record);
            $violations = $this->validator->validate($orderDto);

            if (count($violations) > 0) {
                $errorMessages = [];
                foreach ($violations as $violation) {
                    $errorMessages[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
                }
                $errors[$index] = $errorMessages;
                continue;
            }

            $validated[$index] = $orderDto;
        }

        $this->logger->debug('Validation completed', [
            'valid' => count($validated),
            'invalid' => count($errors),
        ]);

        return $validated;
    }

    private function transform(array $validatedRecords): array
    {
        $transformed = [];

        foreach ($validatedRecords as $index => $orderDto) {
            $transformed[$index] = new TransformedOrderRecord(
                orderNumber: strtoupper(trim($orderDto->orderNumber)),
                customerEmail: strtolower(trim($orderDto->customerEmail)),
                items: $this->parseItems($orderDto->items),
                subtotal: (float) $orderDto->subtotal,
                tax: (float) ($orderDto->tax ?? 0),
                total: (float) $orderDto->total,
                createdAt: new \DateTimeImmutable($orderDto->orderDate ?? 'now'),
                status: 'pending',
            );
        }

        return $transformed;
    }

    private function enrich(array $transformedRecords): array
    {
        $enriched = [];

        foreach ($transformedRecords as $index => $record) {
            $enriched[$index] = new EnrichedOrderRecord(
                original: $record,
                customerTier: $this->calculateCustomerTier($record->customerEmail),
                fulfillmentPriority: $this->determineFulfillmentPriority($record),
                shippingMethod: $this->selectShippingMethod($record),
                fraudRiskScore: $this->assessFraudRisk($record),
            );
        }

        return $enriched;
    }

    private function persist(array $enrichedRecords): array
    {
        $persisted = [];
        $batches = array_chunk($enrichedRecords, self::BATCH_SIZE, true);

        foreach ($batches as $batchIndex => $batch) {
            $this->logger->debug('Processing batch', [
                'batch' => $batchIndex,
                'size' => count($batch),
            ]);

            foreach ($batch as $index => $record) {
                $attempts = 0;
                $success = false;

                while ($attempts < self::MAX_RETRIES && !$success) {
                    try {
                        $order = new Order(
                            orderNumber: $record->original->orderNumber,
                            customerEmail: $record->original->customerEmail,
                            items: $record->original->items,
                            subtotal: $record->original->subtotal,
                            tax: $record->original->tax,
                            total: $record->original->total,
                            status: $record->original->status,
                            customerTier: $record->customerTier,
                            fulfillmentPriority: $record->fulfillmentPriority,
                        );

                        $this->orderRepository->save($order);
                        $persisted[$index] = $record;
                        $success = true;
                    } catch (\Throwable $e) {
                        $attempts++;
                        $this->logger->warning('Persist attempt failed', [
                            'index' => $index,
                            'attempt' => $attempts,
                            'error' => $e->getMessage(),
                        ]);

                        if ($attempts >= self::MAX_RETRIES) {
                            $this->recordError($index, 'persist_failed: ' . $e->getMessage());
                        }
                    }
                }
            }
        }

        return $persisted;
    }

    private function notify(array $persistedRecords): void
    {
        foreach ($persistedRecords as $record) {
            $this->eventDispatcher->dispatch(
                new OrderImportedEvent(
                    orderNumber: $record->original->orderNumber,
                    customerEmail: $record->original->customerEmail,
                    total: $record->original->total,
                    priority: $record->fulfillmentPriority,
                    importedAt: $record->original->createdAt,
                )
            );
        }
    }

    private function mapToOrderDto(array $record): OrderImportDto
    {
        return new OrderImportDto(
            orderNumber: $record['order_number'] ?? '',
            customerEmail: $record['customer_email'] ?? '',
            items: $record['items'] ?? '',
            subtotal: $record['subtotal'] ?? '0',
            tax: $record['tax'] ?? '0',
            total: $record['total'] ?? '0',
            orderDate: $record['order_date'] ?? null,
        );
    }

    private function parseItems(string $itemsJson): array
    {
        $items = json_decode($itemsJson, true);
        return is_array($items) ? $items : [];
    }

    private function calculateCustomerTier(string $email): string
    {
        $domain = explode('@', $email)[1] ?? '';

        if (in_array($domain, ['vip.example.com', 'premium.example.com'])) {
            return 'vip';
        }

        if (str_contains($domain, 'corporate')) {
            return 'business';
        }

        return 'standard';
    }

    private function determineFulfillmentPriority(TransformedOrderRecord $record): string
    {
        if ($record->total > 1000) {
            return 'express';
        }

        if ($record->total > 500) {
            return 'priority';
        }

        return 'standard';
    }

    private function selectShippingMethod(TransformedOrderRecord $record): string
    {
        if ($record->total > 500) {
            return 'expedited';
        }

        return 'standard';
    }

    private function assessFraudRisk(TransformedOrderRecord $record): float
    {
        $riskScore = 0.0;

        if ($record->total > 5000) {
            $riskScore += 0.4;
        }

        if (count($record->items) > 20) {
            $riskScore += 0.2;
        }

        return min(1.0, $riskScore);
    }

    private function gatherErrors(): array
    {
        return [];
    }

    private function recordError(int $index, string $message): void
    {
        $this->logger->error('Import error', ['index' => $index, 'message' => $message]);
    }
}
