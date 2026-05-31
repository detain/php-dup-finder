<?php
declare(strict_types=1);

namespace DataImporter\Shared;

interface ParserInterface
{
    public function parse(string $filePath, ImportOptions $options): ParseResult;
}

abstract class BaseParser implements ParserInterface
{
    protected LoggerInterface $logger;

    protected function transformRow(array $row, ImportOptions $options): array
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

    protected function castValue(mixed $value, ?string $type): mixed
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

    protected function parseDate(string $value): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function parseDateTime(string $value): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    protected function validateRows(array $rows, ImportOptions $options): array
    {
        $errors = [];
        $requiredFields = $options->getRequiredFields();

        foreach ($rows as $index => $row) {
            foreach ($requiredFields as $field) {
                if (!isset($row[$field]) || $row[$field] === null) {
                    $errors[] = new ParseError(
                        row: $index,
                        field: $field,
                        message: "Required field '{$field}' is missing or empty",
                    );
                }
            }
        }

        return $errors;
    }

    protected function extractHeaders(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }
        return array_keys(reset($rows));
    }

    abstract protected function processData(mixed $data, ImportOptions $options): array;
}

final class CsvParser extends BaseParser
{
    public function parse(string $filePath, ImportOptions $options): ParseResult
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException('File not found: ' . $filePath);
        }

        $handle = fopen($filePath, 'r');
        $skipLines = min($options->getSkipLines() ?? 0, 100);
        for ($i = 0; $i < $skipLines; $i++) {
            fgets($handle);
        }

        $headers = array_map('trim', str_getcsv(fgets($handle), $options->getDelimiter()));
        $rows = $this->processData($handle, $options, $headers);
        fclose($handle);

        return new ParseResult(
            rows: $rows,
            headers: $headers,
            errors: $this->validateRows($rows, $options),
            parsedAt: new \DateTimeImmutable(),
        );
    }

    protected function processData($handle, ImportOptions $options, array $headers): array
    {
        $rows = [];
        while (($line = fgets($handle)) !== false) {
            if (trim($line) === '') {
                continue;
            }
            $values = str_getcsv(trim($line), $options->getDelimiter());
            if (count($values) === count($headers)) {
                $rows[] = $this->transformRow(array_combine($headers, $values), $options);
            }
        }
        return $rows;
    }
}

final class JsonParser extends BaseParser
{
    public function parse(string $filePath, ImportOptions $options): ParseResult
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException('File not found: ' . $filePath);
        }

        $data = json_decode(file_get_contents($filePath), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON: ' . json_last_error_msg());
        }

        $data = array_slice((array)$data, $options->getSkipLines() ?? 0);
        $rows = $this->processData($data, $options);

        return new ParseResult(
            rows: $rows,
            headers: $this->extractHeaders($rows),
            errors: $this->validateRows($rows, $options),
            parsedAt: new \DateTimeImmutable(),
        );
    }

    protected function processData(mixed $data, ImportOptions $options): array
    {
        return array_map(fn($row) => $this->transformRow((array)$row, $options), $data);
    }
}
