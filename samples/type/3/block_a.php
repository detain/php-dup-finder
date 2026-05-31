<?php
declare(strict_types=1);

namespace Acme\Import\Customer;

use Acme\Customers\Customer;
use Acme\Customers\CustomerRepository;
use Acme\Import\ImportResult;
use Acme\Import\ProgressReporter;
use Acme\Import\Exceptions\ImportException;

final class CustomerCsvImporter
{
    public function __construct(
        private readonly CustomerRepository $repo,
        private readonly ProgressReporter $progress
    ) {
    }

    public function import(string $path): ImportResult
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new ImportException("Cannot open {$path}");
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            throw new ImportException('Empty file');
        }
        $expected = ['id', 'email', 'name', 'country'];
        if (array_diff($expected, $header) !== []) {
            fclose($handle);
            throw new ImportException('Missing required columns');
        }

        $idx = array_flip($header);
        $imported = 0;
        $skipped  = 0;
        $line = 1;

        $this->repo->beginTransaction();
        try {
            while (($row = fgetcsv($handle)) !== false) {
                $line++;
                if (!filter_var($row[$idx['email']], FILTER_VALIDATE_EMAIL)) {
                    $skipped++;
                    $this->progress->warn("Line {$line}: invalid email");
                    continue;
                }

                $entity = new Customer(
                    (int)$row[$idx['id']],
                    (string)$row[$idx['email']],
                    (string)$row[$idx['name']],
                    (string)$row[$idx['country']]
                );
                $this->repo->upsert($entity);
                $imported++;

                if ($imported % 100 === 0) {
                    $this->progress->tick($imported);
                }
            }
            $this->repo->commit();
        } catch (\Throwable $e) {
            $this->repo->rollback();
            fclose($handle);
            throw new ImportException('Customer import failed', 0, $e);
        }
        fclose($handle);

        return new ImportResult($imported, $skipped);
    }
}
