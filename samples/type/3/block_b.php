<?php
declare(strict_types=1);

namespace Acme\Import\Vendor;

use Acme\Vendors\Vendor;
use Acme\Vendors\VendorRepository;
use Acme\Import\ImportResult;
use Acme\Import\ProgressReporter;
use Acme\Import\Exceptions\ImportException;

final class VendorCsvImporter
{
    public function __construct(
        private readonly VendorRepository $repo,
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
        $expected = ['id', 'tax_id', 'company', 'phone'];
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
                if (!preg_match('/^[0-9\-]{6,}$/', (string)$row[$idx['tax_id']])) {
                    $skipped++;
                    $this->progress->warn("Line {$line}: invalid tax id");
                    continue;
                }

                $entity = new Vendor(
                    (int)$row[$idx['id']],
                    (string)$row[$idx['tax_id']],
                    (string)$row[$idx['company']],
                    (string)$row[$idx['phone']]
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
            throw new ImportException('Vendor import failed', 0, $e);
        }
        fclose($handle);

        return new ImportResult($imported, $skipped);
    }
}
