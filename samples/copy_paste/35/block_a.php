<?php

declare(strict_types=1);

namespace App\Design\Validation;

use App\Exceptions\ColorValidationException;

final class HexColorValidator
{
    private const SHORT_PATTERN = '/^#?([0-9a-f]{3})$/i';
    private const LONG_PATTERN = '/^#?([0-9a-f]{6})$/i';
    private const EIGHT_DIGIT_PATTERN = '/^#?([0-9a-f]{8})$/i';

    public function isValid(string $color): bool
    {
        return $this->validateFormat($color) !== null;
    }

    public function validate(string $color): string
    {
        $format = $this->validateFormat($color);

        if ($format === null) {
            throw new ColorValidationException("Invalid hex color: {$color}");
        }

        return $this->normalize($color, $format);
    }

    public function validateStrict(string $color): string
    {
        $this->ensureValid($color);

        return $this->normalizeToLongForm($color);
    }

    public function validateShort(string $color): string
    {
        $this->ensureValid($color);
        $this->ensureThreeDigits($color);

        return $this->normalize($color, 'short');
    }

    public function validateLong(string $color): string
    {
        $this->ensureValid($color);
        $this->ensureSixDigits($color);

        return $this->normalize($color, 'long');
    }

    public function validateWithAlpha(string $color): string
    {
        $format = $this->validateFormat($color);

        if ($format === null) {
            throw new ColorValidationException("Invalid hex color: {$color}");
        }

        if ($format === 'short' && strlen($this->stripHash($color))) {
            throw new ColorValidationException('Short form cannot have alpha channel');
        }

        return $this->normalize($color, $format);
    }

    public function parse(string $color): array
    {
        $this->ensureValid($color);
        $clean = $this->stripHash($color);
        $length = strlen($clean);

        if ($length === 3) {
            return $this->parseShortForm($clean);
        }

        if ($length === 6) {
            return $this->parseLongForm($clean);
        }

        if ($length === 8) {
            return $this->parseEightDigitForm($clean);
        }

        throw new ColorValidationException("Cannot parse color: {$color}");
    }

    public function toRgb(string $color): array
    {
        $parsed = $this->parse($color);

        return [
            'red' => $parsed['red'],
            'green' => $parsed['green'],
            'blue' => $parsed['blue'],
            'alpha' => $parsed['alpha'] ?? 1.0,
        ];
    }

    public function toHsl(string $color): array
    {
        $rgb = $this->toRgb($color);

        return $this->rgbToHsl(
            $rgb['red'],
            $rgb['green'],
            $rgb['blue'],
            $rgb['alpha']
        );
    }

    public function isDark(string $color): bool
    {
        $rgb = $this->toRgb($color);
        $luminance = $this->calculateRelativeLuminance($rgb['red'], $rgb['green'], $rgb['blue']);

        return $luminance < 0.5;
    }

    public function isLight(string $color): bool
    {
        return !$this->isDark($color);
    }

    public function getContrastColor(string $color): string
    {
        return $this->isDark($color) ? '#FFFFFF' : '#000000';
    }

    public function lighten(string $color, float $amount): string
    {
        $hsl = $this->toHsl($color);
        $hsl['lightness'] = min(1.0, $hsl['lightness'] + $amount);

        return $this->hslToHex($hsl);
    }

    public function darken(string $color, float $amount): string
    {
        $hsl = $this->toHsl($color);
        $hsl['lightness'] = max(0.0, $hsl['lightness'] - $amount);

        return $this->hslToHex($hsl);
    }

    private function validateFormat(string $color): ?string
    {
        $clean = $this->stripHash($color);
        $length = strlen($clean);

        if (preg_match(self::SHORT_PATTERN, $clean)) {
            return 'short';
        }

        if (preg_match(self::LONG_PATTERN, $clean)) {
            return 'long';
        }

        if (preg_match(self::EIGHT_DIGIT_PATTERN, $clean)) {
            return 'eight_digit';
        }

        return null;
    }

    private function ensureValid(string $color): void
    {
        if (!$this->isValid($color)) {
            throw new ColorValidationException("Invalid hex color: {$color}");
        }
    }

    private function ensureThreeDigits(string $color): void
    {
        if (strlen($this->stripHash($color)) !== 3) {
            throw new ColorValidationException('Color must be in 3-digit format');
        }
    }

    private function ensureSixDigits(string $color): void
    {
        if (strlen($this->stripHash($color)) !== 6) {
            throw new ColorValidationException('Color must be in 6-digit format');
        }
    }

    private function normalize(string $color, string $format): string
    {
        $stripped = $this->stripHash($color);

        if ($format === 'short') {
            return '#' . strtolower($stripped);
        }

        if ($format === 'long') {
            if (strlen($stripped) === 3) {
                $expanded = '';

                for ($i = 0; $i < 3; $i++) {
                    $expanded .= str_repeat($stripped[$i], 2);
                }

                return '#' . strtolower($expanded);
            }

            return '#' . strtolower($stripped);
        }

        return '#' . strtolower($stripped);
    }

    private function normalizeToLongForm(string $color): string
    {
        return $this->normalize($color, 'long');
    }

    private function stripHash(string $color): string
    {
        return ltrim($color, '#');
    }

    private function parseShortForm(string $color): array
    {
        $chars = str_split($color);

        return [
            'red' => hexdec($chars[0] . $chars[0]),
            'green' => hexdec($chars[1] . $chars[1]),
            'blue' => hexdec($chars[2] . $chars[2]),
            'alpha' => 1.0,
        ];
    }

    private function parseLongForm(string $color): array
    {
        return [
            'red' => hexdec(substr($color, 0, 2)),
            'green' => hexdec(substr($color, 2, 2)),
            'blue' => hexdec(substr($color, 4, 2)),
            'alpha' => 1.0,
        ];
    }

    private function parseEightDigitForm(string $color): array
    {
        $alpha = hexdec(substr($color, 6, 2)) / 255;

        return [
            'red' => hexdec(substr($color, 0, 2)),
            'green' => hexdec(substr($color, 2, 2)),
            'blue' => hexdec(substr($color, 4, 2)),
            'alpha' => $alpha,
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
            $h = $s = 0;
        } else {
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
        }

        return [
            'hue' => $h,
            'saturation' => $s,
            'lightness' => $l,
            'alpha' => $a,
        ];
    }

    private function calculateRelativeLuminance(int $r, int $g, int $b): float
    {
        $rs = $r / 255;
        $gs = $g / 255;
        $bs = $b / 255;

        $rLinear = $rs <= 0.03928 ? $rs / 12.92 : pow(($rs + 0.055) / 1.055, 2.4);
        $gLinear = $gs <= 0.03928 ? $gs / 12.92 : pow(($gs + 0.055) / 1.055, 2.4);
        $bLinear = $bs <= 0.03928 ? $bs / 12.92 : pow(($bs + 0.055) / 1.055, 2.4);

        return 0.2126 * $rLinear + 0.7152 * $gLinear + 0.0722 * $bLinear;
    }

    private function hslToHex(array $hsl): string
    {
        $h = $hsl['hue'];
        $s = $hsl['saturation'];
        $l = $hsl['lightness'];

        if ($s === 0) {
            $gray = round(255 * $l);
            return sprintf('#%02X%02X%02X', $gray, $gray, $gray);
        }

        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;

        $r = $this->hueToRgb($p, $q, $h + 1 / 3);
        $g = $this->hueToRgb($p, $q, $h);
        $b = $this->hueToRgb($p, $q, $h - 1 / 3);

        return sprintf('#%02X%02X%02X', round(255 * $r), round(255 * $g), round(255 * $b));
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
