<?php

declare(strict_types=1);

namespace App\Print\Services;

use App\Exceptions\PrintLayoutException;

final class SheetMetricsCalculator
{
    public const ISO_A4_WIDTH = 210;
    public const ISO_A4_HEIGHT = 297;
    public const ISO_A3_WIDTH = 297;
    public const ISO_A3_HEIGHT = 420;
    public const ISO_A5_WIDTH = 148;
    public const ISO_A5_HEIGHT = 210;
    public const US_LETTER_WIDTH = 215.9;
    public const US_LETTER_HEIGHT = 279.4;

    private const INCH_TO_MM = 25.4;
    private const INCH_TO_POINTS = 72;

    public function getDimensions(string $standard, string $direction = 'portrait'): array
    {
        $base = $this->resolveStandardDimensions($standard);

        return $this->maybeSwapForLandscape($base, $direction);
    }

    public function getPixelDimensions(string $standard, int $dpi, string $direction = 'portrait'): array
    {
        $mm = $this->getDimensions($standard, $direction);
        $factor = $dpi / self::INCH_TO_MM;

        return [
            'px_width' => (int) round($mm['width'] * $factor),
            'px_height' => (int) round($mm['height'] * $factor),
        ];
    }

    public function getPostscriptDimensions(string $standard, string $direction = 'portrait'): array
    {
        $mm = $this->getDimensions($standard, $direction);
        $factor = self::INCH_TO_POINTS / self::INCH_TO_MM;

        return [
            'pt_width' => $mm['width'] * $factor,
            'pt_height' => $mm['height'] * $factor,
        ];
    }

    public function getPrintableRegion(
        string $standard,
        float $topM,
        float $rightM,
        float $bottomM,
        float $leftM,
        string $direction = 'portrait'
    ): array {
        $dims = $this->getDimensions($standard, $direction);

        $innerW = $dims['width'] - $leftM - $rightM;
        $innerH = $dims['height'] - $topM - $bottomM;

        return [
            'outer_width' => $dims['width'],
            'outer_height' => $dims['height'],
            'inner_width' => max(0, $innerW),
            'inner_height' => max(0, $innerH),
            'margin_top' => $topM,
            'margin_right' => $rightM,
            'margin_bottom' => $bottomM,
            'margin_left' => $leftM,
        ];
    }

    public function getScaledContent(string $srcStandard, string $dstStandard, string $direction = 'portrait'): float
    {
        $src = $this->getDimensions($srcStandard, $direction);
        $dst = $this->getDimensions($dstStandard, $direction);

        $wScale = $dst['width'] / $src['width'];
        $hScale = $dst['height'] / $src['height'];

        return min($wScale, $hScale);
    }

    public function getImposition(array $specs, string $standard): array
    {
        $base = $this->getDimensions($standard);
        $count = count($specs);
        $cols = (int) ceil(sqrt($count));
        $rows = (int) ceil($count / $cols);

        return [
            'source_width' => $base['width'],
            'source_height' => $base['height'],
            'grid_cols' => $cols,
            'grid_rows' => $rows,
            'cell_width' => $base['width'] / $cols,
            'cell_height' => $base['height'] / $rows,
            'items' => $specs,
        ];
    }

    private function resolveStandardDimensions(string $standard): array
    {
        return match (strtoupper($standard)) {
            'A4' => ['width' => self::ISO_A4_WIDTH, 'height' => self::ISO_A4_HEIGHT],
            'A3' => ['width' => self::ISO_A3_WIDTH, 'height' => self::ISO_A3_HEIGHT],
            'A5' => ['width' => self::ISO_A5_WIDTH, 'height' => self::ISO_A5_HEIGHT],
            'LETTER' => ['width' => self::US_LETTER_WIDTH, 'height' => self::US_LETTER_HEIGHT],
            default => throw new PrintLayoutException("Unsupported paper standard: {$standard}"),
        };
    }

    private function maybeSwapForLandscape(array $dims, string $direction): array
    {
        if (strtolower($direction) === 'landscape') {
            return ['width' => $dims['height'], 'height' => $dims['width']];
        }

        return $dims;
    }
}
