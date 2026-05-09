<?php
declare(strict_types=1);

namespace Phpdup\Normalization;

/**
 * Bookkeeping for canonicalization: maps a placeholder produced during
 * normalization back to the original token it replaced, so reports can
 * show "$threshold observed: 10, 20" rather than just "<hole>".
 *
 * Positions are recorded in pre-order traversal order; the same
 * traversal order is used by AstSerializer so the indices line up.
 */
final class HoleMap
{
    /** @var array<string, list<array{kind:string, value:mixed}>> */
    private array $entries = [];

    public function record(string $kind, mixed $value, string $key): void
    {
        $this->entries[$key][] = ['kind' => $kind, 'value' => $value];
    }

    /** @return array<string,list<array{kind:string,value:mixed}>> */
    public function all(): array
    {
        return $this->entries;
    }

    public function isEmpty(): bool
    {
        return empty($this->entries);
    }
}
