<?php
declare(strict_types=1);

namespace Phpdup\Util;

/**
 * Immutable AST path node: a cons list of sub-node segments from the
 * template root to one slot (php-dup-finder-output-039).
 *
 * The three unification walkers used to rebuild their path on every
 * visit — a `[...$path, $sub]` array copy plus an `implode` over
 * `array_map('strval', ...)` — making each descent O(depth) in copies
 * and per-node O(depth) in string work. A NodePath carries the whole
 * history in one parent pointer: `child()` is O(1), and `key()` is
 * computed once per node and memoized, so a full walk costs O(1) per
 * visit.
 *
 * The key grammar is the historical one, byte-identical:
 * segments joined with '/', the empty path (root) keyed ''. Static
 * {@see self::keyOfList()} single-sources that grammar for consumers
 * holding a materialized list (Cluster::$holePaths), so producers and
 * consumers can never drift.
 *
 * Hole-path contract (php-dup-finder-once-039): materialized paths are
 * `list<int|string>` — sub-node names are strings, list indices stay
 * ints. toPathList() preserves both types exactly, so holePaths rows
 * remain byte-identical to the old spread-built lists.
 */
final class NodePath
{
    private ?string $keyCache = null;

    private function __construct(
        private readonly ?self $parent,
        private readonly int|string $segment,
    ) {
    }

    /** The empty path — the template root. */
    public static function root(): self
    {
        return new self(null, '');
    }

    /** Descend one segment; O(1), shares this node as parent. */
    public function child(int|string $segment): self
    {
        return new self($this, $segment);
    }

    /**
     * Slash-joined key of this path in the legacy grammar — memoized,
     * so repeated lookups at one node cost nothing.
     */
    public function key(): string
    {
        if ($this->keyCache !== null) {
            return $this->keyCache;
        }
        if ($this->parent === null) {
            return $this->keyCache = '';
        }
        $parentKey        = $this->parent->key();
        return $this->keyCache = $parentKey === '' ? (string) $this->segment : $parentKey . '/' . $this->segment;
    }

    /**
     * Materialize the path as the legacy `list<int|string>` — segment
     * types preserved (indices stay ints, names stay strings). Root is
     * the empty list.
     *
     * @return list<int|string>
     */
    public function toPathList(): array
    {
        if ($this->parent === null) {
            return [];
        }
        $segments = [];
        for ($node = $this; $node->parent !== null; $node = $node->parent) {
            $segments[] = $node->segment;
        }
        return array_reverse($segments);
    }

    /**
     * The legacy key grammar for a materialized path list — the single
     * source shared by every consumer of Cluster::$holePaths and by
     * toPathList()/key() themselves.
     *
     * @param list<int|string> $path
     */
    public static function keyOfList(array $path): string
    {
        return implode('/', array_map('strval', $path));
    }
}
