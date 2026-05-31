<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\Product;
use App\Repository\ProductRepository;
use App\Exception\ImportException;
use Psr\Log\LoggerInterface;

final class ProductImporter
{
    private const REQUIRED_FIELDS = ['sku', 'name', 'price'];

    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function importFromCsv(string $filename): ImportResult
    {
        $handle = fopen($filename, 'r');
        $headers = fgetcsv($handle);

        if ($headers === false) {
            throw new ImportException('Unable to read CSV headers');
        }

        $imported = 0;
        $errors = [];

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);

            try {
                $this->validateAndImport($data);
                $imported++;
            } catch (\Exception $e) {
                $errors[] = [
                    'row' => $imported + count($errors) + 2,
                    'error' => $e->getMessage(),
                    'data' => $data,
                ];
            }
        }

        fclose($handle);

        $this->logger->info('Product import completed', [
            'filename' => $filename,
            'imported' => $imported,
            'errors' => count($errors),
        ]);

        return new ImportResult($imported, count($errors), $errors);
    }

    public function importFromJson(string $filename): ImportResult
    {
        $content = file_get_contents($filename);
        $data = json_decode($content, true);

        if (!is_array($data)) {
            throw new ImportException('Invalid JSON format');
        }

        $imported = 0;
        $errors = [];

        foreach ($data as $index => $item) {
            try {
                $this->validateAndImport($item);
                $imported++;
            } catch (\Exception $e) {
                $errors[] = [
                    'row' => $index + 1,
                    'error' => $e->getMessage(),
                    'data' => $item,
                ];
            }
        }

        $this->logger->info('Product import completed', [
            'filename' => $filename,
            'imported' => $imported,
            'errors' => count($errors),
        ]);

        return new ImportResult($imported, count($errors), $errors);
    }

    public function importFromXml(string $filename): ImportResult
    {
        $xml = simplexml_load_file($filename);

        if ($xml === false) {
            throw new ImportException('Invalid XML format');
        }

        $imported = 0;
        $errors = [];

        foreach ($xml->product as $index => $productElement) {
            $data = [
                'sku' => (string) $productElement->sku,
                'name' => (string) $productElement->name,
                'price' => (float) $productElement->price,
                'stock' => (int) ($productElement->stock ?? 0),
                'description' => (string) ($productElement->description ?? ''),
            ];

            try {
                $this->validateAndImport($data);
                $imported++;
            } catch (\Exception $e) {
                $errors[] = [
                    'row' => $index + 1,
                    'error' => $e->getMessage(),
                    'data' => $data,
                ];
            }
        }

        $this->logger->info('Product import completed', [
            'filename' => $filename,
            'imported' => $imported,
            'errors' => count($errors),
        ]);

        return new ImportResult($imported, count($errors), $errors);
    }

    private function validateAndImport(array $data): void
    {
        $errors = $this->validate($data);

        if (!empty($errors)) {
            throw new ImportException('Validation failed: ' . implode(', ', $errors));
        }

        $existingProduct = $this->productRepository->findBySku($data['sku']);
        if ($existingProduct !== null) {
            throw new ImportException('Product with SKU already exists');
        }

        $product = new Product(
            $data['sku'],
            $data['name'],
            (float) $data['price'],
            $data['description'] ?? null
        );

        $product->setStock($data['stock'] ?? 0);
        $this->productRepository->save($product);
    }

    private function validate(array $data): array
    {
        $errors = [];

        foreach (self::REQUIRED_FIELDS as $field) {
            if (empty($data[$field]) && $data[$field] !== '0') {
                $errors[] = "Field '{$field}' is required";
            }
        }

        if (!empty($data['price']) && (!is_numeric($data['price']) || $data['price'] < 0)) {
            $errors[] = 'Price must be a positive number';
        }

        if (!empty($data['stock']) && (!is_int($data['stock']) || $data['stock'] < 0)) {
            $errors[] = 'Stock must be a non-negative integer';
        }

        return $errors;
    }
}
