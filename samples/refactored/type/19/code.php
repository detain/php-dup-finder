<?php
declare(strict_types=1);

namespace ReportExporter\Shared;

interface ExporterInterface
{
    public function export(array $data, ExportOptions $options): ExportedFile;
}

abstract class BaseExporter implements ExporterInterface
{
    protected LoggerInterface $logger;
    protected const BOM_PREFIX = "\xEF\xBB\xBF";

    protected function extractHeaders(array $data): array
    {
        $firstRow = reset($data);
        return is_array($firstRow) ? array_keys($firstRow) : [];
    }

    protected function formatCellValue(mixed $value, ExportOptions $options): mixed
    {
        if ($value === null) {
            return $options->getNullPlaceholder();
        }

        if (is_bool($value)) {
            return $value ? $options->getBooleanTrue() : $options->getBooleanFalse();
        }

        if (is_array($value)) {
            return json_encode(array_map(fn($v) => $this->formatCellValue($v, $options), $value));
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format($options->getDateFormat());
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string)$value;
        }

        return $value;
    }

    protected function generateFilename(ExportOptions $options, string $extension): string
    {
        if ($options->getFilename() !== null) {
            $baseName = pathinfo($options->getFilename(), PATHINFO_FILENAME);
            return $baseName . '_' . date('Ymd_His') . '.' . $extension;
        }
        return 'export_' . date('Ymd_His') . '.' . $extension;
    }

    protected function writeToFile(string $filename, string $content): string
    {
        $exportDir = sys_get_temp_dir() . '/exports';
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        $filepath = $exportDir . '/' . $filename;
        file_put_contents($filepath, $content);

        $this->logger->debug('File exported', ['filename' => $filename, 'size' => strlen($content)]);

        return $filepath;
    }

    protected function applyBom(string $content, ExportOptions $options): string
    {
        if ($options->includeBom()) {
            return static::BOM_PREFIX . $content;
        }
        return $content;
    }

    abstract protected function buildContent(array $data, ExportOptions $options): string;
}

final class CsvExporter extends BaseExporter
{
    public function export(array $data, ExportOptions $options): ExportedFile
    {
        if (empty($data)) {
            throw new \InvalidArgumentException('Cannot export empty dataset');
        }

        $headers = $this->extractHeaders($data);
        $content = $this->buildContent($data, $options);
        $filename = $this->generateFilename($options, 'csv');
        $filepath = $this->writeToFile($filename, $content);

        return new ExportedFile(
            filepath: $filepath,
            filename: $filename,
            size: strlen($content),
            mimeType: 'text/csv',
            rowCount: count($data),
        );
    }

    protected function buildContent(array $data, ExportOptions $options): string
    {
        $delimiter = $options->getDelimiter();
        $lines = [];

        $firstRow = reset($data);
        $headers = array_keys($firstRow);
        $lines[] = implode($delimiter, $headers);

        foreach ($data as $row) {
            $formattedRow = array_map(fn($v) => $this->formatCellValue($v, $options), array_values($row));
            $lines[] = implode($delimiter, $formattedRow);
        }

        $content = implode($options->getLineEnding(), $lines);
        return $this->applyBom($content, $options);
    }
}

final class JsonExporter extends BaseExporter
{
    public function export(array $data, ExportOptions $options): ExportedFile
    {
        if (empty($data)) {
            throw new \InvalidArgumentException('Cannot export empty dataset');
        }

        $content = $this->buildContent($data, $options);
        $filename = $this->generateFilename($options, 'json');
        $filepath = $this->writeToFile($filename, $content);

        return new ExportedFile(
            filepath: $filepath,
            filename: $filename,
            size: strlen($content),
            mimeType: 'application/json',
            rowCount: count($data),
        );
    }

    protected function buildContent(array $data, ExportOptions $options): string
    {
        $formatted = array_map(fn($row) => array_map(fn($v) => $this->formatCellValue($v, $options), $row), $data);
        $content = json_encode($formatted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return $this->applyBom($content, $options);
    }
}
