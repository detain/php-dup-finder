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
 * in fewer than maxDocumentFrequency × N blocks). This is the
 * standard rare-gram pre-filter for large clone-detection corpora —
 * keeps candidate generation near linear in the corpus size.
 */
final class NgramInvertedIndex
{
    /** @var array<string,list<string>> ngram → block ids */
    private array $postings = [];
    private int $blockCount = 0;

    public function build(BlockIndex $index): void
    {
        $this->postings = [];
        $this->blockCount = $index->size();
        foreach ($index->all() as $b) {
            $this->indexBlock($b);
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
        $maxDf = max(1, (int)floor($this->blockCount * $maxDocumentFrequency));
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
