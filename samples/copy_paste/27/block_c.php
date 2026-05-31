<?php

declare(strict_types=1);

namespace App\Assets;

use App\Exceptions\ImageTransformException;

final class ImageSizeCalculator
{
    private const ACCEPTED_TYPES = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    private const DEFAULT_QUALITY = 85;
    private const MAX_SIZE = 4096;
    private const MIN_SIZE = 16;

    public function calculateSmall(string $path): array
    {
        $this->checkFile($path);

        return $this->assemble([
            'width' => 150,
            'height' => 150,
            'strategy' => 'crop',
            'format' => 'jpeg',
            'quality' => 75,
        ], $path, 'small');
    }

    public function calculateMedium(string $path): array
    {
        $this->checkFile($path);

        return $this->assemble([
            'width' => 600,
            'height' => 450,
            'strategy' => 'fit',
            'format' => 'jpeg',
            'quality' => self::DEFAULT_QUALITY,
        ], $path, 'medium');
    }

    public function calculateLarge(string $path): array
    {
        $this->checkFile($path);

        return $this->assemble([
            'width' => 1200,
            'height' => 900,
            'strategy' => 'fit',
            'format' => 'jpeg',
            'quality' => self::DEFAULT_QUALITY,
        ], $path, 'large');
    }

    public function calculateXLarge(string $path): array
    {
        $this->checkFile($path);

        return $this->assemble([
            'width' => 1920,
            'height' => 0,
            'strategy' => 'scale',
            'format' => 'jpeg',
            'quality' => 90,
        ], $path, 'xlarge');
    }

    public function calculateSquare(string $path, int $dimension = 200): array
    {
        $this->checkFile($path);
        $dimension = $this->normalizeDimension($dimension);

        return $this->assemble([
            'width' => $dimension,
            'height' => $dimension,
            'strategy' => 'crop',
            'format' => 'jpeg',
            'quality' => self::DEFAULT_QUALITY,
        ], $path, 'sq_' . $dimension);
    }

    public function calculateWide(string $path): array
    {
        $this->checkFile($path);

        return $this->assemble([
            'width' => 1920,
            'height' => 600,
            'strategy' => 'fit',
            'format' => 'jpeg',
            'quality' => self::DEFAULT_QUALITY,
        ], $path, 'wide');
    }

    public function calculateTall(string $path): array
    {
        $this->checkFile($path);

        return $this->assemble([
            'width' => 600,
            'height' => 1200,
            'strategy' => 'fit',
            'format' => 'jpeg',
            'quality' => self::DEFAULT_QUALITY,
        ], $path, 'tall');
    }

    public function calculateCustom(string $path, int $w, int $h, string $mode = 'fit'): array
    {
        $this->checkFile($path);
        $w = $this->normalizeDimension($w);
        $h = $this->normalizeDimension($h);

        return $this->assemble([
            'width' => $w,
            'height' => $h,
            'strategy' => $mode,
            'format' => 'png',
            'quality' => self::DEFAULT_QUALITY,
        ], $path, 'custom');
    }

    private function checkFile(string $path): void
    {
        if (empty($path)) {
            throw new ImageTransformException('Path cannot be empty');
        }

        if (!file_exists($path)) {
            throw new ImageTransformException("File not found: {$path}");
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (!in_array($ext, self::ACCEPTED_TYPES, true)) {
            throw new ImageTransformException("Unsupported: {$ext}");
        }
    }

    private function normalizeDimension(int $dim): int
    {
        if ($dim < self::MIN_SIZE) {
            return self::MIN_SIZE * 4;
        }

        if ($dim > self::MAX_SIZE) {
            return self::MAX_SIZE;
        }

        return $dim;
    }

    private function assemble(array $spec, string $path, string $suffix): array
    {
        $dir = pathinfo($path, PATHINFO_DIRNAME);
        $name = pathinfo($path, PATHINFO_FILENAME);

        $spec['source'] = $path;
        $spec['output'] = "{$dir}/{$name}_{$suffix}." . strtolower($spec['format']);

        return $spec;
    }
}
