<?php
declare(strict_types=1);

namespace Phpdup\Index;

use Phpdup\Extraction\Block;

/**
 * In-memory index of all blocks in the corpus.
 *
 * Provides three views:
 *   - flat list (iteration in insertion order)
 *   - by-id lookup
 *   - hash buckets (structuralHash → list of blocks) for the fast
 *     exact-clone clustering path
 */
final class BlockIndex
{
    /** @var array<string,Block> */
    private array $byId = [];
    /** @var array<string,list<Block>> */
    private array $byHash = [];
    /** @var list<Block> */
    private array $all = [];

    public function add(Block $b): void
    {
        $this->byId[$b->id] = $b;
        $this->byHash[$b->structuralHash][] = $b;
        $this->all[] = $b;
    }

    /** @return list<Block> */
    public function all(): array
    {
        return $this->all;
    }

    public function get(string $id): ?Block
    {
        return $this->byId[$id] ?? null;
    }

    /** @return array<string,list<Block>> */
    public function hashBuckets(): array
    {
        return $this->byHash;
    }

    public function size(): int
    {
        return count($this->all);
    }
}
