<?php

namespace App\Services\DataAccess;

final class FilterConfig
{
    public readonly bool $caseSensitive;
    public readonly bool $strictComparison;

    public function __construct(bool $caseSensitive = false, bool $strictComparison = true)
    {
        $this->caseSensitive = $caseSensitive;
        $this->strictComparison = $strictComparison;
    }
}

final class CollectionFilterService
{
    private FilterConfig $config;

    public function __construct(FilterConfig $config)
    {
        $this->config = $config;
    }

    public function filter(array $items, callable $predicate): array
    {
        $result = [];

        foreach ($items as $item) {
            if ($predicate($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    public function where(array $items, string $property, mixed $value): array
    {
        return $this->filter($items, function ($item) use ($property, $value) {
            $actual = $this->getProperty($item, $property);

            return $this->config->strictComparison ? $actual === $value : $actual == $value;
        });
    }

    public function whereIn(array $items, string $property, array $values): array
    {
        return $this->filter($items, function ($item) use ($property, $values) {
            return in_array($this->getProperty($item, $property), $values, $this->config->strictComparison);
        });
    }

    public function whereBetween(array $items, string $property, mixed $min, mixed $max): array
    {
        return $this->filter($items, function ($item) use ($property, $min, $max) {
            $value = $this->getProperty($item, $property);

            return $value >= $min && $value <= $max;
        });
    }

    public function distinct(array $items, ?string $property = null): array
    {
        if ($property === null) {
            return array_values(array_unique($items));
        }

        $seen = [];
        $result = [];

        foreach ($items as $item) {
            $value = $this->getProperty($item, $property);

            if (!in_array($value, $seen, $this->config->strictComparison)) {
                $seen[] = $value;
                $result[] = $item;
            }
        }

        return $result;
    }

    public function groupBy(array $items, string $property): array
    {
        $grouped = [];

        foreach ($items as $item) {
            $key = (string) $this->getProperty($item, $property);

            if (!isset($grouped[$key])) {
                $grouped[$key] = [];
            }

            $grouped[$key][] = $item;
        }

        return $grouped;
    }

    private function getProperty(mixed $item, string $property): mixed
    {
        if (is_array($item)) {
            return $item[$property] ?? null;
        }

        if (is_object($item)) {
            return $item->$property ?? null;
        }

        return null;
    }
}
