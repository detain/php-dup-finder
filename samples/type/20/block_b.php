<?php
declare(strict_types=1);

namespace DataImporter\Parsers;

use Psr\Log\LoggerInterface;

final class JsonParser
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
        $this->logger->info('Parsing JSON file', [
            'filepath' => $filePath,
        ]);

        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException('File not found: ' . $filePath);
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \RuntimeException('Cannot read file: ' . $filePath);
        }

        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON: ' . json_last_error_msg());
        }

        if (!is_array($data)) {
            $data = [$data];
        }

        $skipLines = min($options->getSkipLines() ?? self::DEFAULT_SKIP_LINES, self::MAX_ROWS_TO_SKIP);
        if ($skipLines > 0) {
            $data = array_slice($data, $skipLines);
        }

        $rows = $this->processRows($data, $options);
        $headers = $this->extractHeaders($rows);
        $errors = $this->validateRows($rows, $options);

        $this->logger->info('JSON parsing completed', [
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

    private function processRows(array $data, ImportOptions $options): array
    {
        $rows = [];
        $maxRows = $options->getMaxRows();
        $dataArray = $data;

        foreach ($dataArray as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $rows[] = $this->transformRow($item, $options);

            if ($maxRows !== null && count($rows) >= $maxRows) {
                break;
            }
        }

        return $rows;
    }

    private function transformRow(array $row, ImportOptions $options): array
    {
        $transformed = [];
        foreach ($row as $key => $value) {
            $trimmedKey = trim((string)$key);

            if ($value === null || $value === '' || $value === $options->getNullValue()) {
                $transformed[$trimmedKey] = null;
                continue;
            }

            $transformed[$trimmedKey] = $this->castValue($value, $options->getColumnType($trimmedKey));
        }

        return $transformed;
    }

    private function castValue(mixed $value, ?string $type): mixed
    {
        if ($type === null || !is_string($value)) {
            return $value;
        }

        return match ($type) {
            'int', 'integer' => (int)$value,
            'float', 'double' => (float)$value,
            'bool', 'boolean' => in_array(strtolower((string)$value), ['true', '1', 'yes', 'y'], true),
            'date' => $this->parseDate((string)$value),
            'datetime' => $this->parseDateTime((string)$value),
            'json' => json_decode((string)$value, true),
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

    private function extractHeaders(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $firstRow = reset($rows);
        return array_keys($firstRow);
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
