<?php
declare(strict_types=1);

namespace Acme\Cms\Hydration;

use Acme\Cms\Exceptions\HydrationException;

/**
 * @template T of object
 */
final class GenericHydrator
{
    /**
     * @param class-string<T> $class
     * @param array<string,array{cast:'int'|'string'|'datetime'|'json',required?:bool,default?:mixed}> $schema
     */
    public function __construct(
        private readonly string $class,
        private readonly array $schema,
        private readonly string $label
    ) {
    }

    /**
     * @param array<string,mixed> $row
     * @return T
     */
    public function hydrate(array $row): object
    {
        $args = [];
        foreach ($this->schema as $column => $spec) {
            if (($spec['required'] ?? false) && !isset($row[$column])) {
                throw new HydrationException("{$this->label}: missing column {$column}");
            }
            $raw = $row[$column] ?? ($spec['default'] ?? null);
            $args[] = match ($spec['cast']) {
                'int'      => (int)$raw,
                'string'   => (string)$raw,
                'datetime' => $this->toDate($raw, $column),
                'json'     => $this->toList($raw),
            };
        }
        return new $this->class(...$args);
    }

    private function toDate(mixed $raw, string $col): ?\DateTimeImmutable
    {
        if (empty($raw)) {
            return null;
        }
        try {
            return new \DateTimeImmutable((string)$raw);
        } catch (\Throwable $e) {
            throw new HydrationException("{$this->label}: bad {$col}", 0, $e);
        }
    }

    /** @return array<int,string> */
    private function toList(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }
}
