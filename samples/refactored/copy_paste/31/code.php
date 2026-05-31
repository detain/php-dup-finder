<?php

namespace App\Services\IO;

final class PathConfig
{
    public readonly string $separator;

    public function __construct(string $separator = '/')
    {
        $this->separator = $separator;
    }
}

final class PathService
{
    private PathConfig $config;

    public function __construct(PathConfig $config)
    {
        $this->config = $config;
    }

    public function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('/\/+/', '/', $path);
        $path = rtrim($path, '/');

        return $this->resolveParents($path);
    }

    public function join(string $base, string ...$segments): string
    {
        $path = rtrim($base, '/');

        foreach ($segments as $segment) {
            $path .= '/' . ltrim($segment, '/');
        }

        return $this->normalize($path);
    }

    public function directory(string $path): string
    {
        $normalized = $this->normalize($path);
        $last = strrpos($normalized, '/');

        return $last === false ? '.' : substr($normalized, 0, $last);
    }

    public function filename(string $path): string
    {
        $normalized = $this->normalize($path);
        $last = strrpos($normalized, '/');

        return $last === false ? $normalized : substr($normalized, $last + 1);
    }

    public function extension(string $path): string
    {
        $filename = $this->filename($path);
        $dot = strrpos($filename, '.');

        return $dot === false ? '' : substr($filename, $dot + 1);
    }

    private function resolveParents(string $path): string
    {
        $parts = explode('/', $path);
        $result = [];

        foreach ($parts as $part) {
            if ($part === '..') {
                array_pop($result);
            } elseif ($part !== '.') {
                $result[] = $part;
            }
        }

        $resolved = implode('/', $result);

        if (str_starts_with($path, '/')) {
            $resolved = '/' . $resolved;
        }

        return $resolved;
    }
}
