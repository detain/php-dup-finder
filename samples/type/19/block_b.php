<?php
declare(strict_types=1);

namespace ReportExporter\Exporters;

use Psr\Log\LoggerInterface;

final class JsonExporter
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
        $this->logger->info('Exporting to JSON', [
            'row_count' => count($data),
            'filename' => $options->getFilename(),
        ]);

        if (empty($data)) {
            throw new \InvalidArgumentException('Cannot export empty dataset');
        }

        $formattedData = $this->formatData($data, $options);
        $content = $this->buildContent($formattedData, $options);

        $filename = $this->generateFilename($options);
        $filepath = $this->writeToFile($filename, $content);

        return new ExportedFile(
            filepath: $filepath,
            filename: $filename,
            size: strlen($content),
            mimeType: 'application/json',
            rowCount: count($data),
        );
    }

    private function formatData(array $data, ExportOptions $options): array
    {
        return array_map(function ($row) use ($options) {
            $formatted = [];
            foreach ($row as $key => $value) {
                $formatted[$key] = $this->formatCellValue($value, $options);
            }
            return $formatted;
        }, $data);
    }

    private function formatCellValue(mixed $value, ExportOptions $options): mixed
    {
        if ($value === null) {
            if ($options->shouldIncludeNullAsNull()) {
                return null;
            }
            return $options->getNullPlaceholder();
        }

        if (is_bool($value)) {
            return $value ? $options->getBooleanTrue() : $options->getBooleanFalse();
        }

        if (is_array($value)) {
            return array_map(fn($v) => $this->formatCellValue($v, $options), $value);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format($options->getDateFormat());
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string)$value;
        }

        return $value;
    }

    private function buildContent(array $formattedData, ExportOptions $options): string
    {
        $jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE;

        if ($options->shouldPrettyPrint()) {
            $jsonFlags |= JSON_PRETTY_PRINT;
        }

        $content = json_encode($formattedData, $jsonFlags);

        if ($content === false) {
            throw new \RuntimeException('Failed to encode JSON: ' . json_last_error_msg());
        }

        if ($options->includeBom()) {
            $content = self::BOM_PREFIX . $content;
        }

        return $content;
    }

    private function generateFilename(ExportOptions $options): string
    {
        $extension = 'json';
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

        $this->logger->debug('JSON exported', [
            'filename' => $filename,
            'size' => strlen($content),
        ]);

        return $filepath;
    }
}
