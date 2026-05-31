<?php

declare(strict_types=1);

namespace App\Queries;

final class ResultFilter
{
    public function filter(array $data, callable $fn): array
    {
        $output = [];

        foreach ($data as $element) {
            if ($fn($element)) {
                $output[] = $element;
            }
        }

        return $output;
    }

    public function filterKeys(array $data, callable $fn): array
    {
        $output = [];

        foreach ($data as $k => $element) {
            if ($fn($k, $element)) {
                $output[$k] = $element;
            }
        }

        return $output;
    }

    public function filterWithIndex(array $data, callable $fn): array
    {
        $output = [];

        foreach ($data as $i => $element) {
            if ($fn($element, $i)) {
                $output[$i] = $element;
            }
        }

        return $output;
    }

    public function matching(array $data, string $prop, mixed $val): array
    {
        return $this->filter($data, fn($d) => $this->extract($d, $prop) === $val);
    }

    public function notMatching(array $data, string $prop, mixed $val): array
    {
        return $this->filter($data, fn($d) => $this->extract($d, $prop) !== $val);
    }

    public function matchingAny(array $data, string $prop, array $candidates): array
    {
        return $this->filter($data, fn($d) => in_array($this->extract($d, $prop), $candidates, true));
    }

    public function matchingNone(array $data, string $prop, array $candidates): array
    {
        return $this->filter($data, fn($d) => !in_array($this->extract($d, $prop), $candidates, true));
    }

    public function withNull(array $data, string $prop): array
    {
        return $this->filter($data, fn($d) => $this->extract($d, $prop) === null);
    }

    public function withoutNull(array $data, string $prop): array
    {
        return $this->filter($data, fn($d) => $this->extract($d, $prop) !== null);
    }

    public function greaterThan(array $data, string $prop, mixed $threshold): array
    {
        return $this->filter($data, fn($d) => $this->extract($d, $prop) > $threshold);
    }

    public function lessThan(array $data, string $prop, mixed $threshold): array
    {
        return $this->filter($data, fn($d) => $this->extract($d, $prop) < $threshold);
    }

    public function betweenValues(array $data, string $prop, mixed $low, mixed $high): array
    {
        return $this->filter($data, function ($d) use ($prop, $low, $high) {
            $v = $this->extract($d, $prop);

            return $v >= $low && $v <= $high;
        });
    }

    public function beginningWith(array $data, string $prop, string $prefix): array
    {
        return $this->filter($data, fn($d) => str_starts_with((string) $this->extract($d, $prop), $prefix));
    }

    public function endingWith(array $data, string $prop, string $suffix): array
    {
        return $this->filter($data, fn($d) => str_ends_with((string) $this->extract($d, $prop), $suffix));
    }

    public function containing(array $data, string $prop, string $substring): array
    {
        return $this->filter($data, fn($d) => str_contains((string) $this->extract($d, $prop), $substring));
    }

    public function regexMatch(array $data, string $prop, string $pattern): array
    {
        return $this->filter($data, fn($d) => (bool) preg_match($pattern, (string) $this->extract($d, $prop)));
    }

    public function deduplicate(array $data, ?string $prop = null): array
    {
        if ($prop === null) {
            return array_values(array_unique($data));
        }

        $visited = [];
        $unique = [];

        foreach ($data as $element) {
            $v = $this->extract($element, $prop);

            if (!in_array($v, $visited, true)) {
                $visited[] = $v;
                $unique[] = $element;
            }
        }

        return $unique;
    }

    public function removeDuplicates(array $data): array
    {
        return $this->deduplicate($data);
    }

    public function takeFirst(array $data, int $n): array
    {
        return array_slice($data, 0, $n);
    }

    public function dropFirst(array $data, int $n): array
    {
        return array_slice($data, $n);
    }

    public function divide(array $data, int $size): array
    {
        return array_chunk($data, $size);
    }

    public function separate(array $data, callable $test): array
    {
        $matching = [];
        $rest = [];

        foreach ($data as $element) {
            if ($test($element)) {
                $matching[] = $element;
            } else {
                $rest[] = $element;
            }
        }

        return [$matching, $rest];
    }

    public function except(array $data, callable $test): array
    {
        return $this->filter($data, fn($d) => !$test($d));
    }

    public function fetchFirst(array $data, ?callable $test = null): mixed
    {
        if ($test === null) {
            return $data[0] ?? null;
        }

        foreach ($data as $element) {
            if ($test($element)) {
                return $element;
            }
        }

        return null;
    }

    public function fetchLast(array $data, ?callable $test = null): mixed
    {
        if ($test === null) {
            return $data[count($data) - 1] ?? null;
        }

        for ($i = count($data) - 1; $i >= 0; $i--) {
            if ($test($data[$i])) {
                return $data[$i];
            }
        }

        return null;
    }

    public function collect(array $data, string $prop): array
    {
        $values = [];

        foreach ($data as $element) {
            $values[] = $this->extract($element, $prop);
        }

        return $values;
    }

    public function collectWithKeys(array $data, string $prop): array
    {
        $values = [];

        foreach ($data as $k => $element) {
            $values[$k] = $this->extract($element, $prop);
        }

        return $values;
    }

    public function mapToKeys(array $data, string $prop): array
    {
        $indexed = [];

        foreach ($data as $element) {
            $indexed[(string) $this->extract($element, $prop)] = $element;
        }

        return $indexed;
    }

    public function groupByProperty(array $data, string $prop): array
    {
        $grouped = [];

        foreach ($data as $element) {
            $key = (string) $this->extract($element, $prop);

            if (!isset($grouped[$key])) {
                $grouped[$key] = [];
            }

            $grouped[$key][] = $element;
        }

        return $grouped;
    }

    public function orderByProperty(array $data, string $prop, string $dir = 'asc'): array
    {
        $sorted = $data;
        $dir = strtolower($dir) === 'desc' ? -1 : 1;

        usort($sorted, fn($a, $b) => $this->compare($this->extract($a, $prop), $this->extract($b, $prop)) * $dir);

        return $sorted;
    }

    public function orderByProperties(array $data, array $props): array
    {
        $sorted = $data;

        usort($sorted, function ($a, $b) use ($props) {
            foreach ($props as $prop) {
                $comparison = $this->compare($this->extract($a, $prop), $this->extract($b, $prop));

                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return 0;
        });

        return $sorted;
    }

    private function extract(mixed $item, string $field): mixed
    {
        if (is_array($item)) {
            return $item[$field] ?? null;
        }

        if (is_object($item)) {
            return $item->$field ?? null;
        }

        return null;
    }

    private function compare(mixed $x, mixed $y): int
    {
        if ($x === $y) {
            return 0;
        }

        if (is_numeric($x) && is_numeric($y)) {
            return $x <=> $y;
        }

        return strcmp((string) $x, (string) $y);
    }
}
