<?php

declare(strict_types=1);

namespace App\Helpers;

class ArrayHelper
{
    public static function first(array $array): mixed
    {
        if (empty($array)) {
            return null;
        }

        return reset($array);
    }

    public static function last(array $array): mixed
    {
        if (empty($array)) {
            return null;
        }

        return end($array);
    }

    public static function firstKey(array $array): mixed
    {
        if (empty($array)) {
            return null;
        }

        reset($array);
        return key($array);
    }

    public static function lastKey(array $array): mixed
    {
        if (empty($array)) {
            return null;
        }

        end($array);
        return key($array);
    }

    public static function pluck(array $array, string $key): array
    {
        $result = [];

        foreach ($array as $item) {
            if (is_array($item) && isset($item[$key])) {
                $result[] = $item[$key];
            } elseif (is_object($item) && isset($item->$key)) {
                $result[] = $item->$key;
            }
        }

        return $result;
    }

    public static function indexBy(array $array, string $key): array
    {
        $result = [];

        foreach ($array as $item) {
            if (is_array($item) && isset($item[$key])) {
                $result[$item[$key]] = $item;
            } elseif (is_object($item) && isset($item->$key)) {
                $result[$item->$key] = $item;
            }
        }

        return $result;
    }

    public static function groupBy(array $array, string $key): array
    {
        $result = [];

        foreach ($array as $item) {
            $groupKey = null;

            if (is_array($item) && isset($item[$key])) {
                $groupKey = $item[$key];
            } elseif (is_object($item) && isset($item->$key)) {
                $groupKey = $item->$key;
            }

            if ($groupKey !== null) {
                $result[$groupKey][] = $item;
            }
        }

        return $result;
    }

    public static function map(array $array, callable $callback): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $result[$key] = $callback($value, $key);
        }

        return $result;
    }

    public static function filter(array $array, callable $callback): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    public static function reduce(array $array, callable $callback, mixed $initial = null): mixed
    {
        $accumulator = $initial;

        foreach ($array as $key => $value) {
            $accumulator = $callback($accumulator, $value, $key);
        }

        return $accumulator;
    }

    public static function find(array $array, callable $callback): mixed
    {
        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return null;
    }

    public static function findKey(array $array, callable $callback): mixed
    {
        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $key;
            }
        }

        return null;
    }

    public static function contains(array $array, mixed $value): bool
    {
        return in_array($value, $array, true);
    }

    public static function unique(array $array): array
    {
        return array_values(array_unique($array, SORT_REGULAR));
    }

    public static function flatten(array $array, int $depth = -1): array
    {
        $result = [];

        foreach ($array as $value) {
            if (is_array($value)) {
                if ($depth === -1) {
                    $result = array_merge($result, self::flatten($value));
                } elseif ($depth > 0) {
                    $result = array_merge($result, self::flatten($value, $depth - 1));
                } else {
                    $result[] = $value;
                }
            } else {
                $result[] = $value;
            }
        }

        return $result;
    }

    public static function only(array $array, array $keys): array
    {
        return array_intersect_key($array, array_flip($keys));
    }

    public static function except(array $array, array $keys): array
    {
        return array_diff_key($array, array_flip($keys));
    }

    public static function get(array $array, string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $array)) {
            return $array[$key];
        }

        return $default;
    }

    public static function set(array &$array, string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $current = &$array;

        foreach ($keys as $i => $k) {
            if ($i === count($keys) - 1) {
                $current[$k] = $value;
            } else {
                if (!isset($current[$k]) || !is_array($current[$k])) {
                    $current[$k] = [];
                }
                $current = &$current[$k];
            }
        }
    }
}
