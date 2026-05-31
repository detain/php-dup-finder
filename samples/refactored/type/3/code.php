<?php
declare(strict_types=1);

namespace Acme\Import;

use Acme\Import\Exceptions\ImportException;

interface RowMapper
{
    /** @return array<string> */
    public function requiredColumns(): array;
    /** @param array<int,string> $row @param array<string,int> $idx */
    public function validate(array $row, array $idx): ?string;
    /** @param array<int,string> $row @param array<string,int> $idx */
    public function toEntity(array $row, array $idx): object;
    public function entityLabel(): string;
}

interface RepositoryGateway
{
    public function beginTransaction(): void;
    public function commit(): void;
    public function rollback(): void;
    public function upsert(object $entity): void;
}

final class CsvImporter
{
    public function __construct(private readonly ProgressReporter $progress)
    {
    }

    public function import(string $path, RowMapper $mapper, RepositoryGateway $repo): ImportResult
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new ImportException("Cannot open {$path}");
        }
        $header = fgetcsv($handle);
        if ($header === false || array_diff($mapper->requiredColumns(), $header) !== []) {
            fclose($handle);
            throw new ImportException('Bad header');
        }
        $idx = array_flip($header);
        $imported = 0; $skipped = 0; $line = 1;

        $repo->beginTransaction();
        try {
            while (($row = fgetcsv($handle)) !== false) {
                $line++;
                if (($err = $mapper->validate($row, $idx)) !== null) {
                    $skipped++;
                    $this->progress->warn("Line {$line}: {$err}");
                    continue;
                }
                $repo->upsert($mapper->toEntity($row, $idx));
                $imported++;
                if ($imported % 100 === 0) {
                    $this->progress->tick($imported);
                }
            }
            $repo->commit();
        } catch (\Throwable $e) {
            $repo->rollback();
            fclose($handle);
            throw new ImportException($mapper->entityLabel() . ' import failed', 0, $e);
        }
        fclose($handle);
        return new ImportResult($imported, $skipped);
    }
}
