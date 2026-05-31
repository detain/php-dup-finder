<?php

declare(strict_types=1);

namespace App\Styling;

use App\Exceptions\ColorFormatException;

final class ColorHexParser
{
    public function valid(string $hex): bool
    {
        return $this->identify($hex) !== null;
    }

    public function parse(string $hex): string
    {
        if (!$this->valid($hex)) {
            throw new ColorFormatException("Invalid color format: {$hex}");
        }

        return $this->toStandard($hex);
    }

    public function parseStrict(string $hex): string
    {
        if (!$this->valid($hex)) {
            throw new ColorFormatException("Invalid color format: {$hex}");
        }

        return $this->toSixDigits($hex);
    }

    public function parseCompact(string $hex): string
    {
        if (!$this->valid($hex)) {
            throw new ColorFormatException("Invalid color format: {$hex}");
        }

        if (strlen($this->strip($hex)) !== 3) {
            throw new ColorFormatException('Color is not in compact form');
        }

        return $this->toStandard($hex);
    }

    public function parseExtended(string $hex): string
    {
        if (!$this->valid($hex)) {
            throw new ColorFormatException("Invalid color format: {$hex}");
        }

        if (strlen($this->strip($hex)) !== 6) {
            throw new ColorFormatException('Color is not in extended form');
        }

        return $this->toSixDigits($hex);
    }

    public function parseWithAlpha(string $hex): string
    {
        if (!$this->valid($hex)) {
            throw new ColorFormatException("Invalid color format: {$hex}");
        }

        if (strlen($this->strip($hex)) === 3) {
            throw new ColorFormatException('Compact form cannot have alpha channel');
        }

        return $this->toSixDigits($hex);
    }

    public function breakdown(string $hex): array
    {
        if (!$this->valid($hex)) {
            throw new ColorFormatException("Invalid color format: {$hex}");
        }

        $clean = $this->strip($hex);
        $len = strlen($clean);

        if ($len === 3) {
            return $this->breakdownCompact($clean);
        }

        if ($len === 6) {
            return $this->breakdownSixDigits($clean);
        }

        if ($len === 8) {
            return $this->breakdownEightDigits($clean);
        }

        throw new ColorFormatException("Cannot breakdown: {$hex}");
    }

    public function components(string $hex): array
    {
        $parts = $this->breakdown($hex);

        return [
            'red' => $parts['red'],
            'green' => $parts['green'],
            'blue' => $parts['blue'],
            'alpha' => $parts['alpha'] ?? 1.0,
        ];
    }

    public function toRgb(string $hex): array
    {
        return $this->components($hex);
    }

    public function toHsl(string $hex): array
    {
        $rgb = $this->components($hex);

        return $this->rgbToHsl($rgb['red'], $rgb['green'], $rgb['blue'], $rgb['alpha']);
    }

    public function isMuted(string $hex): bool
    {
        $rgb = $this->components($hex);

        return $this->relativeLuminance($rgb['red'], $rgb['green'], $rgb['blue']) < 0.5;
    }

    public function isVibrant(string $hex): bool
    {
        return !$this->isMuted($hex);
    }

    public function inverse(string $hex): string
    {
        $rgb = $this->components($hex);

        return sprintf('#%02X%02X%02X', 255 - $rgb['red'], 255 - $rgb['green'], 255 - $rgb['blue']);
    }

    public function saturate(string $hex, float $amount): string
    {
        $hsl = $this->toHsl($hex);
        $hsl['s'] = min(1, $hsl['s'] + $amount);

        return $this->hslToHex($hsl);
    }

    public function desaturate(string $hex, float $amount): string
    {
        $hsl = $this->toHsl($hex);
        $hsl['s'] = max(0, $hsl['s'] - $amount);

        return $this->hslToHex($hsl);
    }

    private function identify(string $hex): ?string
    {
        $stripped = $this->strip($hex);

        if (preg_match('/^[0-9a-f]{3}$/i', $stripped)) {
            return 'compact';
        }

        if (preg_match('/^[0-9a-f]{6}$/i', $stripped)) {
            return 'six_digit';
        }

        if (preg_match('/^[0-9a-f]{8}$/i', $stripped)) {
            return 'eight_digit';
        }

        return null;
    }

