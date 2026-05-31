<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\DTO\ExportResult;
use App\Entity\EntityInterface;
use Psr\Log\LoggerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class GenericExportService
{
    /** @var array<string, callable(object): array> */
    private array $headerGetters = [];

    /** @var array<string, callable(object): array> */
    private array $rowGetters = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        $this->registerEntityHandlers();
    }

    private function registerEntityHandlers(): void
    {
        $this->headerGetters['user'] = fn() => ['ID', 'Name', 'Email', 'Status', 'Created At', 'Last Login'];
        $this->rowGetters['user'] = fn($user) => [
            $user->getId(),
            $user->getFullName(),
            $user->getEmail(),
            $user->getStatus(),
            $user->getCreatedAt()->format('Y-m-d H:i:s'),
            $user->getLastLogin()?->format('Y-m-d H:i:s') ?? 'Never',
        ];

        $this->headerGetters['order'] = fn() => ['Order ID', 'Customer', 'Total', 'Status', 'Created At', 'Shipped At'];
        $this->rowGetters['order'] = fn($order) => [
            $order->getId(),
            $order->getCustomerName(),
            $order->getTotalAmount(),
            $order->getStatus(),
            $order->getCreatedAt()->format('Y-m-d H:i:s'),
            $order->getShippedAt()?->format('Y-m-d H:i:s') ?? 'Pending',
        ];

        $this->headerGetters['product'] = fn() => ['SKU', 'Name', 'Price', 'Stock', 'Category', 'Status'];
        $this->rowGetters['product'] = fn($product) => [
            $product->getSku(),
            $product->getName(),
            $product->getPrice(),
            $product->getStockQuantity(),
            $product->getCategory(),
            $product->isActive() ? 'Active' : 'Inactive',
        ];
    }

    public function exportToExcel(array $entities, string $entityType, string $format = 'xlsx'): ExportResult
    {
        try {
            if (empty($entities)) {
                return new ExportResult(false, 'No ' . $entityType . 's found', 0);
            }

            if (!isset($this->headerGetters[$entityType])) {
                return new ExportResult(false, "Unknown entity type: {$entityType}", 0);
            }

            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle(ucfirst($entityType) . 's');

            $headers = ($this->headerGetters[$entityType])();
            $sheet->fromArray($headers, null, 'A1');

            $row = 2;
            foreach ($entities as $entity) {
                $rowData = ($this->rowGetters[$entityType])($entity);
                $sheet->fromArray($rowData, null, "A{$row}");
                $row++;
            }

            foreach (range('A', chr(64 + count($headers))) as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }

            $filename = $this->generateFilename($entityType, $format);
            $path = $this->ensureExportDirectory() . DIRECTORY_SEPARATOR . $filename;

            $this->writeFile($spreadsheet, $path);

            $this->logger->info("{$entityType} export completed", [
                'count' => count($entities),
                'path' => $path,
            ]);

            return new ExportResult(true, 'Export successful', count($entities), $path);

        } catch (\Throwable $e) {
            $this->logger->error("{$entityType} export failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new ExportResult(false, 'Export failed: ' . $e->getMessage(), 0);
        }
    }

    public function exportToCsv(array $entities, string $entityType): ExportResult
    {
        try {
            if (empty($entities)) {
                return new ExportResult(false, "No {$entityType}s found", 0);
            }

            if (!isset($this->headerGetters[$entityType])) {
                return new ExportResult(false, "Unknown entity type: {$entityType}", 0);
            }

            $filename = $this->generateFilename($entityType, 'csv');
            $path = $this->ensureExportDirectory() . DIRECTORY_SEPARATOR . $filename;

            $handle = fopen($path, 'w');
            if ($handle === false) {
                throw new \RuntimeException("Cannot open file for writing: {$path}");
            }

            fputcsv($handle, ($this->headerGetters[$entityType])());

            foreach ($entities as $entity) {
                fputcsv($handle, ($this->rowGetters[$entityType])($entity));
            }

            fclose($handle);

            $this->logger->info("{$entityType} CSV export completed", [
                'count' => count($entities),
                'path' => $path,
            ]);

            return new ExportResult(true, 'CSV export successful', count($entities), $path);

        } catch (\Throwable $e) {
            $this->logger->error("{$entityType} CSV export failed", ['error' => $e->getMessage()]);
            return new ExportResult(false, 'CSV export failed: ' . $e->getMessage(), 0);
        }
    }

    private function generateFilename(string $prefix, string $format): string
    {
        $timestamp = (new \DateTimeImmutable())->format('Y_m_d_His');
        return sprintf('%s_export_%s.%s', $prefix, $timestamp, $format);
    }

    private function ensureExportDirectory(): string
    {
        $dir = dirname(__DIR__, 2) . '/var/exports';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    private function writeFile(Spreadsheet $spreadsheet, string $path): void
    {
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
    }
}
