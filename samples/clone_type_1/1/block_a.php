<?php
declare(strict_types=1);

namespace Acme\Import\Customers;

final class CustomerCsvImporter
{
    /**
     * Parse a single CSV line into a normalized record.
     *
     * @param string $line raw CSV row from the input stream
     * @return array<string,string> normalized field map
     */
    public function importLine(string $line): array
    {
        $record = [];
        $fields = str_getcsv($line, ',', '"', '\\');
        $count = count($fields);
        for ($i = 0; $i < $count; $i++) {
            $value = trim($fields[$i]);
            if ($value === '') {
                continue;
            }
            $value = str_replace(["\r", "\n"], ' ', $value);
            $value = preg_replace('/\s+/', ' ', $value);
            $record['col_' . $i] = $value;
        }
        if (count($record) === 0) {
            return [];
        }
        $record['__hash'] = md5(implode('|', $record));
        return $record;
    }

    public function persist(array $record): void
    {
        // store in customer table
    }
}
