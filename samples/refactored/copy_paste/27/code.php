<?php

declare(strict_types=1);

namespace App\Services\Media;

final class ThumbnailConfig
{
    public readonly int $width;
    public readonly int $height;
    public readonly string $mode;
    public readonly string $format;
    public readonly int $quality;

    public function __construct(
        int $width,
        int $height = 0,
        string $mode = 'fit',
        string $format = 'jpeg',
        int $quality = 85
    ) {
        $this->width = $width;
        $this->height = $height;
        $this->mode = $mode;
        $this->format = $format;
        $this->quality = $quality;
    }
}

final class ThumbnailPreset
{
    public static function profile(): self
    {
        return new self(200, 200, 'crop', 'jpeg', 85);
    }

    public static function listing(): self
    {
        return new self(400, 300, 'fit', 'jpeg', 80);
    }

    public static function gallery(): self
    {
        return new self(800, 600, 'fit', 'jpeg', 85);
    }

    public static function thumbnail(): self
    {
        return new self(150, 150, 'crop', 'jpeg', 70);
    }

    public static function square(int $size = 200): self
    {
        return new self($size, $size, 'crop', 'jpeg', 85);
    }
}

final class ThumbnailService
{
    public function generate(string $sourcePath, ThumbnailConfig $config): array
    {
        $this->validateSource($sourcePath);

        return [
            'width' => $config->width,
            'height' => $config->height,
            'mode' => $config->mode,
            'format' => $config->format,
            'quality' => $config->quality,
            'output' => $this->deriveOutput($sourcePath, $config->format),
        ];
    }

    private function validateSource(string $path): void
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException("File not found: {$path}");
        }
    }

    private function deriveOutput(string $source, string $format): string
    {
        $dir = pathinfo($source, PATHINFO_DIRNAME);
        $name = pathinfo($source, PATHINFO_FILENAME);

        return "{$dir}/{$name}_thumb.{$format}";
    }
}
