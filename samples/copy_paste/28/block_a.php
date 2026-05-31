<?php

declare(strict_types=1);

namespace App\Documents\Processing;

use App\Exceptions\PageLayoutException;

final class PdfPageSizeCalculator
{
    public const A4_WIDTH_MM = 210;
    public const A4_HEIGHT_MM = 297;
    public const A3_WIDTH_MM = 297;
    public const A3_HEIGHT_MM = 420;
    public const A5_WIDTH_MM = 148;
    public const A5_HEIGHT_MM = 210;
    public const LETTER_WIDTH_MM = 215.9;
    public const LETTER_HEIGHT_MM = 279.4;
    public const LEGAL_WIDTH_MM = 215.9;
    public const LEGAL_HEIGHT_MM = 355.6;

    private const DPI = 72;
    private const MM_TO_INCH = 25.4;

    public function calculateDimensions(string $pageSize, string $orientation = 'portrait'): array
    {
        $dimensions = $this->getBaseDimensions($pageSize);

        return $this->applyOrientation($dimensions, $orientation);
    }

    public function calculatePixels(string $pageSize, int $dpi, string $orientation = 'portrait'): array
    {
        $mm = $this->calculateDimensions($pageSize, $orientation);

        return $this->convertMmToPixels($mm, $dpi);
    }

    public function calculatePoints(string $pageSize, string $orientation = 'portrait'): array
    {
        $mm = $this->calculateDimensions($pageSize, $orientation);

        return $this->convertMmToPoints($mm);
    }

    public function calculateWithMargins(
        string $pageSize,
        float $topMargin,
        float $rightMargin,
        float $bottomMargin,
        float $leftMargin,
        string $orientation = 'portrait'
    ): array {
        $dimensions = $this->calculateDimensions($pageSize, $orientation);

        $contentWidth = $dimensions['width'] - $leftMargin - $rightMargin;
        $contentHeight = $dimensions['height'] - $topMargin - $bottomMargin;

        return [
            'page' => $dimensions,
            'margins' => [
                'top' => $topMargin,
                'right' => $rightMargin,
                'bottom' => $bottomMargin,
                'left' => $leftMargin,
            ],
            'content' => [
                'width' => max(0, $contentWidth),
                'height' => max(0, $contentHeight),
            ],
        ];
    }

    public function calculatePrintArea(
        string $pageSize,
        array $margins,
        string $orientation = 'portrait'
    ): array {
        $dimensions = $this->calculateDimensions($pageSize, $orientation);
        $top = $margins['top'] ?? 0;
        $right = $margins['right'] ?? 0;
        $bottom = $margins['bottom'] ?? 0;
        $left = $margins['left'] ?? 0;

        return [
            'width' => max(0, $dimensions['width'] - $left - $right),
            'height' => max(0, $dimensions['height'] - $top - $bottom),
            'origin_x' => $left,
            'origin_y' => $top,
        ];
    }

    public function calculateScalingFactor(
        string $sourcePageSize,
        string $targetPageSize,
        string $orientation = 'portrait'
    ): float {
        $source = $this->calculateDimensions($sourcePageSize, $orientation);
        $target = $this->calculateDimensions($targetPageSize, $orientation);

        $widthRatio = $target['width'] / $source['width'];
        $heightRatio = $target['height'] / $source['height'];

        return min($widthRatio, $heightRatio);
    }

    public function calculateNupLayout(int $pagesPerSheet, string $pageSize, string $orientation = 'portrait'): array
    {
        $pageDims = $this->calculateDimensions($pageSize, $orientation);

        $layout = $this->determineNupGrid($pagesPerSheet);

        return [
            'pages_per_sheet' => $pagesPerSheet,
            'rows' => $layout['rows'],
            'columns' => $layout['columns'],
            'page_width' => $pageDims['width'] / $layout['columns'],
            'page_height' => $pageDims['height'] / $layout['rows'],
            'orientation' => $orientation,
        ];
    }

    public function calculatePosterLayout(int $tilesX, int $tilesY, string $pageSize): array
    {
        $pageDims = $this->getBaseDimensions($pageSize);

        return [
            'total_width' => $pageDims['width'] * $tilesX,
            'total_height' => $pageDims['height'] * $tilesY,
            'tiles_x' => $tilesX,
            'tiles_y' => $tilesY,
            'page_width' => $pageDims['width'],
            'page_height' => $pageDims['height'],
        ];
    }

    private function getBaseDimensions(string $pageSize): array
    {
        return match (strtoupper($pageSize)) {
            'A4' => ['width' => self::A4_WIDTH_MM, 'height' => self::A4_HEIGHT_MM],
            'A3' => ['width' => self::A3_WIDTH_MM, 'height' => self::A3_HEIGHT_MM],
            'A5' => ['width' => self::A5_WIDTH_MM, 'height' => self::A5_HEIGHT_MM],
            'LETTER' => ['width' => self::LETTER_WIDTH_MM, 'height' => self::LETTER_HEIGHT_MM],
            'LEGAL' => ['width' => self::LEGAL_WIDTH_MM, 'height' => self::LEGAL_HEIGHT_MM],
            default => throw new PageLayoutException("Unknown page size: {$pageSize}"),
        };
    }

    private function applyOrientation(array $dimensions, string $orientation): array
    {
        if (strtolower($orientation) === 'landscape') {
            return [
                'width' => $dimensions['height'],
                'height' => $dimensions['width'],
            ];
        }

        return $dimensions;
    }

    private function convertMmToPixels(array $mm, int $dpi): array
    {
        $pixelsPerMm = $dpi / self::MM_TO_INCH;

        return [
            'width' => (int) round($mm['width'] * $pixelsPerMm),
            'height' => (int) round($mm['height'] * $pixelsPerMm),
        ];
    }

    private function convertMmToPoints(array $mm): array
    {
        $pointsPerMm = self::DPI / self::MM_TO_INCH;

        return [
            'width' => $mm['width'] * $pointsPerMm,
            'height' => $mm['height'] * $pointsPerMm,
        ];
    }

    private function determineNupGrid(int $pagesPerSheet): array
    {
        return match ($pagesPerSheet) {
            1 => ['rows' => 1, 'columns' => 1],
            2 => ['rows' => 1, 'columns' => 2],
            4 => ['rows' => 2, 'columns' => 2],
            6 => ['rows' => 2, 'columns' => 3],
            8 => ['rows' => 2, 'columns' => 4],
            9 => ['rows' => 3, 'columns' => 3],
            12 => ['rows' => 3, 'columns' => 4],
            16 => ['rows' => 4, 'columns' => 4],
            default => throw new PageLayoutException("Unsupported pages per sheet: {$pagesPerSheet}"),
        };
    }
}
