<?php

declare(strict_types=1);

namespace App\Images;

use App\Exceptions\ImageProcessingException;

final class ImageResizerConfiguration
{
    private const SUPPORTED_TYPES = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'];
    private const STANDARD_QUALITY = 85;
    private const UPPER_LIMIT = 4096;
    private const LOWER_LIMIT = 16;

    public function forAvatar(string $imagePath): array
    {
        $this->assertImageExists($imagePath);

        return [
            'target_width' => 128,
            'target_height' => 128,
            'resize_mode' => 'crop_center',
            'output_format' => 'jpeg',
            'compression' => self::STANDARD_QUALITY,
            'destination' => $this->computeDestination($imagePath, 'avatar'),
        ];
    }

    public function forCard(string $imagePath): array
    {
        $this->assertImageExists($imagePath);

        return [
            'target_width' => 350,
            'target_height' => 250,
            'resize_mode' => 'fit_bounds',
            'output_format' => 'jpeg',
            'compression' => 80,
            'destination' => $this->computeDestination($imagePath, 'card'),
        ];
    }

    public function forHero(string $imagePath): array
    {
        $this->assertImageExists($imagePath);

        return [
            'target_width' => 1920,
            'target_height' => 1080,
            'resize_mode' => 'scale_down',
            'output_format' => 'jpeg',
            'compression' => self::STANDARD_QUALITY,
            'destination' => $this->computeDestination($imagePath, 'hero'),
        ];
    }

    public function forIcon(string $imagePath): array
    {
        $this->assertImageExists($imagePath);

        return [
            'target_width' => 64,
            'target_height' => 64,
            'resize_mode' => 'crop_center',
            'output_format' => 'png',
            'compression' => 100,
            'destination' => $this->computeDestination($imagePath, 'icon'),
        ];
    }

    public function forMedium(string $imagePath): array
    {
        $this->assertImageExists($imagePath);

        return [
            'target_width' => 640,
            'target_height' => 480,
            'resize_mode' => 'fit',
            'output_format' => 'jpeg',
            'compression' => self::STANDARD_QUALITY,
            'destination' => $this->computeDestination($imagePath, 'medium'),
        ];
    }

    public function forPreview(string $imagePath): array
    {
        $this->assertImageExists($imagePath);

        return [
            'target_width' => 320,
            'target_height' => 240,
            'resize_mode' => 'fit',
            'output_format' => 'jpeg',
            'compression' => 75,
            'destination' => $this->computeDestination($imagePath, 'preview'),
        ];
    }

    public function forResponsive(string $imagePath, array $breakpoints): array
    {
        $this->assertImageExists($imagePath);

        $configs = [];

        foreach ($breakpoints as $name => $width) {
            $width = $this->clampDimension($width);
            $configs[$name] = [
                'target_width' => $width,
                'target_height' => 0,
                'resize_mode' => 'width_only',
                'output_format' => 'webp',
                'compression' => 80,
                'destination' => $this->computeDestination($imagePath, 'resp_' . $name),
            ];
        }

        return $configs;
    }

    public function forCustom(string $imagePath, int $width, int $height, string $mode): array
    {
        $this->assertImageExists($imagePath);
        $width = $this->clampDimension($width);
        $height = $this->clampDimension($height);

        return [
            'target_width' => $width,
            'target_height' => $height,
            'resize_mode' => $mode,
            'output_format' => 'png',
            'compression' => self::STANDARD_QUALITY,
            'destination' => $this->computeDestination($imagePath, 'custom'),
        ];
    }

    private function assertImageExists(string $path): void
    {
        if (empty($path)) {
            throw new ImageProcessingException('Image path cannot be blank');
        }

        if (!is_file($path)) {
            throw new ImageProcessingException("Image file not found: {$path}");
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (!in_array($ext, self::SUPPORTED_TYPES, true)) {
            throw new ImageProcessingException("Image type {$ext} is not supported");
        }
    }

    private function clampDimension(int $value): int
    {
        if ($value < self::LOWER_LIMIT) {
            return self::LOWER_LIMIT * 4;
        }

        if ($value > self::UPPER_LIMIT) {
            return self::UPPER_LIMIT;
        }

        return $value;
    }

    private function computeDestination(string $source, string $variant): string
    {
        $dir = pathinfo($source, PATHINFO_DIRNAME);
        $name = pathinfo($source, PATHINFO_FILENAME);

        return $dir . '/' . $name . '_' . $variant . '.jpg';
    }
}
