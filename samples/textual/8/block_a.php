<?php
declare(strict_types=1);

namespace Hr\Reports\Exporters;

final class EmployeeRosterExporter
{
    /** @param iterable<array<string,mixed>> $rows */
    public function export(iterable $rows, string $destinationPath): int
    {
        $fh = fopen($destinationPath, 'w');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open {$destinationPath}");
        }

        $header = "employee_id,first_name,last_name,email,department,title,manager_id,hire_date,location,employment_type,status,tenure_months\n";
        fwrite($fh, $header);

        $count = 0;
        foreach ($rows as $row) {
            $line = sprintf(
                "%d,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d\n",
                $row['employee_id'],
                $this->csvEscape($row['first_name']),
                $this->csvEscape($row['last_name']),
                $row['email'],
                $row['department'],
                $this->csvEscape($row['title']),
                $row['manager_id'] ?? '',
                $row['hire_date'],
                $row['location'],
                $row['employment_type'],
                $row['status'],
                (int) $row['tenure_months'],
            );
            fwrite($fh, $line);
            $count++;
        }

        fclose($fh);
        return $count;
    }

    private function csvEscape(string $v): string
    {
        return '"' . str_replace('"', '""', $v) . '"';
    }
}
