<?php
declare(strict_types=1);

namespace Hr\Reports\Exporters;

final class EmployeeCsvSchema
{
    public const COLUMNS = [
        'employee_id', 'first_name', 'last_name', 'email', 'department',
        'title', 'manager_id', 'hire_date', 'location', 'employment_type',
        'status', 'tenure_months',
    ];

    public static function header(): string
    {
        return implode(',', self::COLUMNS) . "\n";
    }

    /** @param array<string,mixed> $row */
    public static function format(array $row): string
    {
        $escape = static fn (mixed $v): string => '"' . str_replace('"', '""', (string) $v) . '"';
        $parts = [];
        foreach (self::COLUMNS as $col) {
            $parts[] = $escape($row[$col] ?? '');
        }
        return implode(',', $parts) . "\n";
    }
}

final class EmployeeRosterExporter
{
    /** @param iterable<array<string,mixed>> $rows */
    public function export(iterable $rows, string $destinationPath): int
    {
        $fh = fopen($destinationPath, 'w');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open {$destinationPath}");
        }
        fwrite($fh, EmployeeCsvSchema::header());
        $count = 0;
        foreach ($rows as $row) {
            fwrite($fh, EmployeeCsvSchema::format($row));
            $count++;
        }
        fclose($fh);
        return $count;
    }
}
