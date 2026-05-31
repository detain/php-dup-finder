<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Entity\Order;
use App\Repository\OrderRepository;
use App\DTO\ExportResult;
use Psr\Log\LoggerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class OrderExportService
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function exportToExcel(array $orderIds, string $format = 'xlsx'): ExportResult
    {
        try {
            $orders = $this->orderRepository->findByIds($orderIds);

            if (empty($orders)) {
                $this->logger->warning('No orders found for export', ['ids' => $orderIds]);
                return new ExportResult(false, 'No orders found', 0);
            }

            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Orders');

            $headers = ['Order ID', 'Customer', 'Total', 'Status', 'Created At', 'Shipped At'];
            $sheet->fromArray($headers, null, 'A1');

            $row = 2;
            foreach ($orders as $order) {
                $sheet->setCellValue("A{$row}", $order->getId());
                $sheet->setCellValue("B{$row}", $order->getCustomerName());
                $sheet->setCellValue("C{$row}", $order->getTotalAmount());
                $sheet->setCellValue("D{$row}", $order->getStatus());
                $sheet->setCellValue("E{$row}", $order->getCreatedAt()->format('Y-m-d H:i:s'));
                $sheet->setCellValue("F{$row}", $order->getShippedAt()?->format('Y-m-d H:i:s') ?? 'Pending');
                $row++;
            }

            foreach (range('A', 'F') as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }

            $filename = $this->generateFilename('orders', $format);
            $path = $this->ensureExportDirectory() . DIRECTORY_SEPARATOR . $filename;

            $this->writeFile($spreadsheet, $path, $format);

            $this->logger->info('Order export completed', [
                'count' => count($orders),
                'path' => $path,
                'format' => $format,
            ]);

            return new ExportResult(true, 'Export successful', count($orders), $path);

        } catch (\Throwable $e) {
            $this->logger->error('Order export failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new ExportResult(false, 'Export failed: ' . $e->getMessage(), 0);
        }
    }

    public function exportToCsv(array $orderIds): ExportResult
    {
        try {
            $orders = $this->orderRepository->findByIds($orderIds);

            if (empty($orders)) {
                return new ExportResult(false, 'No orders found', 0);
            }

            $filename = $this->generateFilename('orders', 'csv');
            $path = $this->ensureExportDirectory() . DIRECTORY_SEPARATOR . $filename;

            $handle = fopen($path, 'w');
            if ($handle === false) {
                throw new \RuntimeException("Cannot open file for writing: {$path}");
            }

            fputcsv($handle, ['Order ID', 'Customer', 'Total', 'Status', 'Created At', 'Shipped At']);

            foreach ($orders as $order) {
                fputcsv($handle, [
                    $order->getId(),
                    $order->getCustomerName(),
                    $order->getTotalAmount(),
                    $order->getStatus(),
                    $order->getCreatedAt()->format('Y-m-d H:i:s'),
                    $order->getShippedAt()?->format('Y-m-d H:i:s') ?? 'Pending',
                ]);
            }

            fclose($handle);

            $this->logger->info('Order CSV export completed', [
                'count' => count($orders),
                'path' => $path,
            ]);

            return new ExportResult(true, 'CSV export successful', count($orders), $path);

        } catch (\Throwable $e) {
            $this->logger->error('Order CSV export failed', ['error' => $e->getMessage()]);
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
