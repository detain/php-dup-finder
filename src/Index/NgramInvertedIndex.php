<?php
declare(strict_types=1);

namespace Phpdup\Index;

use Phpdup\Extraction\Block;

/**
 * Inverted index from canonical n-gram → block ids.
 *
 * Used to generate the candidate-pair list for near-duplicate
 * comparison without doing all-pairs Jaccard. For each block we look
 * up all blocks sharing at least one *rare* n-gram (a gram appearing
 * in at most ceil(maxDocumentFrequency × N) blocks, the cap never
 * falling below 2). This is the standard rare-gram pre-filter for
 * large clone-detection corpora — keeps candidate generation near
 * linear in the corpus size.
 *
 * The floor of 2 is load-bearing (finding engine-001): a gram shared
 * by two distinct blocks has posting count ≥ 2 by definition, so a
 * cap that can drop below 2 (floor(df × N) on any corpus smaller
 * than 1/df blocks) prunes EVERY pair seed and the whole near-dup
 * stack goes silent. Pairs are proposed and the downstream score
 * gates prune what is not a clone.
 */
final class NgramInvertedIndex
{
    /** @var array<string|int,list<string>> ngram → block ids. Keys are string (xxh64 hex)
     *                                          in normal mode; int (hi^lo) when --low-memory. */
    private array $postings = [];
    private int $blockCount = 0;

    /**
     * @param callable(int $indexed, int $total): void|null $progressCallback
     */
    public function build(BlockIndex $index, ?callable $progressCallback = null): void
    {
        $this->postings = [];
        $this->blockCount = $index->size();
        $indexed = 0;
        foreach ($index->all() as $b) {
            $this->indexBlock($b);
            $indexed++;
            if ($progressCallback !== null) {
                $progressCallback($indexed, $this->blockCount);
            }
        }
    }

    private function indexBlock(Block $b): void
    {
        if ($b->ngramBag === null) {
            return;
        }
        foreach (array_keys($b->ngramBag) as $gram) {
            $this->postings[$gram][] = $b->id;
        }
    }

    /**
     * @return list<string> candidate block ids that share at least one
     *                      rare ngram with $block, excluding $block itself.
     */
    public function candidatesFor(Block $block, float $maxDocumentFrequency): array
    {
        if ($block->ngramBag === null) {
            return [];
        }
        // Rareness cap: ceil(df × N), floored at 2. The floor keeps the
        // smallest informative signal — a gram shared by exactly two
        // blocks — proposable on ANY corpus; without it, every corpus
        // under 1/df blocks prunes all postings (df ≥ 2 there) and
        // near-dup candidate generation is dead. What the pair actually
        // is, the score gates decide.
        $maxDf = max(2, (int)ceil($this->blockCount * $maxDocumentFrequency));
        $seen = [];
        foreach (array_keys($block->ngramBag) as $gram) {
            $posting = $this->postings[$gram] ?? [];
            if (count($posting) > $maxDf) {
                continue; // too common to be informative
            }
            foreach ($posting as $otherId) {
                if ($otherId === $block->id) {
                    continue;
                }
                $seen[$otherId] = true;
            }
        }
        return array_keys($seen);
    }
}
