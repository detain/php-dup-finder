<?php

namespace App\Services\Print;

final class PageSizeConfig
{
    public readonly float $widthMm;
    public readonly float $heightMm;

    private const SIZES = [
        'A4' => [210, 297],
        'A3' => [297, 420],
        'A5' => [148, 210],
        'LETTER' => [215.9, 279.4],
    ];

    public function __construct(string $size, string $orientation = 'portrait')
    {
        $size = strtoupper($size);

        if (!isset(self::SIZES[$size])) {
            throw new \InvalidArgumentException("Unknown size: {$size}");
        }

        [$w, $h] = self::SIZES[$size];

        if (strtolower($orientation) === 'landscape') {
            $this->widthMm = $h;
            $this->heightMm = $w;
        } else {
            $this->widthMm = $w;
            $this->heightMm = $h;
        }
    }

    public function toPixels(int $dpi): array
    {
        $scale = $dpi / 25.4;

        return [
            'width' => (int) round($this->widthMm * $scale),
            'height' => (int) round($this->heightMm * $scale),
        ];
    }

    public function toPoints(): array
    {
        $scale = 72 / 25.4;

        return [
            'width' => $this->widthMm * $scale,
            'height' => $this->heightMm * $scale,
        ];
    }

    public function usableArea(float $top, float $right, float $bottom, float $left): array
    {
        return [
            'width' => max(0, $this->widthMm - $left - $right),
            'height' => max(0, $this->heightMm - $top - $bottom),
        ];
    }
}

final class PrintService
{
    public function compute(PageSizeConfig $page, array $margins = []): array
    {
        $usable = $page->usableArea(
            $margins['top'] ?? 0,
            $margins['right'] ?? 0,
            $margins['bottom'] ?? 0,
            $margins['left'] ?? 0
        );

        return [
            'page' => ['width' => $page->widthMm, 'height' => $page->heightMm],
            'usable' => $usable,
        ];
    }
}
