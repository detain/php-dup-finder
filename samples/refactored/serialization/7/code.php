<?php

declare(strict_types=1);

namespace App\Export;

interface CsvExportStrategy
{
    public function getHeaders(): array;
    public function toRow(mixed $entity): array;
}

abstract class AbstractCsvExporter
{
    protected string $delimiter = ',';
    protected string $enclosure = '"';
    protected string $lineEnding = "\r\n";

    public function export(array $entities, string $filepath): int
    {
        $handle = fopen($filepath, 'w');

        if ($handle === false) {
            throw new \RuntimeException("Cannot open file for writing: {$filepath}");
        }

        $this->writeHeader($handle);

        $strategy = $this->getExportStrategy();
        $rowCount = 0;

        foreach ($entities as $entity) {
            fputcsv($handle, $strategy->toRow($entity), $this->delimiter, $this->enclosure, $this->lineEnding);
            $rowCount++;
        }

        fclose($handle);

        return $rowCount;
    }

    public function exportToString(array $entities): string
    {
        $handle = fopen('php://memory', 'r+');

        $strategy = $this->getExportStrategy();

        fputcsv($handle, $strategy->getHeaders(), $this->delimiter, $this->enclosure, $this->lineEnding);

        foreach ($entities as $entity) {
            fputcsv($handle, $strategy->toRow($entity), $this->delimiter, $this->enclosure, $this->lineEnding);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    private function writeHeader($handle): void
    {
        fputcsv($handle, $this->getExportStrategy()->getHeaders(), $this->delimiter, $this->enclosure, $this->lineEnding);
    }

    public function generateFilename(string $prefix = 'export'): string
    {
        $timestamp = date('Y-m-d_His');
        return "{$prefix}_{$timestamp}.csv";
    }

    public function setDelimiter(string $delimiter): void
    {
        $this->delimiter = $delimiter;
    }

    public function setEnclosure(string $enclosure): void
    {
        $this->enclosure = $enclosure;
    }

    public function getContentType(): string
    {
        return 'text/csv; charset=utf-8';
    }

    abstract protected function getExportStrategy(): CsvExportStrategy;
}

class UserCsvExporter extends AbstractCsvExporter
{
    protected function getExportStrategy(): CsvExportStrategy
    {
        return new class implements CsvExportStrategy {
            public function getHeaders(): array {
                return ['ID', 'Email', 'Name', 'Avatar URL', 'Is Active', 'Roles', 'Created At', 'Updated At'];
            }

            public function toRow(User $user): array {
                return [
                    $user->getId(),
                    $user->getEmail(),
                    $user->getName(),
                    $user->getAvatarUrl() ?? '',
                    $user->isActive() ? 'Yes' : 'No',
                    implode(';', $user->getRoles()),
                    $user->getCreatedAt()->format('Y-m-d H:i:s'),
                    $user->getUpdatedAt()?->format('Y-m-d H:i:s') ?? ''
                ];
            }
        };
    }
}

class ProductCsvExporter extends AbstractCsvExporter
{
    protected function getExportStrategy(): CsvExportStrategy
    {
        return new class implements CsvExportStrategy {
            public function getHeaders(): array {
                return ['ID', 'Name', 'Description', 'Price Amount', 'Price Currency', 'Category ID', 'Image URL', 'Stock Quantity', 'Is Available', 'Tags', 'Created At', 'Updated At'];
            }

            public function toRow(Product $product): array {
                return [
                    $product->getId(),
                    $product->getName(),
                    $product->getDescription() ?? '',
                    (string)$product->getPrice(),
                    $product->getCurrency(),
                    $product->getCategoryId(),
                    $product->getImageUrl() ?? '',
                    (string)$product->getStockQuantity(),
                    $product->isAvailable() ? 'Yes' : 'No',
                    implode(';', $product->getTags()),
                    $product->getCreatedAt()->format('Y-m-d H:i:s'),
                    $product->getUpdatedAt()?->format('Y-m-d H:i:s') ?? ''
                ];
            }
        };
    }
}

class OrderCsvExporter extends AbstractCsvExporter
{
    protected function getExportStrategy(): CsvExportStrategy
    {
        return new class implements CsvExportStrategy {
            public function getHeaders(): array {
                return ['ID', 'User ID', 'Total Amount', 'Total Currency', 'Status', 'Shipping Address', 'Billing Address', 'Item Count', 'Created At', 'Updated At', 'Shipped At'];
            }

            public function toRow(Order $order): array {
                return [
                    $order->getId(),
                    $order->getUserId(),
                    (string)$order->getTotalAmount(),
                    $order->getCurrency(),
                    $order->getStatus(),
                    $order->getShippingAddress() ?? '',
                    $order->getBillingAddress() ?? '',
                    (string)count($order->getItems()),
                    $order->getCreatedAt()->format('Y-m-d H:i:s'),
                    $order->getUpdatedAt()?->format('Y-m-d H:i:s') ?? '',
                    $order->getShippedAt()?->format('Y-m-d H:i:s') ?? ''
                ];
            }
        };
    }
}

class CsvExporterRegistry
{
    private array $exporters = [];

    public function register(string $entityType, AbstractCsvExporter $exporter): void
    {
        $this->exporters[$entityType] = $exporter;
    }

    public function getExporter(string $entityType): ?AbstractCsvExporter
    {
        return $this->exporters[$entityType] ?? null;
    }

    public function export(string $entityType, array $entities, string $filepath): int
    {
        $exporter = $this->getExporter($entityType);

        if ($exporter === null) {
            throw new \InvalidArgumentException("No CSV exporter for: {$entityType}");
        }

        return $exporter->export($entities, $filepath);
    }
}
