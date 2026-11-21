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
 *
 * The by-id lookup promises one block per id, so the three views share
 * an invariant: block ids are unique across the index. {@see self::add()}
 * enforces it.
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
        $existing = $this->byId[$b->id] ?? null;
        if ($existing === $b) {
            return; // idempotent re-add: the same object is already indexed.
        }
        if ($existing !== null) {
            $this->rejectIdCollision($existing, $b);
        }
        $this->byId[$b->id] = $b;
        $this->byHash[$b->structuralHash][] = $b;
        $this->all[] = $b;
    }

    /**
     * Fail loud on a colliding id: a silent byId overwrite would desync
     * get(id) from all()/byHash — the loser stays visible in the flat and
     * bucket views while lookup returns only the winner.
     */
    private function rejectIdCollision(Block $existing, Block $b): never
    {
        throw new \LogicException(sprintf(
            'BlockIndex: duplicate block id %s — the id is already registered to a different object (%s vs %s); ids must be unique per block.',
            $b->id,
            $existing->location(),
            $b->location(),
        ));
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
