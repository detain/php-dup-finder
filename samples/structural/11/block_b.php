<?php
declare(strict_types=1);

namespace DataImport\Pipeline;

use Psr\Log\LoggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ProductImportPipeline
{
    private const BATCH_SIZE = 100;
    private const MAX_RETRIES = 3;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ValidatorInterface $validator,
        private readonly ProductRepository $productRepository,
        private readonly ProductEventDispatcher $eventDispatcher,
    ) {}

    public function process(string $filePath): ImportResult
    {
        $this->logger->info('Starting product import pipeline', ['file' => $filePath]);

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
        $this->logger->info('Product import pipeline completed');

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
            $productDto = $this->mapToProductDto($record);
            $violations = $this->validator->validate($productDto);

            if (count($violations) > 0) {
                $errorMessages = [];
                foreach ($violations as $violation) {
                    $errorMessages[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
                }
                $errors[$index] = $errorMessages;
                continue;
            }

            $validated[$index] = $productDto;
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

        foreach ($validatedRecords as $index => $productDto) {
            $transformed[$index] = new TransformedProductRecord(
                sku: strtoupper(trim($productDto->sku)),
                name: ucwords(strtolower(trim($productDto->name))),
                description: trim($productDto->description ?? ''),
                price: (float) $productDto->price,
                cost: (float) ($productDto->cost ?? 0),
                createdAt: new \DateTimeImmutable(),
                status: 'draft',
            );
        }

        return $transformed;
    }

    private function enrich(array $transformedRecords): array
    {
        $enriched = [];

        foreach ($transformedRecords as $index => $record) {
            $margin = $record->price > 0
                ? (($record->price - $record->cost) / $record->price) * 100
                : 0;

            $enriched[$index] = new EnrichedProductRecord(
                original: $record,
                category: $this->inferCategory($record->name),
                marginPercent: round($margin, 2),
                productRank: $this->calculateInitialRank($record),
                images: $this->generateImageUrls($record->sku),
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
                        $product = new Product(
                            sku: $record->original->sku,
                            name: $record->original->name,
                            description: $record->original->description,
                            price: $record->original->price,
                            cost: $record->original->cost,
                            status: $record->original->status,
                            category: $record->category,
                            marginPercent: $record->marginPercent,
                        );

                        $this->productRepository->save($product);
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
                new ProductImportedEvent(
                    productSku: $record->original->sku,
                    name: $record->original->name,
                    category: $record->category,
                    importedAt: $record->original->createdAt,
                )
            );
        }
    }

    private function mapToProductDto(array $record): ProductImportDto
    {
        return new ProductImportDto(
            sku: $record['sku'] ?? '',
            name: $record['name'] ?? '',
            description: $record['description'] ?? '',
            price: $record['price'] ?? '0',
            cost: $record['cost'] ?? '0',
        );
    }

    private function inferCategory(string $name): string
    {
        $nameLower = strtolower($name);

        if (str_contains($nameLower, 'shirt') || str_contains($nameLower, 'pants')) {
            return 'apparel';
        }

        if (str_contains($nameLower, 'laptop') || str_contains($nameLower, 'phone')) {
            return 'electronics';
        }

        return 'general';
    }

    private function calculateInitialRank(TransformedProductRecord $record): int
    {
        if ($record->price > 1000) {
            return 100;
        }

        if ($record->price > 500) {
            return 75;
        }

        return 50;
    }

    private function generateImageUrls(string $sku): array
    {
        return [
            sprintf('https://cdn.example.com/products/%s/main.jpg', strtolower($sku)),
            sprintf('https://cdn.example.com/products/%s/thumb.jpg', strtolower($sku)),
        ];
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
