<?php
declare(strict_types=1);

namespace Phpdup\Index;

use Phpdup\Extraction\Block;
use Phpdup\Fingerprint\MinHashSignature;

/**
 * Locality-Sensitive Hashing index built on top of MinHash signatures.
 *
 * Splits each block's MinHash signature into bands; two blocks
 * colliding in any band are emitted as candidate pairs. Lookups are
 * O(1) per band — significantly faster than the inverted-index
 * posting-list scan when the average n-gram bag is large.
 *
 * Use this when:
 *   - The corpus has many blocks with overlapping common ngrams
 *     (so the inverted index's posting lists grow long).
 *   - You want approximate Jaccard similarity at lookup time
 *     (the bands are tuned to the cluster threshold).
 */
final class LshIndex
{
    /** @var array<int, array<string, list<string>>> band index → band-key → block ids */
    private array $bands = [];

    /** @var array<string, list<int>> block id → MinHash signature */
    private array $signatures = [];

    public function build(BlockIndex $index): void
    {
        $this->bands      = array_fill(0, MinHashSignature::BANDS, []);
        $this->signatures = [];
        foreach ($index->all() as $b) {
            $sig = MinHashSignature::compute($b->ngramBag ?? []);
            $this->signatures[$b->id] = $sig;
            foreach ($this->bandKeys($sig) as $band => $key) {
                $this->bands[$band][$key][] = $b->id;
            }
        }
    }

    /**
     * @return list<string> candidate block ids that collide in at
     *                      least one band with $block. Excludes self.
     */
    public function candidatesFor(Block $block): array
    {
        if (!isset($this->signatures[$block->id])) {
            return [];
        }
        $sig = $this->signatures[$block->id];
        $seen = [];
        foreach ($this->bandKeys($sig) as $band => $key) {
            $bucket = $this->bands[$band][$key] ?? [];
            foreach ($bucket as $other) {
                if ($other !== $block->id) {
                    $seen[$other] = true;
                }
            }
        }
        return array_keys($seen);
    }

    /**
     * Estimated Jaccard between two registered blocks.
     */
    public function estimateJaccard(string $a, string $b): float
    {
        if (!isset($this->signatures[$a], $this->signatures[$b])) {
            return 0.0;
        }
        return MinHashSignature::jaccard($this->signatures[$a], $this->signatures[$b]);
    }

    /**
     * @param list<int> $sig
     * @return array<int, string>  band index → packed band key
     */
    private function bandKeys(array $sig): array
    {
        $keys = [];
        for ($band = 0; $band < MinHashSignature::BANDS; $band++) {
            $offset = $band * MinHashSignature::ROWS_PER_BAND;
            $slice  = array_slice($sig, $offset, MinHashSignature::ROWS_PER_BAND);
            $keys[$band] = implode('|', $slice);
        }
        return $keys;
    }
}
