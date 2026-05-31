<?php

declare(strict_types=1);

namespace App\Media\Processing;

use App\Exceptions\ThumbnailException;

final class ThumbnailConfigurationBuilder
{
    private const ALLOWED_FORMATS = ['jpeg', 'jpg', 'png', 'webp', 'gif'];
    private const DEFAULT_QUALITY = 85;
    private const MAX_DIMENSION = 4096;
    private const MIN_DIMENSION = 10;

    public function buildForProfile(string $sourcePath): array
    {
        $this->validateSourcePath($sourcePath);

        return [
            'width' => 200,
            'height' => 200,
            'mode' => 'crop',
            'format' => 'jpeg',
            'quality' => self::DEFAULT_QUALITY,
            'output_path' => $this->deriveOutputPath($sourcePath, 'profile'),
        ];
    }

    public function buildForListing(string $sourcePath): array
    {
        $this->validateSourcePath($sourcePath);

        return [
            'width' => 400,
            'height' => 300,
            'mode' => 'fit',
            'format' => 'jpeg',
            'quality' => 80,
            'output_path' => $this->deriveOutputPath($sourcePath, 'listing'),
        ];
    }

    public function buildForGallery(string $sourcePath): array
    {
        $this->validateSourcePath($sourcePath);

        return [
            'width' => 800,
            'height' => 600,
            'mode' => 'fit',
            'format' => 'jpeg',
            'quality' => self::DEFAULT_QUALITY,
            'output_path' => $this->deriveOutputPath($sourcePath, 'gallery'),
        ];
    }

    public function buildForSocial(string $sourcePath): array
    {
        $this->validateSourcePath($sourcePath);

        return [
            'width' => 1200,
            'height' => 630,
            'mode' => 'fit',
            'format' => 'jpeg',
            'quality' => 90,
            'output_path' => $this->deriveOutputPath($sourcePath, 'social'),
        ];
    }

    public function buildForMobile(string $sourcePath): array
    {
        $this->validateSourcePath($sourcePath);

        return [
            'width' => 600,
            'height' => 0,
            'mode' => 'width',
            'format' => 'webp',
            'quality' => 75,
            'output_path' => $this->deriveOutputPath($sourcePath, 'mobile'),
        ];
    }

    public function buildForThumbnail(string $sourcePath): array
    {
        $this->validateSourcePath($sourcePath);

        return [
            'width' => 150,
            'height' => 150,
            'mode' => 'crop',
            'format' => 'jpeg',
            'quality' => 70,
            'output_path' => $this->deriveOutputPath($sourcePath, 'thumb'),
        ];
    }

    public function buildForBanner(string $sourcePath, int $targetWidth): array
    {
        $this->validateSourcePath($sourcePath);
        $targetWidth = $this->constrainWidth($targetWidth);

        return [
            'width' => $targetWidth,
            'height' => 0,
            'mode' => 'scale',
            'format' => 'jpeg',
            'quality' => self::DEFAULT_QUALITY,
            'output_path' => $this->deriveOutputPath($sourcePath, 'banner'),
        ];
    }

    public function buildCustom(string $sourcePath, int $width, int $height, string $mode = 'fit'): array
    {
        $this->validateSourcePath($sourcePath);
        $this->validateDimension($width, 'width');
        $this->validateDimension($height, 'height');

        return [
            'width' => $width,
            'height' => $height,
            'mode' => $mode,
            'format' => 'png',
            'quality' => self::DEFAULT_QUALITY,
            'output_path' => $this->deriveOutputPath($sourcePath, 'custom'),
        ];
    }

    public function buildSquare(string $sourcePath, int $size): array
    {
        $this->validateSourcePath($sourcePath);
        $size = $this->constrainDimension($size);

        return [
            'width' => $size,
            'height' => $size,
            'mode' => 'crop',
            'format' => 'jpeg',
            'quality' => self::DEFAULT_QUALITY,
            'output_path' => $this->deriveOutputPath($sourcePath, 'square'),
        ];
    }

    private function validateSourcePath(string $path): void
    {
        if (empty($path)) {
            throw new ThumbnailException('Source path cannot be empty');
        }

        if (!file_exists($path)) {
            throw new ThumbnailException("Source file does not exist: {$path}");
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (!in_array($extension, self::ALLOWED_FORMATS, true)) {
            throw new ThumbnailException("Unsupported image format: {$extension}");
        }
    }

    private function validateDimension(int $dimension, string $name): void
    {
        if ($dimension < 0 || $dimension > self::MAX_DIMENSION) {
            throw new ThumbnailException(
                "{$name} dimension must be between " . self::MIN_DIMENSION . " and " . self::MAX_DIMENSION
            );
        }
    }

    private function constrainWidth(int $width): int
    {
        if ($width < self::MIN_DIMENSION) {
            return 100;
        }

        if ($width > self::MAX_DIMENSION) {
            return self::MAX_DIMENSION;
        }

        return $width;
    }

    private function constrainDimension(int $dimension): int
    {
        if ($dimension < self::MIN_DIMENSION) {
            return self::MIN_DIMENSION * 10;
        }

        if ($dimension > self::MAX_DIMENSION) {
            return self::MAX_DIMENSION;
        }

        return $dimension;
    }

    private function deriveOutputPath(string $sourcePath, string $suffix): string
    {
        $directory = pathinfo($sourcePath, PATHINFO_DIRNAME);
        $basename = pathinfo($sourcePath, PATHINFO_FILENAME);

        return $directory . '/' . $basename . '_' . $suffix . '.jpg';
    }
}
