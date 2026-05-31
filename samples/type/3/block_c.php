<?php
declare(strict_types=1);

namespace Acme\Import\Employee;

use Acme\Hr\Employee;
use Acme\Hr\EmployeeRepository;
use Acme\Import\ImportResult;
use Acme\Import\ProgressReporter;
use Acme\Import\Exceptions\ImportException;

final class EmployeeCsvImporter
{
    public function __construct(
        private readonly EmployeeRepository $repo,
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
        $expected = ['id', 'ssn', 'name', 'department'];
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
                if (!preg_match('/^\d{3}-\d{2}-\d{4}$/', (string)$row[$idx['ssn']])) {
                    $skipped++;
                    $this->progress->warn("Line {$line}: invalid ssn");
                    continue;
                }

                $entity = new Employee(
                    (int)$row[$idx['id']],
                    (string)$row[$idx['ssn']],
                    (string)$row[$idx['name']],
                    (string)$row[$idx['department']]
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
            throw new ImportException('Employee import failed', 0, $e);
        }
        fclose($handle);

        return new ImportResult($imported, $skipped);
    }
}
