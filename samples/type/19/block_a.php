<?php
declare(strict_types=1);

namespace ReportExporter\Exporters;

use Psr\Log\LoggerInterface;

final class CsvExporter
{
    private const DEFAULT_DELIMITER = ',';
    private const DEFAULT_ENCLOSURE = '"';
    private const DEFAULT_LINE_ENDING = "\r\n";
    private const BOM_PREFIX = "\xEF\xBB\xBF";

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function export(array $data, ExportOptions $options): ExportedFile
    {
        $this->logger->info('Exporting to CSV', [
            'row_count' => count($data),
            'filename' => $options->getFilename(),
        ]);

        if (empty($data)) {
            throw new \InvalidArgumentException('Cannot export empty dataset');
        }

        $headers = $this->extractHeaders($data);
        $rows = $this->formatRows($data, $headers, $options);
        $content = $this->buildContent($headers, $rows, $options);

        $filename = $this->generateFilename($options);
        $filepath = $this->writeToFile($filename, $content);

        return new ExportedFile(
            filepath: $filepath,
            filename: $filename,
            size: strlen($content),
            mimeType: 'text/csv',
            rowCount: count($data),
        );
    }

    private function extractHeaders(array $data): array
    {
        $firstRow = reset($data);
        if (is_array($firstRow)) {
            return array_keys($firstRow);
        }
        return [];
    }

    private function formatRows(array $data, array $headers, ExportOptions $options): array
    {
        $rows = [];
        foreach ($data as $row) {
            $formattedRow = [];
            foreach ($headers as $header) {
                $value = $row[$header] ?? '';
                $formattedRow[] = $this->formatCellValue($value, $options);
            }
            $rows[] = $formattedRow;
        }
        return $rows;
    }

    private function formatCellValue(mixed $value, ExportOptions $options): string
    {
        if ($value === null) {
            return $options->getNullPlaceholder();
        }

        if (is_bool($value)) {
            return $value ? $options->getBooleanTrue() : $options->getBooleanFalse();
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format($options->getDateFormat());
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string)$value;
        }

        $stringValue = (string)$value;

        if ($options->shouldEscapeSpecialCharacters()) {
            $delimiter = $options->getDelimiter();
            $enclosure = $options->getEnclosure();
            if (str_contains($stringValue, $delimiter) ||
                str_contains($stringValue, $enclosure) ||
                str_contains($stringValue, "\n")) {
                $stringValue = str_replace($enclosure, $enclosure . $enclosure, $stringValue);
                $stringValue = $enclosure . $stringValue . $enclosure;
            }
        }

        return $stringValue;
    }

    private function buildContent(array $headers, array $rows, ExportOptions $options): string
    {
        $lines = [];
        $delimiter = $options->getDelimiter();

        $headerLine = implode($delimiter, $headers);
        $lines[] = $headerLine;

        foreach ($rows as $row) {
            $lines[] = implode($delimiter, $row);
        }

        $content = implode($options->getLineEnding(), $lines);

        if ($options->includeBom()) {
            $content = self::BOM_PREFIX . $content;
        }

        return $content;
    }

    private function generateFilename(ExportOptions $options): string
    {
        $extension = 'csv';
        if ($options->getFilename() !== null) {
            $baseName = pathinfo($options->getFilename(), PATHINFO_FILENAME);
            return $baseName . '_' . date('Ymd_His') . '.' . $extension;
        }
        return 'export_' . date('Ymd_His') . '.' . $extension;
    }

    private function writeToFile(string $filename, string $content): string
    {
        $exportDir = sys_get_temp_dir() . '/exports';
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        $filepath = $exportDir . '/' . $filename;
        file_put_contents($filepath, $content);

        $this->logger->debug('CSV exported', [
            'filename' => $filename,
            'size' => strlen($content),
        ]);

        return $filepath;
    }
}
