<?php

declare(strict_types=1);

namespace App\Publishing\Layout;

use App\Exceptions\DocumentLayoutException;

final class PageDimensionComputer
{
    public const SIZE_A4_W = 210;
    public const SIZE_A4_H = 297;
    public const SIZE_A3_W = 297;
    public const SIZE_A3_H = 420;
    public const SIZE_A5_W = 148;
    public const SIZE_A5_H = 210;
    public const SIZE_LETTER_W = 215.9;
    public const SIZE_LETTER_H = 279.4;

    private const POINTS_PER_INCH = 72;
    private const MM_PER_INCH = 25.4;

    public function computePageMetrics(string $format, string $orientation = 'portrait'): array
    {
        $base = $this->fetchFormatDimensions($format);

        return $this->adjustForOrientation($base, $orientation);
    }

    public function computePixelMetrics(string $format, int $resolution, string $orientation = 'portrait'): array
    {
        $metrics = $this->computePageMetrics($format, $orientation);
        $scale = $resolution / self::MM_PER_INCH;

        return [
            'width_px' => (int) round($metrics['width_mm'] * $scale),
            'height_px' => (int) round($metrics['height_mm'] * $scale),
        ];
    }

    public function computePointMetrics(string $format, string $orientation = 'portrait'): array
    {
        $metrics = $this->computePageMetrics($format, $orientation);
        $ptScale = self::POINTS_PER_INCH / self::MM_PER_INCH;

        return [
            'width_pt' => $metrics['width_mm'] * $ptScale,
            'height_pt' => $metrics['height_mm'] * $ptScale,
        ];
    }

    public function computeUsableArea(
        string $format,
        float $top,
        float $right,
        float $bottom,
        float $left,
        string $orientation = 'portrait'
    ): array {
        $page = $this->computePageMetrics($format, $orientation);

        $usableW = $page['width_mm'] - $left - $right;
        $usableH = $page['height_mm'] - $top - $bottom;

        return [
            'page_width_mm' => $page['width_mm'],
            'page_height_mm' => $page['height_mm'],
            'usable_width_mm' => max(0, $usableW),
            'usable_height_mm' => max(0, $usableH),
            'margins' => ['top' => $top, 'right' => $right, 'bottom' => $bottom, 'left' => $left],
        ];
    }

    public function computeContentBox(string $format, array $marginSpec, string $orientation = 'portrait'): array
    {
        $page = $this->computePageMetrics($format, $orientation);
        $top = $marginSpec['top'] ?? 0;
        $right = $marginSpec['right'] ?? 0;
        $bottom = $marginSpec['bottom'] ?? 0;
        $left = $marginSpec['left'] ?? 0;

        return [
            'x' => $left,
            'y' => $top,
            'width' => max(0, $page['width_mm'] - $left - $right),
            'height' => max(0, $page['height_mm'] - $top - $bottom),
        ];
    }

    public function computeScaleToFit(string $sourceFormat, string $destFormat, string $orientation = 'portrait'): float
    {
        $source = $this->computePageMetrics($sourceFormat, $orientation);
        $dest = $this->computePageMetrics($destFormat, $orientation);

        $wRatio = $dest['width_mm'] / $source['width_mm'];
        $hRatio = $dest['height_mm'] / $source['height_mm'];

        return min($wRatio, $hRatio);
    }

    public function computeBookletLayout(string $format, int $sheets, string $orientation = 'portrait'): array
    {
        $page = $this->computePageMetrics($format, $orientation);
        $pagesPerSignature = 4;

        return [
            'sheet_width_mm' => $page['width_mm'],
            'sheet_height_mm' => $page['height_mm'],
            'pages_per_sheet' => $pagesPerSignature,
            'total_sheets' => $sheets,
            'total_pages' => $sheets * $pagesPerSignature,
            'orientation' => $orientation,
        ];
    }

    private function fetchFormatDimensions(string $format): array
    {
        return match (strtoupper($format)) {
            'A4' => ['width_mm' => self::SIZE_A4_W, 'height_mm' => self::SIZE_A4_H],
            'A3' => ['width_mm' => self::SIZE_A3_W, 'height_mm' => self::SIZE_A3_H],
            'A5' => ['width_mm' => self::SIZE_A5_W, 'height_mm' => self::SIZE_A5_H],
            'LETTER' => ['width_mm' => self::SIZE_LETTER_W, 'height_mm' => self::SIZE_LETTER_H],
            default => throw new DocumentLayoutException("Unsupported format: {$format}"),
        };
    }

    private function adjustForOrientation(array $base, string $orientation): array
    {
        if (strtolower($orientation) === 'landscape') {
            return [
                'width_mm' => $base['height_mm'],
                'height_mm' => $base['width_mm'],
            ];
        }

        return $base;
    }
}
