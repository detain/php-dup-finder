<?php

declare(strict_types=1);

namespace App\Graphics;

use App\Exceptions\ColorException;

final class ColorCodeChecker
{
    public function checkValid(string $code): bool
    {
        return $this->determineFormat($code) !== null;
    }

    public function verify(string $code): string
    {
        if (!$this->checkValid($code)) {
            throw new ColorException("Color code invalid: {$code}");
        }

        return $this->canonicalize($code);
    }

    public function verifyCompact(string $code): string
    {
        if (!$this->checkValid($code)) {
            throw new ColorException("Color code invalid: {$code}");
        }

        if (strlen($this->removePrefix($code)) !== 3) {
            throw new ColorException('Requires 3-digit hex format');
        }

        return $this->canonicalize($code);
    }

    public function verifyFull(string $code): string
    {
        if (!$this->checkValid($code)) {
            throw new ColorException("Color code invalid: {$code}");
        }

        if (strlen($this->removePrefix($code)) !== 6) {
            throw new ColorException('Requires 6-digit hex format');
        }

        return $this->canonicalize($code);
    }

    public function verifyWithTransparency(string $code): string
    {
        $format = $this->determineFormat($code);

        if ($format === null) {
            throw new ColorException("Color code invalid: {$code}");
        }

        if ($format === 'short') {
            throw new ColorException('Short form cannot support transparency');
        }

        return $this->canonicalize($code);
    }

    public function extractComponents(string $code): array
    {
        if (!$this->checkValid($code)) {
            throw new ColorException("Color code invalid: {$code}");
        }

        $clean = $this->removePrefix($code);
        $len = strlen($clean);

        if ($len === 3) {
            return $this->expandShortForm($clean);
        }

        if ($len === 6) {
            return $this->decodeSixDigits($clean);
        }

        if ($len === 8) {
            return $this->decodeEightDigits($clean);
        }

        throw new ColorException("Cannot extract components from: {$code}");
    }

    public function toRgbArray(string $code): array
    {
        $parts = $this->extractComponents($code);

        return [
            'r' => $parts['r'],
            'g' => $parts['g'],
            'b' => $parts['b'],
            'a' => $parts['a'] ?? 1.0,
        ];
    }

    public function toHslArray(string $code): array
    {
        $rgb = $this->toRgbArray($code);

        return $this->convertRgbToHsl($rgb['r'], $rgb['g'], $rgb['b'], $rgb['a']);
    }

    public function isBright(string $code): bool
    {
        $rgb = $this->toRgbArray($code);
        $luma = $this->computeLuminance($rgb['r'], $rgb['g'], $rgb['b']);

        return $luma > 0.179;
    }

    public function isDim(string $code): bool
    {
        return !$this->isBright($code);
    }

    public function contrastingShade(string $code): string
    {
        return $this->isBright($code) ? '#000000' : '#FFFFFF';
    }

    public function adjustBrightness(string $code, float $delta): string
    {
        $hsl = $this->toHslArray($code);
        $hsl['l'] = max(0, min(1, $hsl['l'] + $delta));

        return $this->convertHslToHex($hsl);
    }

    private function determineFormat(string $code): ?string
    {
        $clean = $this->removePrefix($code);

        if (preg_match('/^[0-9a-f]{3}$/i', $clean)) {
            return 'short';
        }

        if (preg_match('/^[0-9a-f]{6}$/i', $clean)) {
            return 'long';
        }

        if (preg_match('/^[0-9a-f]{8}$/i', $clean)) {
            return 'eight';
        }

        return null;
    }

    private function removePrefix(string $code): string
    {
        return ltrim($code, '#');
    }

    private function canonicalize(string $code): string
    {
        $clean = $this->removePrefix($code);

        if (strlen($clean) === 3) {
            $expanded = '';

            for ($i = 0; $i < 3; $i++) {
                $expanded .= str_repeat($clean[$i], 2);
            }

            return '#' . strtolower($expanded);
        }

        return '#' . strtolower($clean);
    }

    private function expandShortForm(string $color): array
    {
        return [
            'r' => hexdec($color[0] . $color[0]),
            'g' => hexdec($color[1] . $color[1]),
            'b' => hexdec($color[2] . $color[2]),
            'a' => 1.0,
        ];
    }

    private function decodeSixDigits(string $color): array
    {
        return [
            'r' => hexdec(substr($color, 0, 2)),
            'g' => hexdec(substr($color, 2, 2)),
            'b' => hexdec(substr($color, 4, 2)),
            'a' => 1.0,
        ];
    }

    private function decodeEightDigits(string $color): array
    {
        return [
            'r' => hexdec(substr($color, 0, 2)),
            'g' => hexdec(substr($color, 2, 2)),
            'b' => hexdec(substr($color, 4, 2)),
            'a' => hexdec(substr($color, 6, 2)) / 255,
        ];
    }

    private function convertRgbToHsl(int $r, int $g, int $b, float $a = 1.0): array
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

    private function computeLuminance(int $r, int $g, int $b): float
    {
        $rs = $r / 255;
        $gs = $g / 255;
        $bs = $b / 255;

        $rLin = $rs <= 0.03928 ? $rs / 12.92 : pow(($rs + 0.055) / 1.055, 2.4);
        $gLin = $gs <= 0.03928 ? $gs / 12.92 : pow(($gs + 0.055) / 1.055, 2.4);
        $bLin = $bs <= 0.03928 ? $bs / 12.92 : pow(($bs + 0.055) / 1.055, 2.4);

        return 0.2126 * $rLin + 0.7152 * $gLin + 0.0722 * $bLin;
    }

    private function convertHslToHex(array $hsl): string
    {
        $h = $hsl['h'];
        $s = $hsl['s'];
        $l = $hsl['l'];

        if ($s === 0) {
            $v = round(255 * $l);

            return sprintf('#%02X%02X%02X', $v, $v, $v);
        }

        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;

        return sprintf(
            '#%02X%02X%02X',
            round(255 * $this->hueComponent($p, $q, $h + 1 / 3)),
            round(255 * $this->hueComponent($p, $q, $h)),
            round(255 * $this->hueComponent($p, $q, $h - 1 / 3))
        );
    }

    private function hueComponent(float $p, float $q, float $t): float
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
