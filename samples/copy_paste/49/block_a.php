<?php

declare(strict_types=1);

namespace App\DataAccess;

final class CollectionFilter
{
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

    public function filterByKey(array $items, callable $keyPredicate): array
    {
        $result = [];

        foreach ($items as $key => $item) {
            if ($keyPredicate($key, $item)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    public function filterWithIndex(array $items, callable $predicate): array
    {
        $result = [];

        foreach ($items as $index => $item) {
            if ($predicate($item, $index)) {
                $result[$index] = $item;
            }
        }

        return $result;
    }

    public function where(array $items, string $property, mixed $value): array
    {
        return $this->filter($items, fn($item) => $this->getProperty($item, $property) === $value);
    }

    public function whereNot(array $items, string $property, mixed $value): array
    {
        return $this->filter($items, fn($item) => $this->getProperty($item, $property) !== $value);
    }

    public function whereIn(array $items, string $property, array $values): array
    {
        return $this->filter($items, fn($item) => in_array($this->getProperty($item, $property), $values, true));
    }

    public function whereNotIn(array $items, string $property, array $values): array
    {
        return $this->filter($items, fn($item) => !in_array($this->getProperty($item, $property), $values, true));
    }

    public function whereNull(array $items, string $property): array
    {
        return $this->filter($items, fn($item) => $this->getProperty($item, $property) === null);
    }

    public function whereNotNull(array $items, string $property): array
    {
        return $this->filter($items, fn($item) => $this->getProperty($item, $property) !== null);
    }

    public function whereGreater(array $items, string $property, mixed $threshold): array
    {
        return $this->filter($items, fn($item) => $this->getProperty($item, $property) > $threshold);
    }

    public function whereLess(array $items, string $property, mixed $threshold): array
    {
        return $this->filter($items, fn($item) => $this->getProperty($item, $property) < $threshold);
    }

    public function whereBetween(array $items, string $property, mixed $min, mixed $max): array
    {
        return $this->filter($items, function ($item) use ($property, $min, $max) {
            $value = $this->getProperty($item, $property);

            return $value >= $min && $value <= $max;
        });
    }

    public function whereStartsWith(array $items, string $property, string $prefix): array
    {
        return $this->filter($items, fn($item) => str_starts_with((string) $this->getProperty($item, $property), $prefix));
    }

    public function whereEndsWith(array $items, string $property, string $suffix): array
    {
        return $this->filter($items, fn($item) => str_ends_with((string) $this->getProperty($item, $property), $suffix));
    }

    public function whereContains(array $items, string $property, string $substring): array
    {
        return $this->filter($items, fn($item) => str_contains((string) $this->getProperty($item, $property), $substring));
    }

    public function whereMatches(array $items, string $property, string $pattern): array
    {
        return $this->filter($items, fn($item) => (bool) preg_match($pattern, (string) $this->getProperty($item, $property)));
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

            if (!in_array($value, $seen, true)) {
                $seen[] = $value;
                $result[] = $item;
            }
        }

        return $result;
    }

    public function unique(array $items): array
    {
        return $this->distinct($items);
    }

    public function take(array $items, int $count): array
    {
        return array_slice($items, 0, $count);
    }

    public function skip(array $items, int $count): array
    {
        return array_slice($items, $count);
    }

    public function chunk(array $items, int $size): array
    {
        return array_chunk($items, $size);
    }

    public function partition(array $items, callable $predicate): array
    {
        $matches = [];
        $nonMatches = [];

        foreach ($items as $item) {
            if ($predicate($item)) {
                $matches[] = $item;
            } else {
                $nonMatches[] = $item;
            }
        }

        return [$matches, $nonMatches];
    }

    public function reject(array $items, callable $predicate): array
    {
        return $this->filter($items, fn($item) => !$predicate($item));
    }

    public function first(array $items, ?callable $predicate = null): mixed
    {
        if ($predicate === null) {
            return $items[0] ?? null;
        }

        foreach ($items as $item) {
            if ($predicate($item)) {
                return $item;
            }
        }

        return null;
    }

    public function last(array $items, ?callable $predicate = null): mixed
    {
        if ($predicate === null) {
            return $items[count($items) - 1] ?? null;
        }

        for ($i = count($items) - 1; $i >= 0; $i--) {
            if ($predicate($items[$i])) {
                return $items[$i];
            }
        }

        return null;
    }

    public function pluck(array $items, string $property): array
    {
        $result = [];

        foreach ($items as $item) {
            $result[] = $this->getProperty($item, $property);
        }

        return $result;
    }

    public function pluckWithKey(array $items, string $property): array
    {
        $result = [];

        foreach ($items as $key => $item) {
            $result[$key] = $this->getProperty($item, $property);
        }

        return $result;
    }

    public function keyBy(array $items, string $property): array
    {
        $result = [];

        foreach ($items as $item) {
            $key = (string) $this->getProperty($item, $property);
            $result[$key] = $item;
        }

        return $result;
    }

    public function groupBy(array $items, string $property): array
    {
        $result = [];

        foreach ($items as $item) {
            $key = (string) $this->getProperty($item, $property);

            if (!isset($result[$key])) {
                $result[$key] = [];
            }

            $result[$key][] = $item;
        }

        return $result;
    }

    public function sortBy(array $items, string $property, string $direction = 'asc'): array
    {
        $sorted = $items;
        $direction = strtolower($direction) === 'desc' ? -1 : 1;

        usort($sorted, fn($a, $b) => $this->compareValues($this->getProperty($a, $property), $this->getProperty($b, $property)) * $direction);

        return $sorted;
    }

    public function sortByMultiple(array $items, array $properties): array
    {
        $sorted = $items;

        usort($sorted, function ($a, $b) use ($properties) {
            foreach ($properties as $property) {
                $cmp = $this->compareValues(
                    $this->getProperty($a, $property),
                    $this->getProperty($b, $property)
                );

                if ($cmp !== 0) {
                    return $cmp;
                }
            }

            return 0;
        });

        return $sorted;
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

    private function compareValues(mixed $a, mixed $b): int
    {
        if ($a === $b) {
            return 0;
        }

        if (is_numeric($a) && is_numeric($b)) {
            return $a <=> $b;
        }

        return strcmp((string) $a, (string) $b);
    }
}
