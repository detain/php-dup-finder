<?php

declare(strict_types=1);

namespace App\Data;

final class ArrayMerger
{
    public function combine(array $target, array $source): array
    {
        foreach ($source as $k => $v) {
            if (is_array($v) && isset($target[$k]) && is_array($target[$k])) {
                $target[$k] = $this->combine($target[$k], $v);
            } else {
                $target[$k] = $v;
            }
        }

        return $target;
    }

    public function combineAll(array ...$arrays): array
    {
        $merged = [];

        foreach ($arrays as $arr) {
            $merged = $this->combine($merged, $arr);
        }

        return $merged;
    }

    public function combineStrict(array $target, array $source): array
    {
        foreach ($source as $k => $v) {
            if (is_array($v) && isset($target[$k]) && is_array($target[$k])) {
                if ($this->hasStringKeys($v) && $this->hasStringKeys($target[$k])) {
                    $target[$k] = $this->combineStrict($target[$k], $v);
                } else {
                    $target[$k] = $v;
                }
            } else {
                $target[$k] = $v;
            }
        }

        return $target;
    }

    public function combineNumeric(array $target, array $source): array
    {
        foreach ($source as $k => $v) {
            if (is_array($v) && isset($target[$k]) && is_array($target[$k])) {
                if ($this->hasStringKeys($v) || $this->hasStringKeys($target[$k])) {
                    $target[$k] = $this->combineNumeric($target[$k], $v);
                } else {
                    $target[$k] = array_merge($target[$k], $v);
                }
            } else {
                $target[$k] = $v;
            }
        }

        return $target;
    }

    public function combineUsing(array $target, array $source, string $mode): array
    {
        return match ($mode) {
            'deep' => $this->combine($target, $source),
            'shallow' => array_merge($target, $source),
            'strict' => $this->combineStrict($target, $source),
            'numeric' => $this->combineNumeric($target, $source),
            default => throw new \InvalidArgumentException("Unknown mode: {$mode}"),
        };
    }

    public function overwrite(array $target, array $source): array
    {
        foreach ($source as $k => $v) {
            if (is_array($v) && isset($target[$k]) && is_array($target[$k])) {
                $target[$k] = $this->overwrite($target[$k], $v);
            } else {
                $target[$k] = $v;
            }
        }

        return $target;
    }

    public function join(array $a, array $b): array
    {
        return $this->combine($a, $b);
    }

    public function common(array $a, array $b): array
    {
        $result = [];

        foreach ($a as $k => $v) {
            if (array_key_exists($k, $b)) {
                if (is_array($v) && is_array($b[$k])) {
                    $result[$k] = $this->common($v, $b[$k]);
                } elseif ($v === $b[$k]) {
                    $result[$k] = $v;
                }
            }
        }

        return $result;
    }

    public function differences(array $a, array $b): array
    {
        $result = [];

        foreach ($a as $k => $v) {
            if (!array_key_exists($k, $b)) {
                $result[$k] = $v;
            } elseif (is_array($v) && is_array($b[$k])) {
                $nestedDiff = $this->differences($v, $b[$k]);

                if (!empty($nestedDiff)) {
                    $result[$k] = $nestedDiff;
                }
            } elseif ($v !== $b[$k]) {
                $result[$k] = $v;
            }
        }

        return $result;
    }

    public function select(array $data, callable $criteria): array
    {
        $output = [];

        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $filtered = $this->select($v, $criteria);

                if (!empty($filtered)) {
                    $output[$k] = $filtered;
                }
            } elseif ($criteria($k, $v)) {
                $output[$k] = $v;
            }
        }

        return $output;
    }

    public function transform(array $data, callable $fn): array
    {
        $output = [];

        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $output[$k] = $this->transform($v, $fn);
            } else {
                $output[$k] = $fn($k, $v);
            }
        }

        return $output;
    }

    public function fold(array $data, callable $fn, mixed $init = null): mixed
    {
        $acc = $init;

        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $acc = $this->fold($v, $fn, $acc);
            } else {
                $acc = $fn($acc, $k, $v);
            }
        }

        return $acc;
    }

    public function flattenDepth(array $arr, int $depth = -1): array
    {
        $flat = [];

        foreach ($arr as $v) {
            if (!is_array($v)) {
                $flat[] = $v;
            } elseif ($depth === 0) {
                $flat[] = $v;
            } else {
                $flat = array_merge($flat, $this->flattenDepth($v, $depth - 1));
            }
        }

        return $flat;
    }

    public function allKeys(array $arr): array
    {
        $keys = [];

        foreach ($arr as $k => $v) {
            $keys[] = $k;

            if (is_array($v)) {
                foreach ($this->allKeys($v) as $nested) {
                    $keys[] = $k . '.' . $nested;
                }
            }
        }

        return $keys;
    }

    public function getPath(array $arr, string $path, mixed $fallback = null): mixed
    {
        $parts = explode('.', $path);
        $node = $arr;

        foreach ($parts as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $fallback;
            }

            $node = $node[$part];
        }

        return $node;
    }

    public function setPath(array &$arr, string $path, mixed $value): void
    {
        $parts = explode('.', $path);
        $node = &$arr;

        foreach ($parts as $part) {
            if (!isset($node[$part]) || !is_array($node[$part])) {
                $node[$part] = [];
            }

            $node = &$node[$part];
        }

        $node = $value;
    }

    public function hasPath(array $arr, string $path): bool
    {
        return $this->getPath($arr, $path, null) !== null;
    }

    public function removePath(array &$arr, string $path): bool
    {
        $parts = explode('.', $path);
        $node = &$arr;

        for ($i = 0; $i < count($parts) - 1; $i++) {
            $key = $parts[$i];

            if (!is_array($node) || !isset($node[$key])) {
                return false;
            }

            $node = &$node[$key];
        }

        $last = end($parts);

        if (isset($node[$last])) {
            unset($node[$last]);

            return true;
        }

        return false;
    }

    private function hasStringKeys(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
