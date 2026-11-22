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
 * Postings are stored as sequential internal ints, not block-id
 * strings (php-dup-finder-engine-011, engine-S18 half A): each block
 * id is interned once per build and every posting slot is then a
 * packed int, so a 16-char id zend_string is referenced by the decode
 * table exactly once instead of once per posting. The old shape cost
 * ~one id string per (gram, block) incidence and dominated preprocess
 * residency on WordPress-scale corpora. {@see candidatesFor()} decodes
 * back to id strings, so the external contract is byte-identical —
 * and now truthful even for an all-decimal id, which the old `$seen`
 * hash would have leaked back as an int key through numeric-string
 * coercion.
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
    /** @var array<string|int,list<int>> ngram → internal int block ids. Gram keys are
     *                                      string (xxh64 hex) in normal mode; the digest
     *                                      as a positive 63-bit int under --low-memory
     *                                      (CompactNgramBag::compact, engine-042). */
    private array $postings = [];

    /** @var list<string> internal int id → block id (decode table) */
    private array $idByInt = [];

    /** @var array<string,int> block id → internal int id (interning table) */
    private array $intById = [];

    private int $blockCount = 0;

    /**
     * @param callable(int $indexed, int $total): void|null $progressCallback
     */
    public function build(BlockIndex $index, ?callable $progressCallback = null): void
    {
        $this->postings = [];
        $this->idByInt = [];
        $this->intById = [];
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
        $grams = array_keys($b->ngramBag);
        if ($grams === []) {
            return; // empty bag: no postings, no table slot
        }
        // One intern per block, not one per gram: every posting of this
        // block's grams carries the same sequential int (engine-011).
        $intId = $this->internId($b->id);
        foreach ($grams as $gram) {
            $this->postings[$gram][] = $intId;
        }
    }

    /** Map a block id to its stable sequential int, assigning one on first sight. */
    private function internId(string $id): int
    {
        $existing = $this->intById[$id] ?? null;
        if ($existing !== null) {
            return $existing;
        }
        $intId = count($this->idByInt);
        $this->idByInt[] = $id;
        $this->intById[$id] = $intId;
        return $intId;
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
        // Self-exclusion on the int side: a block that was never interned
        // (queried outside this index, or bag-less at build time) gets an
        // int no real posting can hold.
        $ownInt = $this->intById[$block->id] ?? -1;
        $seen = [];
        foreach (array_keys($block->ngramBag) as $gram) {
            $posting = $this->postings[$gram] ?? [];
            if (count($posting) > $maxDf) {
                continue; // too common to be informative
            }
            foreach ($posting as $otherInt) {
                if ($otherInt === $ownInt) {
                    continue;
                }
                $seen[$otherInt] = true;
            }
        }
        // Decode in first-touch order: the same gram-key-order ×
        // insertion-order stream the string postings produced.
        $candidates = [];
        foreach (array_keys($seen) as $intId) {
            $candidates[] = $this->idByInt[$intId];
        }
        return $candidates;
    }
}
