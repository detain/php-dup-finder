<?php
declare(strict_types=1);

namespace DataIngest\Imports\Csv;

use Psr\Log\LoggerInterface;

final class SubscribersCsvImporter
{
    public function __construct(
        private SubscriberRepository $repo,
        private LoggerInterface $log,
    ) {}

    /**
     * @return array{rows:int,errors:int}
     */
    public function import(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("cannot open {$path}");
        }

        $rows = 0;
        $errors = 0;
        try {
            $header = fgetcsv($handle);
            if ($header === false) {
                throw new \RuntimeException('empty file');
            }
            $idx = array_flip(array_map('strtolower', $header));
            while (($cols = fgetcsv($handle)) !== false) {
                try {
                    $email = trim((string) ($cols[$idx['email']] ?? ''));
                    $name  = trim((string) ($cols[$idx['name']] ?? ''));
                    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $errors++;
                        continue;
                    }
                    $this->repo->upsert($email, $name);
                    $rows++;
                } catch (\Throwable $e) {
                    $errors++;
                    $this->log->warning('csv.row_failed', ['err' => $e->getMessage()]);
                }
            }
            $this->log->info('csv.import.ok', ['rows' => $rows, 'errors' => $errors]);
            return ['rows' => $rows, 'errors' => $errors];
        } finally {
            fclose($handle);
        }
    }
}
