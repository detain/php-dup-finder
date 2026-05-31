<?php
declare(strict_types=1);

namespace DataImporter\Parsers;

use Psr\Log\LoggerInterface;

final class CsvParser
{
    private const DEFAULT_DELIMITER = ',';
    private const DEFAULT_ENCLOSURE = '"';
    private const DEFAULT_SKIP_LINES = 0;
    private const MAX_ROWS_TO_SKIP = 100;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function parse(string $filePath, ImportOptions $options): ParseResult
    {
        $this->logger->info('Parsing CSV file', [
            'filepath' => $filePath,
        ]);

        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException('File not found: ' . $filePath);
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open file: ' . $filePath);
        }

        $skipLines = min($options->getSkipLines() ?? self::DEFAULT_SKIP_LINES, self::MAX_ROWS_TO_SKIP);
        for ($i = 0; $i < $skipLines; $i++) {
            fgets($handle);
        }

        $headers = $this->parseHeaders($handle, $options);
        $rows = $this->parseRows($handle, $headers, $options);
        $errors = $this->validateRows($rows, $options);

        fclose($handle);

        $this->logger->info('CSV parsing completed', [
            'total_rows' => count($rows),
            'error_count' => count($errors),
        ]);

        return new ParseResult(
            rows: $rows,
            headers: $headers,
            errors: $errors,
            parsedAt: new \DateTimeImmutable(),
        );
    }

    private function parseHeaders($handle, ImportOptions $options): array
    {
        $line = fgets($handle);
        if ($line === false) {
            return [];
        }

        $headers = str_getcsv(trim($line), $options->getDelimiter());
        return array_map('trim', $headers);
    }

    private function parseRows($handle, array $headers, ImportOptions $options): array
    {
        $rows = [];
        $maxRows = $options->getMaxRows();

        while (($line = fgets($handle)) !== false) {
            if ($line === "\n" || trim($line) === '') {
                continue;
            }

            $values = str_getcsv(trim($line), $options->getDelimiter());
            if (count($values) !== count($headers)) {
                $this->logger->warning('Column count mismatch, skipping row', [
                    'expected' => count($headers),
                    'actual' => count($values),
                ]);
                continue;
            }

            $row = array_combine($headers, $values);
            $rows[] = $this->transformRow($row, $options);

            if ($maxRows !== null && count($rows) >= $maxRows) {
                $this->logger->debug('Max rows reached', ['max_rows' => $maxRows]);
                break;
            }
        }

        return $rows;
    }

    private function transformRow(array $row, ImportOptions $options): array
    {
        $transformed = [];
        foreach ($row as $key => $value) {
            $trimmedValue = trim($value);

            if ($trimmedValue === '' || $trimmedValue === $options->getNullValue()) {
                $transformed[$key] = null;
                continue;
            }

            $transformed[$key] = $this->castValue($trimmedValue, $options->getColumnType($key));
        }

        return $transformed;
    }

    private function castValue(string $value, ?string $type): mixed
    {
        if ($type === null) {
            return $value;
        }

        return match ($type) {
            'int', 'integer' => (int)$value,
            'float', 'double' => (float)$value,
            'bool', 'boolean' => in_array(strtolower($value), ['true', '1', 'yes', 'y'], true),
            'date' => $this->parseDate($value),
            'datetime' => $this->parseDateTime($value),
            'json' => json_decode($value, true),
            default => $value,
        };
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function parseDateTime(string $value): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function validateRows(array $rows, ImportOptions $options): array
    {
        $errors = [];
        $requiredFields = $options->getRequiredFields();

        foreach ($rows as $index => $row) {
            $rowErrors = $this->validateRow($row, $requiredFields, $index);
            if (!empty($rowErrors)) {
                $errors = array_merge($errors, $rowErrors);
            }
        }

        return $errors;
    }

    private function validateRow(array $row, array $requiredFields, int $rowIndex): array
    {
        $errors = [];

        foreach ($requiredFields as $field) {
            if (!isset($row[$field]) || $row[$field] === null) {
                $errors[] = new ParseError(
                    row: $rowIndex,
                    field: $field,
                    message: "Required field '{$field}' is missing or empty",
                );
            }
        }

        return $errors;
    }
}
