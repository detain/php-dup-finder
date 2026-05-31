<?php

declare(strict_types=1);

namespace App\IO;

use App\Exceptions\PathResolutionException;

final class PathProcessor
{
    private const FORWARD = '/';
    private const BACKWARD = '\\';
    private const SEQUENCE_PATTERN = '/\/+/';

    public function canonicalize(string $path): string
    {
        $path = $this->toForwardSlashes($path);
        $path = $this->removeDuplicateSlashes($path);
        $path = $this->stripTrailingSlash($path);
        $path = $this->collapseParentRefs($path);
        $path = $this->removeCurrentRefs($path);

        return $path;
    }

    public function canonicalizeWithRoot(string $path, string $root): string
    {
        if ($this->isAbsolutePath($path)) {
            return $this->canonicalize($path);
        }

        return $this->canonicalize($root . '/' . ltrim($path, '/'));
    }

    public function normalizedEquality(string $pathA, string $pathB): bool
    {
        $normA = $this->canonicalize(mb_strtolower($pathA));
        $normB = $this->canonicalize(mb_strtolower($pathB));

        return $normA === $normB;
    }

    public function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:/', $path);
    }

    public function isSafe(string $path, string $rootDir): bool
    {
        $canonical = $this->canonicalize($path);
        $realCanonical = realpath($canonical);

        if ($realCanonical === false) {
            return false;
        }

        $realRoot = realpath($this->canonicalize($rootDir));

        if ($realRoot === false) {
            return false;
        }

        return str_starts_with($realCanonical, $realRoot);
    }

    public function sanitizeName(string $name): string
    {
        $name = $this->stripPathElements($name);
        $name = $this->replaceBadChars($name);
        $name = $this->removeDuplicateSlashes($name);
        $name = $this->stripTrailingSlash($name);

        return $name;
    }

    public function combine(string $base, string ...$additions): string
    {
        $path = rtrim($base, '/');

        foreach ($additions as $add) {
            $path .= '/' . ltrim($add, '/');
        }

        return $this->canonicalize($path);
    }

    public function directoryOf(string $path): string
    {
        $canonical = $this->canonicalize($path);
        $lastSlash = strrpos($canonical, '/');

        return $lastSlash === false ? '.' : substr($canonical, 0, $lastSlash);
    }

    public function filenameOf(string $path): string
    {
        $canonical = $this->canonicalize($path);
        $lastSlash = strrpos($canonical, '/');

        return $lastSlash === false ? $canonical : substr($canonical, $lastSlash + 1);
    }

    public function extensionOf(string $path): string
    {
        $filename = $this->filenameOf($path);
        $lastDot = strrpos($filename, '.');

        return $lastDot === false ? '' : substr($filename, $lastDot + 1);
    }

    private function toForwardSlashes(string $path): string
    {
        return str_replace(self::BACKWARD, self::FORWARD, $path);
    }

    private function removeDuplicateSlashes(string $path): string
    {
        return preg_replace(self::SEQUENCE_PATTERN, self::FORWARD, $path) ?: $path;
    }

    private function stripTrailingSlash(string $path): string
    {
        if (strlen($path) > 1 && str_ends_with($path, '/')) {
            return substr($path, 0, -1);
        }

        return $path;
    }

    private function collapseParentRefs(string $path): string
    {
        $parts = explode('/', $path);
        $stack = [];

        foreach ($parts as $part) {
            if ($part === '..') {
                array_pop($stack);
            } else {
                $stack[] = $part;
            }
        }

        $result = implode('/', $stack);

        if (str_starts_with($path, '/')) {
            $result = '/' . $result;
        }

        return $result;
    }

    private function removeCurrentRefs(string $path): string
    {
        $parts = explode('/', $path);
        $filtered = array_filter($parts, fn($p) => $p !== '.');

        $result = implode('/', $filtered);

        if (str_starts_with($path, '/')) {
            $result = '/' . $result;
        }

        return $result;
    }

    private function stripPathElements(string $filename): string
    {
        $filename = preg_replace(['/\.\.\//', '/\.\.$/', '/\.\.\//'], '', $filename);

        return preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename ?? '') ?: '';
    }

    private function replaceBadChars(string $filename): string
    {
        $bad = ['/', '\\', "\0", '<', '>', ':', '"', '|', '?', '*'];

        return str_replace($bad, '_', $filename);
    }
}