    private function strip(string $hex): string
    {
        return ltrim($hex, '#');
    }

    private function toStandard(string $hex): string
    {
        $clean = $this->strip($hex);

        if (strlen($clean) === 3) {
            $expanded = '';

            for ($i = 0; $i < 3; $i++) {
                $expanded .= str_repeat($clean[$i], 2);
            }

            return '#' . strtolower($expanded);
        }

        return '#' . strtolower($clean);
    }

    private function toSixDigits(string $hex): string
    {
        return $this->toStandard($hex);
    }

    private function breakdownCompact(string $color): array
    {
        return [
            'red' => hexdec($color[0] . $color[0]),
            'green' => hexdec($color[1] . $color[1]),
            'blue' => hexdec($color[2] . $color[2]),
            'alpha' => 1.0,
        ];
    }

    private function breakdownSixDigits(string $color): array
    {
        return [
            'red' => hexdec(substr($color, 0, 2)),
            'green' => hexdec(substr($color, 2, 2)),
            'blue' => hexdec(substr($color, 4, 2)),
            'alpha' => 1.0,
        ];
    }

    private function breakdownEightDigits(string $color): array
    {
        return [
            'red' => hexdec(substr($color, 0, 2)),
            'green' => hexdec(substr($color, 2, 2)),
            'blue' => hexdec(substr($color, 4, 2)),
            'alpha' => hexdec(substr($color, 6, 2)) / 255,
        ];
    }

    private function rgbToHsl(int $r, int $g, int $b, float $a = 1.0): array
    {
        $r /= 255;
        $g /= 255;
        $b /= 255;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;

        if ($max === $min) {
            return ['h' => 0, 's' => 0, 'l' => $l, 'a' => $a];
        }

        $d = $max - $min;

        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

        switch ($max) {
            case $r:
                $h = (($g - $b) / $d + ($g < $b ? 6 : 0)) / 6;
                break;
            case $g:
                $h = (($b - $r) / $d + 2) / 6;
                break;
            default:
                $h = (($r - $g) / $d + 4) / 6;
                break;
        }

        return ['h' => $h, 's' => $s, 'l' => $l, 'a' => $a];
    }

    private function relativeLuminance(int $r, int $g, int $b): float
    {
        $rs = $r / 255;
        $gs = $g / 255;
        $bs = $b / 255;

        $rLin = $rs <= 0.03928 ? $rs / 12.92 : pow(($rs + 0.055) / 1.055, 2.4);
        $gLin = $gs <= 0.03928 ? $gs / 12.92 : pow(($gs + 0.055) / 1.055, 2.4);
        $bLin = $bs <= 0.03928 ? $bs / 12.92 : pow(($bs + 0.055) / 1.055, 2.4);

        return 0.2126 * $rLin + 0.7152 * $gLin + 0.0722 * $bLin;
    }

    private function hslToHex(array $hsl): string
    {
        $h = $hsl['h'];
        $s = $hsl['s'];
        $l = $hsl['l'];

        if ($s === 0) {
            $gray = (int) round(255 * $l);

            return sprintf('#%02X%02X%02X', $gray, $gray, $gray);
        }

        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;

        return sprintf(
            '#%02X%02X%02X',
            (int) round(255 * $this->hueToRgb($p, $q, $h + 1 / 3)),
            (int) round(255 * $this->hueToRgb($p, $q, $h)),
            (int) round(255 * $this->hueToRgb($p, $q, $h - 1 / 3))
        );
    }

    private function hueToRgb(float $p, float $q, float $t): float
    {
        if ($t < 0) {
            $t += 1;
        }

        if ($t > 1) {
            $t -= 1;
        }

        if ($t < 1 / 6) {
            return $p + ($q - $p) * 6 * $t;
        }

        if ($t < 1 / 2) {
            return $q;
        }

        if ($t < 2 / 3) {
            return $p + ($q - $p) * (2 / 3 - $t) * 6;
        }

        return $p;
    }
}
