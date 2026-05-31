<?php

declare(strict_types=1);

namespace App\Config;

final class DeepMerger
{
    public function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    public function mergeMultiple(array ...$arrays): array
    {
        $result = [];

        foreach ($arrays as $array) {
            $result = $this->merge($result, $array);
        }

        return $result;
    }

    public function mergeDistinct(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                if ($this->isAssociative($value) && $this->isAssociative($base[$key])) {
                    $base[$key] = $this->mergeDistinct($base[$key], $value);
                } else {
                    $base[$key] = $value;
                }
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    public function mergePreserveNumeric(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                if ($this->isAssociative($value) || $this->isAssociative($base[$key])) {
                    $base[$key] = $this->mergePreserveNumeric($base[$key], $value);
                } else {
                    $base[$key] = array_merge($base[$key], $value);
                }
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    public function mergeWithStrategy(array $base, array $override, string $strategy): array
    {
        return match ($strategy) {
            'deep' => $this->merge($base, $override),
            'shallow' => array_merge($base, $override),
            'distinct' => $this->mergeDistinct($base, $override),
            'preserve_numeric' => $this->mergePreserveNumeric($base, $override),
            default => throw new \InvalidArgumentException("Unknown merge strategy: {$strategy}"),
        };
    }

    public function override(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->override($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    public function union(array $first, array $second): array
    {
        return $this->merge($first, $second);
    }

    public function intersect(array $first, array $second): array
    {
        $result = [];

        foreach ($first as $key => $value) {
            if (array_key_exists($key, $second)) {
                if (is_array($value) && is_array($second[$key])) {
                    $result[$key] = $this->intersect($value, $second[$key]);
                } elseif ($value === $second[$key]) {
                    $result[$key] = $value;
                }
            }
        }

        return $result;
    }

    public function diff(array $first, array $second): array
    {
        $result = [];

        foreach ($first as $key => $value) {
            if (!array_key_exists($key, $second)) {
                $result[$key] = $value;
            } elseif (is_array($value) && is_array($second[$key])) {
                $nestedDiff = $this->diff($value, $second[$key]);

                if (!empty($nestedDiff)) {
                    $result[$key] = $nestedDiff;
                }
            } elseif ($value !== $second[$key]) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    public function filter(array $array, callable $predicate): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $filtered = $this->filter($value, $predicate);

                if (!empty($filtered)) {
                    $result[$key] = $filtered;
                }
            } elseif ($predicate($key, $value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    public function map(array $array, callable $transformer): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->map($value, $transformer);
            } else {
                $result[$key] = $transformer($key, $value);
            }
        }

        return $result;
    }

    public function reduce(array $array, callable $reducer, mixed $initial = null): mixed
    {
        $accumulator = $initial;

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $accumulator = $this->reduce($value, $reducer, $accumulator);
            } else {
                $accumulator = $reducer($accumulator, $key, $value);
            }
        }

        return $accumulator;
    }

    public function flatten(array $array, int $depth = -1): array
    {
        $result = [];

        foreach ($array as $value) {
            if (!is_array($value)) {
                $result[] = $value;
            } elseif ($depth === 0) {
                $result[] = $value;
            } else {
                $result = array_merge($result, $this->flatten($value, $depth - 1));
            }
        }

        return $result;
    }

    public function deepKeys(array $array): array
    {
        $keys = [];

        foreach ($array as $key => $value) {
            $keys[] = $key;

            if (is_array($value)) {
                foreach ($this->deepKeys($value) as $nestedKey) {
                    $keys[] = $key . '.' . $nestedKey;
                }
            }
        }

        return $keys;
    }

    public function deepGet(array $array, string $path, mixed $default = null): mixed
    {
        $keys = explode('.', $path);
        $current = $array;

        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return $default;
            }

            $current = $current[$key];
        }

        return $current;
    }

    public function deepSet(array &$array, string $path, mixed $value): void
    {
        $keys = explode('.', $path);
        $current = &$array;

        foreach ($keys as $key) {
            if (!isset($current[$key]) || !is_array($current[$key])) {
                $current[$key] = [];
            }

            $current = &$current[$key];
        }

        $current = $value;
    }

    public function deepHas(array $array, string $path): bool
    {
        return $this->deepGet($array, $path, null) !== null;
    }

    public function deepDelete(array &$array, string $path): bool
    {
        $keys = explode('.', $path);
        $current = &$array;

        for ($i = 0; $i < count($keys) - 1; $i++) {
            $key = $keys[$i];

            if (!is_array($current) || !isset($current[$key])) {
                return false;
            }

            $current = &$current[$key];
        }

        $lastKey = end($keys);

        if (isset($current[$lastKey])) {
            unset($current[$lastKey]);

            return true;
        }

        return false;
    }

    private function isAssociative(array $array): bool
    {
        if (empty($array)) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }
}
