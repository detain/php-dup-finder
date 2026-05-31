<?php
declare(strict_types=1);

namespace Acme\Reports\Csv;

final class CsvRowFormatter
{
    public function format(array $row, string $delimiter = ','): string
    {
        $cleaned = [];
        foreach ($row as $value) {
            if ($value === null) {
                $cleaned[] = '';
                continue;
            }
            $str = (string) $value;
            $str = str_replace(["\r\n", "\r"], "\n", $str);
            $needsQuote = preg_match('/[",\n]/', $str) === 1;
            $escaped = str_replace('"', '""', $str);
            $cleaned[] = $needsQuote ? '"' . $escaped . '"' : $escaped;
        }
        return implode($delimiter, $cleaned) . "\n";
    }
}

final class CustomerExporter
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly CsvRowFormatter $formatter,
    ) {
    }

    public function exportToFile(string $path): int
    {
        $file = fopen($path, 'wb');
        if ($file === false) {
            throw new \RuntimeException("Cannot open {$path}");
        }
        fwrite($file, "id,name,email,signup_date\n");
        $count = 0;
        foreach ($this->customers->all() as $c) {
            fwrite($file, $this->formatter->format([
                $c->id(), $c->name(), $c->email(), $c->signupDate()->format('Y-m-d'),
            ]));
            $count++;
        }
        fclose($file);
        return $count;
    }
}
