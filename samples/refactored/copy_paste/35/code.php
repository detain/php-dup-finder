<?php

namespace App\Services\Styling;

final class ColorConfig
{
    public readonly bool $allowShort;
    public readonly bool $allowAlpha;

    public function __construct(bool $allowShort = true, bool $allowAlpha = false)
    {
        $this->allowShort = $allowShort;
        $this->allowAlpha = $allowAlpha;
    }
}

final class ColorService
{
    private ColorConfig $config;

    public function __construct(ColorConfig $config)
    {
        $this->config = $config;
    }

    public function validate(string $hex): string
    {
        $stripped = ltrim($hex, '#');

        if (!preg_match('/^[0-9a-f]{3}$/i', $stripped) &&
            !preg_match('/^[0-9a-f]{6}$/i', $stripped) &&
            !preg_match('/^[0-9a-f]{8}$/i', $stripped)) {
            throw new \InvalidArgumentException("Invalid hex color: {$hex}");
        }

        if (strlen($stripped) === 3 && !$this->config->allowShort) {
            throw new \InvalidArgumentException('Short form not allowed');
        }

        if (strlen($stripped) === 8 && !$this->config->allowAlpha) {
            throw new \InvalidArgumentException('Alpha channel not allowed');
        }

        return $this->normalize($stripped);
    }

    public function toRgb(string $hex): array
    {
        $stripped = ltrim($this->validate($hex), '#');
        $len = strlen($stripped);

        if ($len === 3) {
            $stripped = str_repeat($stripped[0], 2) . str_repeat($stripped[1], 2) . str_repeat($stripped[2], 2);
        }

        return [
            'r' => hexdec(substr($stripped, 0, 2)),
            'g' => hexdec(substr($stripped, 2, 2)),
            'b' => hexdec(substr($stripped, 4, 2)),
            'a' => $len === 8 ? hexdec(substr($stripped, 6, 2)) / 255 : 1.0,
        ];
    }

    public function isDark(string $hex): bool
    {
        $rgb = $this->toRgb($hex);
        $luma = 0.2126 * ($rgb['r'] / 255) + 0.7152 * ($rgb['g'] / 255) + 0.0722 * ($rgb['b'] / 255);

        return $luma < 0.5;
    }

    private function normalize(string $hex): string
    {
        if (strlen($hex) === 3) {
            $expanded = '';

            for ($i = 0; $i < 3; $i++) {
                $expanded .= str_repeat($hex[$i], 2);
            }

            return '#' . strtolower($expanded);
        }

        return '#' . strtolower($hex);
    }
}
