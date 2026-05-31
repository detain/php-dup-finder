<?php
declare(strict_types=1);

namespace DataIngest\Imports\Fixed;

use Psr\Log\LoggerInterface;

final class BankFixedWidthImporter
{
    public function __construct(
        private TransactionRepository $repo,
        private LoggerInterface $log,
    ) {}

    /**
     * @return array{records:int,errors:int}
     */
    public function import(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("cannot open {$path}");
        }

        $records = 0;
        $errors = 0;
        try {
            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '' || strlen($line) < 80) {
                    continue;
                }
                try {
                    $account = trim(substr($line, 0, 12));
                    $date    = trim(substr($line, 12, 8));
                    $amount  = (int) trim(substr($line, 20, 12));
                    $memo    = trim(substr($line, 32, 48));
                    if ($account === '' || $date === '') {
                        $errors++;
                        continue;
                    }
                    $this->repo->insertFixedWidthRow($account, $date, $amount, $memo);
                    $records++;
                } catch (\Throwable $e) {
                    $errors++;
                    $this->log->warning('fixed.row_failed', ['err' => $e->getMessage()]);
                }
            }
            $this->log->info('fixed.import.ok', ['records' => $records, 'errors' => $errors]);
            return ['records' => $records, 'errors' => $errors];
        } finally {
            fclose($handle);
        }
    }
}
