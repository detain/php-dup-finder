<?php
declare(strict_types=1);

namespace ReportExporter\Exporters;

use Psr\Log\LoggerInterface;

final class XmlExporter
{
    private const DEFAULT_DELIMITER = ',';
    private const DEFAULT_ENCLOSURE = '"';
    private const DEFAULT_LINE_ENDING = "\r\n";
    private const BOM_PREFIX = "\xEF\xBB\xBF";
    private const DEFAULT_ROOT_ELEMENT = 'data';
    private const DEFAULT_ITEM_ELEMENT = 'item';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function export(array $data, ExportOptions $options): ExportedFile
    {
        $this->logger->info('Exporting to XML', [
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
            mimeType: 'application/xml',
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

    private function buildContent(array $formattedData, ExportOptions $options): string
    {
        $rootElement = $options->getRootElementName() ?? self::DEFAULT_ROOT_ELEMENT;
        $itemElement = $options->getItemElementName() ?? self::DEFAULT_ITEM_ELEMENT;

        $xml = new \SimpleXMLElement("<?xml version=\"1.0\" encoding=\"UTF-8\"?><{$rootElement}/>");

        foreach ($formattedData as $row) {
            $item = $xml->addChild($itemElement);
            foreach ($row as $key => $value) {
                $safeKey = $this->sanitizeXmlTagName($key);
                if (is_scalar($value)) {
                    $item->addChild($safeKey, htmlspecialchars((string)$value, ENT_XML1, 'UTF-8'));
                }
            }
        }

        $content = $xml->asXML();

        if ($content === false) {
            throw new \RuntimeException('Failed to generate XML');
        }

        if ($options->includeBom()) {
            $content = self::BOM_PREFIX . $content;
        }

        return $content;
    }

    private function sanitizeXmlTagName(string $name): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
        $sanitized = preg_replace('/^[0-9]/', '_$&', $sanitized);
        return $sanitized ?: 'item';
    }

    private function generateFilename(ExportOptions $options): string
    {
        $extension = 'xml';
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

        $this->logger->debug('XML exported', [
            'filename' => $filename,
            'size' => strlen($content),
        ]);

        return $filepath;
    }
}
