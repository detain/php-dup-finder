<?php

declare(strict_types=1);

namespace App\Services\File;

use App\Exceptions\PathException;

final class FilePathResolver
{
    private const SLASH = '/';
    private const BACKSLASH = '\\';
    private const MULTIPLE_SLASHES = '/{2,}/';

    public function resolve(string $path): string
    {
        $path = $this->forwardSlashify($path);
        $path = $this->squashRepeats($path);
        $path = $this->stripTrailing($path);
        $path = $this->resolveParentDirs($path);
        $path = $this->stripCurrentDirs($path);

        return $path;
    }

    public function resolveAgainst(string $path, string $base): string
    {
        if ($this->isAbsolutePath($path)) {
            return $this->resolve($path);
        }

        $joined = rtrim($base, '/') . '/' . ltrim($path, '/');

        return $this->resolve($joined);
    }

    public function makeComparable(string $path): string
    {
        $resolved = $this->resolve($path);

        return mb_strtolower(rtrim($resolved, '/'));
    }

    public function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:/', $path);
    }

    public function isInside(string $path, string $directory): bool
    {
        $resolvedPath = $this->resolve($path);
        $resolvedDir = $this->resolve($directory);

        $realPath = realpath($resolvedPath);
        $realDir = realpath($resolvedDir);

        if ($realPath === false || $realDir === false) {
            return false;
        }

        return str_starts_with($realPath, $realDir);
    }

    public function makeSafeFilename(string $name): string
    {
        $name = $this->removeDirectoryTraversals($name);
        $name = $this->replaceInvalidChars($name);
        $name = $this->squashRepeats($name);
        $name = $this->stripTrailing($name);

        return $name;
    }

    public function concatenate(string $base, string ...$pieces): string
    {
        $accumulated = rtrim($base, '/');

        foreach ($pieces as $piece) {
            $piece = ltrim($piece, '/');
            $accumulated .= '/' . $piece;
        }

        return $this->resolve($accumulated);
    }

    public function extractDirectory(string $path): string
    {
        $resolved = $this->resolve($path);
        $lastSlash = strrpos($resolved, '/');

        return $lastSlash === false ? '.' : substr($resolved, 0, $lastSlash);
    }

    public function extractFilename(string $path): string
    {
        $resolved = $this->resolve($path);
        $lastSlash = strrpos($resolved, '/');

        return $lastSlash === false ? $resolved : substr($resolved, $lastSlash + 1);
    }

    public function extractExtension(string $path): string
    {
        $filename = $this->extractFilename($path);
        $lastDot = strrpos($filename, '.');

        return $lastDot === false ? '' : substr($filename, $lastDot + 1);
    }

    private function forwardSlashify(string $path): string
    {
        return str_replace(self::BACKSLASH, self::SLASH, $path);
    }

    private function squashRepeats(string $path): string
    {
        return preg_replace(self::MULTIPLE_SLASHES, self::SLASH, $path) ?: $path;
    }

    private function stripTrailing(string $path): string
    {
        if (strlen($path) > 1 && str_ends_with($path, '/')) {
            return substr($path, 0, -1);
        }

        return $path;
    }

    private function resolveParentDirs(string $path): string
    {
        $segments = explode('/', $path);
        $stack = [];

        foreach ($segments as $segment) {
            if ($segment === '..') {
                array_pop($stack);
            } else {
                $stack[] = $segment;
            }
        }

        $result = implode('/', $stack);

        if (str_starts_with($path, '/')) {
            $result = '/' . $result;
        }

        return $result;
    }

    private function stripCurrentDirs(string $path): string
    {
        $segments = explode('/', $path);
        $filtered = array_filter($segments, fn($s) => $s !== '.');

        $result = implode('/', $filtered);

        if (str_starts_with($path, '/')) {
            $result = '/' . $result;
        }

        return $result;
    }

    private function removeDirectoryTraversals(string $filename): string
    {
        return preg_replace(['/\.\.\//', '/\.\./', '/\.\./'], '', $filename) ?: $filename;
    }

    private function replaceInvalidChars(string $filename): string
    {
        $invalid = ['/', '\\', "\0", '<', '>', ':', '"', '|', '?', '*'];

        return str_replace($invalid, '_', $filename);
    }
}
