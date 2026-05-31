<?php

declare(strict_types=1);

namespace App\Repository;

final class ItemSelector
{
    public function select(array $records, callable $condition): array
    {
        $output = [];

        foreach ($records as $record) {
            if ($condition($record)) {
                $output[] = $record;
            }
        }

        return $output;
    }

    public function selectByKey(array $records, callable $keyFn): array
    {
        $output = [];

        foreach ($records as $k => $record) {
            if ($keyFn($k, $record)) {
                $output[$k] = $record;
            }
        }

        return $output;
    }

    public function selectWithPosition(array $records, callable $condition): array
    {
        $output = [];

        foreach ($records as $idx => $record) {
            if ($condition($record, $idx)) {
                $output[$idx] = $record;
            }
        }

        return $output;
    }

    public function matches(array $records, string $field, mixed $val): array
    {
        return $this->select($records, fn($r) => $this->valueOf($r, $field) === $val);
    }

    public function excludes(array $records, string $field, mixed $val): array
    {
        return $this->select($records, fn($r) => $this->valueOf($r, $field) !== $val);
    }

    public function matchesAny(array $records, string $field, array $options): array
    {
        return $this->select($records, fn($r) => in_array($this->valueOf($r, $field), $options, true));
    }

    public function excludesAll(array $records, string $field, array $options): array
    {
        return $this->select($records, fn($r) => !in_array($this->valueOf($r, $field), $options, true));
    }

    public function isEmpty(array $records, string $field): array
    {
        return $this->select($records, fn($r) => $this->valueOf($r, $field) === null);
    }

    public function isPresent(array $records, string $field): array
    {
        return $this->select($records, fn($r) => $this->valueOf($r, $field) !== null);
    }

    public function exceeds(array $records, string $field, mixed $limit): array
    {
        return $this->select($records, fn($r) => $this->valueOf($r, $field) > $limit);
    }

    public function below(array $records, string $field, mixed $limit): array
    {
        return $this->select($records, fn($r) => $this->valueOf($r, $field) < $limit);
    }

    public function withinRange(array $records, string $field, mixed $low, mixed $high): array
    {
        return $this->select($records, function ($r) use ($field, $low, $high) {
            $v = $this->valueOf($r, $field);

            return $v >= $low && $v <= $high;
        });
    }

    public function beginsWith(array $records, string $field, string $start): array
    {
        return $this->select($records, fn($r) => str_starts_with((string) $this->valueOf($r, $field), $start));
    }

    public function endsWith(array $records, string $field, string $ending): array
    {
        return $this->select($records, fn($r) => str_ends_with((string) $this->valueOf($r, $field), $ending));
    }

    public function includesSubstring(array $records, string $field, string $needle): array
    {
        return $this->select($records, fn($r) => str_contains((string) $this->valueOf($r, $field), $needle));
    }

    public function matchesPattern(array $records, string $field, string $regex): array
    {
        return $this->select($records, fn($r) => (bool) preg_match($regex, (string) $this->valueOf($r, $field)));
    }

    public function only(array $records, ?string $field = null): array
    {
        if ($field === null) {
            return array_values(array_unique($records));
        }

        $seen = [];
        $output = [];

        foreach ($records as $record) {
            $v = $this->valueOf($record, $field);

            if (!in_array($v, $seen, true)) {
                $seen[] = $v;
                $output[] = $record;
            }
        }

        return $output;
    }

    public function uniqueRecords(array $records): array
    {
        return $this->only($records);
    }

    public function limit(array $records, int $n): array
    {
        return array_slice($records, 0, $n);
    }

    public function offset(array $records, int $n): array
    {
        return array_slice($records, $n);
    }

    public function splitInto(array $records, int $size): array
    {
        return array_chunk($records, $size);
    }

    public function splitBy(array $records, callable $condition): array
    {
        $yes = [];
        $no = [];

        foreach ($records as $record) {
            if ($condition($record)) {
                $yes[] = $record;
            } else {
                $no[] = $record;
            }
        }

        return [$yes, $no];
    }

    public function removeMatching(array $records, callable $condition): array
    {
        return $this->select($records, fn($r) => !$condition($r));
    }

    public function firstMatch(array $records, ?callable $condition = null): mixed
    {
        if ($condition === null) {
            return $records[0] ?? null;
        }

        foreach ($records as $record) {
            if ($condition($record)) {
                return $record;
            }
        }

        return null;
    }

    public function lastMatch(array $records, ?callable $condition = null): mixed
    {
        if ($condition === null) {
            return $records[count($records) - 1] ?? null;
        }

        for ($i = count($records) - 1; $i >= 0; $i--) {
            if ($condition($records[$i])) {
                return $records[$i];
            }
        }

        return null;
    }

    public function extract(array $records, string $field): array
    {
        $output = [];

        foreach ($records as $record) {
            $output[] = $this->valueOf($record, $field);
        }

        return $output;
    }

    public function extractWithKeys(array $records, string $field): array
    {
        $output = [];

        foreach ($records as $k => $record) {
            $output[$k] = $this->valueOf($record, $field);
        }

        return $output;
    }

    public function indexBy(array $records, string $field): array
    {
        $output = [];

        foreach ($records as $record) {
            $output[(string) $this->valueOf($record, $field)] = $record;
        }

        return $output;
    }

    public function clusterBy(array $records, string $field): array
    {
        $output = [];

        foreach ($records as $record) {
            $key = (string) $this->valueOf($record, $field);

            if (!isset($output[$key])) {
                $output[$key] = [];
            }

            $output[$key][] = $record;
        }

        return $output;
    }

    public function orderBy(array $records, string $field, string $dir = 'asc'): array
    {
        $sorted = $records;
        $dir = strtolower($dir) === 'desc' ? -1 : 1;

        usort($sorted, fn($a, $b) => $this->cmp($this->valueOf($a, $field), $this->valueOf($b, $field)) * $dir);

        return $sorted;
    }

    public function orderByMany(array $records, array $fields): array
    {
        $sorted = $records;

        usort($sorted, function ($a, $b) use ($fields) {
            foreach ($fields as $field) {
                $comparison = $this->cmp($this->valueOf($a, $field), $this->valueOf($b, $field));

                if ($comparison !== 0) {
                    return $comparison;
                }
            }

            return 0;
        });

        return $sorted;
    }

    private function valueOf(mixed $item, string $field): mixed
    {
        if (is_array($item)) {
            return $item[$field] ?? null;
        }

        if (is_object($item)) {
            return $item->$field ?? null;
        }

        return null;
    }

    private function cmp(mixed $a, mixed $b): int
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
