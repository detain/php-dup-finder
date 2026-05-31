<?php
declare(strict_types=1);

namespace DataIngest\Imports;

use Psr\Log\LoggerInterface;

final class FileHandle
{
    public function __construct(private LoggerInterface $log) {}

    /**
     * @template T
     * @param callable(resource):T $work
     * @return T
     */
    public function withFile(string $path, string $mode, callable $work)
    {
        $handle = @fopen($path, $mode);
        if ($handle === false) {
            throw new \RuntimeException("cannot open {$path}");
        }
        try {
            return $work($handle);
        } finally {
            fclose($handle);
            $this->log->debug('file.closed', ['path' => $path]);
        }
    }
}

final class SubscribersCsvImporter
{
    public function __construct(private FileHandle $fh, private SubscriberRepository $repo) {}

    public function import(string $path): array
    {
        return $this->fh->withFile($path, 'rb', function ($h): array {
            $rows = 0; $errors = 0;
            $header = fgetcsv($h) ?: throw new \RuntimeException('empty file');
            $idx = array_flip(array_map('strtolower', $header));
            while (($cols = fgetcsv($h)) !== false) {
                $email = trim((string) ($cols[$idx['email']] ?? ''));
                $name  = trim((string) ($cols[$idx['name']] ?? ''));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors++; continue; }
                $this->repo->upsert($email, $name);
                $rows++;
            }
            return ['rows' => $rows, 'errors' => $errors];
        });
    }
}
