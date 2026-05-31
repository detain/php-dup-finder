<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Entity\User;
use App\Repository\UserRepository;
use App\DTO\ExportResult;
use Psr\Log\LoggerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class UserExportService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function exportToExcel(array $userIds, string $format = 'xlsx'): ExportResult
    {
        try {
            $users = $this->userRepository->findByIds($userIds);

            if (empty($users)) {
                $this->logger->warning('No users found for export', ['ids' => $userIds]);
                return new ExportResult(false, 'No users found', 0);
            }

            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Users');

            $headers = ['ID', 'Name', 'Email', 'Status', 'Created At', 'Last Login'];
            $sheet->fromArray($headers, null, 'A1');

            $row = 2;
            foreach ($users as $user) {
                $sheet->setCellValue("A{$row}", $user->getId());
                $sheet->setCellValue("B{$row}", $user->getFullName());
                $sheet->setCellValue("C{$row}", $user->getEmail());
                $sheet->setCellValue("D{$row}", $user->getStatus());
                $sheet->setCellValue("E{$row}", $user->getCreatedAt()->format('Y-m-d H:i:s'));
                $sheet->setCellValue("F{$row}", $user->getLastLogin()?->format('Y-m-d H:i:s') ?? 'Never');
                $row++;
            }

            foreach (range('A', 'F') as $column) {
                $sheet->getColumnDimension($column)->setAutoSize(true);
            }

            $filename = $this->generateFilename('users', $format);
            $path = $this->ensureExportDirectory() . DIRECTORY_SEPARATOR . $filename;

            $this->writeFile($spreadsheet, $path, $format);

            $this->logger->info('User export completed', [
                'count' => count($users),
                'path' => $path,
                'format' => $format,
            ]);

            return new ExportResult(true, 'Export successful', count($users), $path);

        } catch (\Throwable $e) {
            $this->logger->error('User export failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return new ExportResult(false, 'Export failed: ' . $e->getMessage(), 0);
        }
    }

    public function exportToCsv(array $userIds): ExportResult
    {
        try {
            $users = $this->userRepository->findByIds($userIds);

            if (empty($users)) {
                return new ExportResult(false, 'No users found', 0);
            }

            $filename = $this->generateFilename('users', 'csv');
            $path = $this->ensureExportDirectory() . DIRECTORY_SEPARATOR . $filename;

            $handle = fopen($path, 'w');
            if ($handle === false) {
                throw new \RuntimeException("Cannot open file for writing: {$path}");
            }

            fputcsv($handle, ['ID', 'Name', 'Email', 'Status', 'Created At', 'Last Login']);

            foreach ($users as $user) {
                fputcsv($handle, [
                    $user->getId(),
                    $user->getFullName(),
                    $user->getEmail(),
                    $user->getStatus(),
                    $user->getCreatedAt()->format('Y-m-d H:i:s'),
                    $user->getLastLogin()?->format('Y-m-d H:i:s') ?? 'Never',
                ]);
            }

            fclose($handle);

            $this->logger->info('User CSV export completed', [
                'count' => count($users),
                'path' => $path,
            ]);

            return new ExportResult(true, 'CSV export successful', count($users), $path);

        } catch (\Throwable $e) {
            $this->logger->error('User CSV export failed', ['error' => $e->getMessage()]);
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
