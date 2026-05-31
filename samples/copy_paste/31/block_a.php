<?php

declare(strict_types=1);

namespace App\Filesystem\Processing;

use App\Exceptions\PathNormalizationException;

final class PathNormalizer
{
    private const DIRECTORY_SEPARATOR = '/';
    private const WINDOWS_SEPARATOR = '\\';
    private const SEQUENTIAL_SEPARATORS = '/+';

    public function normalize(string $path): string
    {
        $path = $this->convertWindowsSeparators($path);
        $path = $this->collapseRepeatedSeparators($path);
        $path = $this->removeTrailingSeparator($path);
        $path = $this->resolveParentReferences($path);
        $path = $this->removeDotReferences($path);

        return $path;
    }

    public function normalizeWithBase(string $path, string $basePath): string
    {
        if ($this->isAbsolute($path)) {
            return $this->normalize($path);
        }

        $combined = rtrim($basePath, '/') . '/' . ltrim($path, '/');

        return $this->normalize($combined);
    }

    public function normalizeForComparison(string $path): string
    {
        $normalized = $this->normalize($path);
        $normalized = strtolower($normalized);

        return rtrim($normalized, '/');
    }

    public function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:/', $path);
    }

    public function isSecure(string $path, string $allowedDirectory): bool
    {
        $normalized = $this->normalize($path);
        $normalized = $this->resolveRealPath($normalized);

        if ($normalized === false) {
            return false;
        }

        $allowed = $this->normalize($allowedDirectory);

        return str_starts_with($normalized, $allowed);
    }

    public function sanitizeFilename(string $filename): string
    {
        $filename = $this->removePathComponents($filename);
        $filename = $this->replaceForbiddenCharacters($filename);
        $filename = $this->collapseRepeatedSeparators($filename);
        $filename = $this->trimSeparators($filename);

        return $filename;
    }

    public function join(string $basePath, string ...$segments): string
    {
        $result = rtrim($basePath, '/');

        foreach ($segments as $segment) {
            $segment = ltrim($segment, '/');
            $result .= '/' . $segment;
        }

        return $this->normalize($result);
    }

    public function getDirectory(string $path): string
    {
        $normalized = $this->normalize($path);
        $lastSlash = strrpos($normalized, '/');

        if ($lastSlash === false) {
            return '.';
        }

        return substr($normalized, 0, $lastSlash);
    }

    public function getFilename(string $path): string
    {
        $normalized = $this->normalize($path);
        $lastSlash = strrpos($normalized, '/');

        if ($lastSlash === false) {
            return $normalized;
        }

        return substr($normalized, $lastSlash + 1);
    }

    public function getExtension(string $path): string
    {
        $filename = $this->getFilename($path);
        $lastDot = strrpos($filename, '.');

        if ($lastDot === false) {
            return '';
        }

        return substr($filename, $lastDot + 1);
    }

    private function convertWindowsSeparators(string $path): string
    {
        return str_replace(self::WINDOWS_SEPARATOR, self::DIRECTORY_SEPARATOR, $path);
    }

    private function collapseRepeatedSeparators(string $path): string
    {
        return preg_replace(self::SEQUENTIAL_SEPARATORS, self::DIRECTORY_SEPARATOR, $path) ?: $path;
    }

    private function removeTrailingSeparator(string $path): string
    {
        if ($path !== '/' && str_ends_with($path, '/')) {
            return substr($path, 0, -1);
        }

        return $path;
    }

    private function trimSeparators(string $path): string
    {
        return trim($path, '/');
    }

    private function resolveParentReferences(string $path): string
    {
        $parts = explode('/', $path);
        $resolved = [];

        foreach ($parts as $part) {
            if ($part === '..') {
                array_pop($resolved);
            } elseif ($part !== '.' && $part !== '') {
                $resolved[] = $part;
            }
        }

        $result = implode('/', $resolved);

        if (str_starts_with($path, '/')) {
            $result = '/' . $result;
        }

        return $result;
    }

    private function removeDotReferences(string $path): string
    {
        $parts = explode('/', $path);
        $filtered = array_filter($parts, fn($p) => $p !== '.');

        $result = implode('/', $filtered);

        if (str_starts_with($path, '/')) {
            $result = '/' . $result;
        }

        return $result;
    }

    private function removePathComponents(string $filename): string
    {
        $normalized = str_replace(['..', './', '../', '/'], '', $filename);

        return preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $normalized);
    }

    private function replaceForbiddenCharacters(string $filename): string
    {
        $forbidden = ['/', '\\', "\0", '<', '>', ':', '"', '|', '?', '*'];

        return str_replace($forbidden, '_', $filename);
    }

    private function resolveRealPath(string $path): string|false
    {
        return realpath($path);
    }
}
