<?php
declare(strict_types=1);

namespace Phpdup\Index;

use Phpdup\Extraction\Block;

/**
 * Bloom-filter-based candidate-pair index.
 *
 * Drop-in alternative to {@see NgramInvertedIndex} for very large
 * corpora where holding all (ngram, block_id) postings in RAM is
 * impractical: each block carries a fixed-width Bloom filter over
 * its n-gram bag, and candidate pairs are produced by O(n²)
 * filter-overlap comparison. Memory is O(n × m) where m is the
 * filter width (default 2048 bits = 256 bytes per block); compare
 * to the inverted index's O(corpus_unique_ngrams × posting_length).
 *
 * The filter overlap threshold is calibrated to mirror the rare-
 * gram pre-filter: pairs with overlap below {@see MIN_OVERLAP} are
 * unlikely to share enough rare ngrams to score above the Jaccard
 * threshold, so they're rejected here without further work.
 */
final class BloomCandidateIndex
{
    /** Minimum bit-overlap to keep a candidate pair. */
    public const MIN_OVERLAP = 0.05;

    /** @var array<string, BloomFilter> block id → filter */
    private array $filters = [];

    public function __construct(
        private readonly int $bits   = 2048,
        private readonly int $hashes = 3,
    ) {
    }

    public function build(BlockIndex $index): void
    {
        $this->filters = [];
        foreach ($index->all() as $b) {
            $this->filters[$b->id] = $this->filterFor($b);
        }
    }

    private function filterFor(Block $b): BloomFilter
    {
        $f = new BloomFilter($this->bits, $this->hashes);
        if ($b->ngramBag !== null) {
            foreach (array_keys($b->ngramBag) as $gram) {
                $f->add((string)$gram);
            }
        }
        return $f;
    }

    /**
     * @return list<string> candidate block ids whose filter overlaps
     *                      with $block's filter above the threshold.
     */
    public function candidatesFor(Block $block): array
    {
        if (!isset($this->filters[$block->id])) {
            return [];
        }
        $own = $this->filters[$block->id];
        $out = [];
        foreach ($this->filters as $id => $other) {
            if ($id === $block->id) continue;
            if (BloomFilter::overlap($own, $other) >= self::MIN_OVERLAP) {
                $out[] = (string)$id;
            }
        }
        return $out;
    }
}
