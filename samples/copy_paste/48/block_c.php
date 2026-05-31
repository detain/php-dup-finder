<?php

declare(strict_types=1);

namespace App\Utilities;

final class NestedArrayProcessor
{
    public function merge(array $left, array $right): array
    {
        foreach ($right as $key => $val) {
            if (is_array($val) && isset($left[$key]) && is_array($left[$key])) {
                $left[$key] = $this->merge($left[$key], $val);
            } else {
                $left[$key] = $val;
            }
        }

        return $left;
    }

    public function mergeAll(array ...$args): array
    {
        $output = [];

        foreach ($args as $arg) {
            $output = $this->merge($output, $arg);
        }

        return $output;
    }

    public function mergeRecursive(array $left, array $right): array
    {
        foreach ($right as $key => $val) {
            if (is_array($val) && isset($left[$key]) && is_array($left[$key])) {
                if ($this->isMap($val) && $this->isMap($left[$key])) {
                    $left[$key] = $this->mergeRecursive($left[$key], $val);
                } else {
                    $left[$key] = $val;
                }
            } else {
                $left[$key] = $val;
            }
        }

        return $left;
    }

    public function mergeAppendNumeric(array $left, array $right): array
    {
        foreach ($right as $key => $val) {
            if (is_array($val) && isset($left[$key]) && is_array($left[$key])) {
                if ($this->isMap($val) || $this->isMap($left[$key])) {
                    $left[$key] = $this->mergeAppendNumeric($left[$key], $val);
                } else {
                    $left[$key] = array_merge($left[$key], $val);
                }
            } else {
                $left[$key] = $val;
            }
        }

        return $left;
    }

    public function applyStrategy(array $left, array $right, string $method): array
    {
        return match ($method) {
            'deep' => $this->merge($left, $right),
            'flat' => array_merge($left, $right),
            'recursive' => $this->mergeRecursive($left, $right),
            'append' => $this->mergeAppendNumeric($left, $right),
            default => throw new \InvalidArgumentException("Unknown method: {$method}"),
        };
    }

    public function replace(array $target, array $source): array
    {
        foreach ($source as $key => $val) {
            if (is_array($val) && isset($target[$key]) && is_array($target[$key])) {
                $target[$key] = $this->replace($target[$key], $val);
            } else {
                $target[$key] = $val;
            }
        }

        return $target;
    }

    public function concat(array $a, array $b): array
    {
        return $this->merge($a, $b);
    }

    public function keepCommon(array $a, array $b): array
    {
        $common = [];

        foreach ($a as $key => $val) {
            if (array_key_exists($key, $b)) {
                if (is_array($val) && is_array($b[$key])) {
                    $common[$key] = $this->keepCommon($val, $b[$key]);
                } elseif ($val === $b[$key]) {
                    $common[$key] = $val;
                }
            }
        }

        return $common;
    }

    public function findDiff(array $a, array $b): array
    {
        $diff = [];

        foreach ($a as $key => $val) {
            if (!array_key_exists($key, $b)) {
                $diff[$key] = $val;
            } elseif (is_array($val) && is_array($b[$key])) {
                $subDiff = $this->findDiff($val, $b[$key]);

                if (!empty($subDiff)) {
                    $diff[$key] = $subDiff;
                }
            } elseif ($val !== $b[$key]) {
                $diff[$key] = $val;
            }
        }

        return $diff;
    }

    public function where(array $data, callable $cond): array
    {
        $filtered = [];

        foreach ($data as $key => $val) {
            if (is_array($val)) {
                $sub = $this->where($val, $cond);

                if (!empty($sub)) {
                    $filtered[$key] = $sub;
                }
            } elseif ($cond($key, $val)) {
                $filtered[$key] = $val;
            }
        }

        return $filtered;
    }

    public function each(array $data, callable $fn): array
    {
        $mapped = [];

        foreach ($data as $key => $val) {
            if (is_array($val)) {
                $mapped[$key] = $this->each($val, $fn);
            } else {
                $mapped[$key] = $fn($key, $val);
            }
        }

        return $mapped;
    }

    public function aggregate(array $data, callable $fn, mixed $start = null): mixed
    {
        $acc = $start;

        foreach ($data as $key => $val) {
            if (is_array($val)) {
                $acc = $this->aggregate($val, $fn, $acc);
            } else {
                $acc = $fn($acc, $key, $val);
            }
        }

        return $acc;
    }

    public function spread(array $data, int $maxDepth = -1): array
    {
        $flattened = [];

        foreach ($data as $val) {
            if (!is_array($val)) {
                $flattened[] = $val;
            } elseif ($maxDepth === 0) {
                $flattened[] = $val;
            } else {
                $flattened = array_merge($flattened, $this->spread($val, $maxDepth - 1));
            }
        }

        return $flattened;
    }

    public function fetchKeys(array $data): array
    {
        $keys = [];

        foreach ($data as $key => $val) {
            $keys[] = $key;

            if (is_array($val)) {
                foreach ($this->fetchKeys($val) as $nested) {
                    $keys[] = $key . '.' . $nested;
                }
            }
        }

        return $keys;
    }

    public function resolve(array $data, string $dotPath, mixed $default = null): mixed
    {
        $path = explode('.', $dotPath);
        $cursor = $data;

        foreach ($path as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    public function assign(array &$data, string $dotPath, mixed $value): void
    {
        $path = explode('.', $dotPath);
        $cursor = &$data;

        foreach ($path as $segment) {
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }

        $cursor = $value;
    }

    public function exists(array $data, string $dotPath): bool
    {
        return $this->resolve($data, $dotPath, null) !== null;
    }

    public function forget(array &$data, string $dotPath): bool
    {
        $path = explode('.', $dotPath);
        $cursor = &$data;

        for ($i = 0; $i < count($path) - 1; $i++) {
            $segment = $path[$i];

            if (!is_array($cursor) || !isset($cursor[$segment])) {
                return false;
            }

            $cursor = &$cursor[$segment];
        }

        $last = end($path);

        if (isset($cursor[$last])) {
            unset($cursor[$last]);

            return true;
        }

        return false;
    }

    private function isMap(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
