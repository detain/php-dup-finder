<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Entity\Product;
use App\Repository\ProductRepository;
use App\DTO\ExportResult;
use Psr\Log\LoggerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class ProductExportService
{
    public function __construct(
        private readonly ProductRepository $productRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function exportToExcel(array $productIds, string $format = 'xlsx'): ExportResult
    {
        try {
            $products = $this->productRepository->findByIds($productIds);

            if (empty($products)) {
                $this->logger->warning('No products found for export', ['ids' => $productIds]);
                return new ExportResult(false, 'No products found', 0);
            }

            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Products');

            $headers = ['SKU', 'Name', 'Price', 'Stock', 'Category', 'Status'];
            $sheet->fromArray($headers, null, 'A1');

            $row = 2;
            foreach ($products as $product) {
                $sheet->setCellValue("A{$row}", $product->getSku());
                $sheet->setCellValue("B{$row}", $product->getName());
                $sheet->setCellValue("C{$row}", $product->getPrice());
                $sheet->setCellValue("D{$row}", $product->getStockQuantity());
                $sheet->setCellValue("E{$row}", $product->getCategory());
                $sheet->setCellValue("F{$row}", $product->isActive() ? 'Active' : 'Inactive');
                $row++;
            }

            foreach (range('A', 'F') as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }

            $filename = $this->generateFilename('products', $format);
            $path = $this->ensureExportDirectory() . DIRECTORY_SEPARATOR . $filename;

            $this->writeFile($spreadsheet, $path, $format);

            $this->logger->info('Product export completed', [
                'count' => count($products),
                'path' => $path,
                'format' => $format,
            ]);

            return new ExportResult(true, 'Export successful', count($products), $path);

        } catch (\Throwable $e) {
            $this->logger->error('Product export failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new ExportResult(false, 'Export failed: ' . $e->getMessage(), 0);
        }
    }

    public function exportToCsv(array $productIds): ExportResult
    {
        try {
            $products = $this->productRepository->findByIds($productIds);

            if (empty($products)) {
                return new ExportResult(false, 'No products found', 0);
            }

            $filename = $this->generateFilename('products', 'csv');
            $path = $this->ensureExportDirectory() . DIRECTORY_SEPARATOR . $filename;

            $handle = fopen($path, 'w');
            if ($handle === false) {
                throw new \RuntimeException("Cannot open file for writing: {$path}");
            }

            fputcsv($handle, ['SKU', 'Name', 'Price', 'Stock', 'Category', 'Status']);

            foreach ($products as $product) {
                fputcsv($handle, [
                    $product->getSku(),
                    $product->getName(),
                    $product->getPrice(),
                    $product->getStockQuantity(),
                    $product->getCategory(),
                    $product->isActive() ? 'Active' : 'Inactive',
                ]);
            }

            fclose($handle);

            $this->logger->info('Product CSV export completed', [
                'count' => count($products),
                'path' => $path,
            ]);

            return new ExportResult(true, 'CSV export successful', count($products), $path);

        } catch (\Throwable $e) {
            $this->logger->error('Product CSV export failed', ['error' => $e->getMessage()]);
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

    private function writeFile(Spreadsheet $spreadsheet, string $path, string $format): void
    {
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
    }
}
