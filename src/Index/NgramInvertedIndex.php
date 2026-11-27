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
 * table exactly once instead of once per posting. Each posting
 * incidence kept a reference to the block's id string (slot bytes +
 * refcount traffic); engine-011 measured preprocess residency dominated
 * by the per-block ngram bags together with the index's string postings
 * — this half lays the ordered-int substrate, and engine-S18b's master
 * gram dictionary (NgramFingerprint) took the bag side: every distinct
 * gram keeps one canonical key string per fingerprinter, and both the
 * bags and this index's posting keys reference that shared object
 * instead of a fresh 16-char zend_string per incidence. {@see candidatesFor()}
 * decodes
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

    /**
     * Item 0.5 (F3, improve_logic_info.md §3): per-block bag snapshots kept
     * beside the postings so the candidate walk can price each proposed
     * pair's Σmin upper bound without re-touching the corpus. PHP arrays are
     * copy-on-write: this slot is a REFERENCE to the block's bag array, not
     * a duplicate — the bytes already lived in the Block. The bound is only
     * sound while nobody rewrites a bag between build() and enumeration,
     * and nothing in the pipeline does: the only post-build ngramBag writer
     * is the §5a guard, and it writes a FRESH array onto a CLONE (the live
     * block's bag is never touched). This invariant is pinned by
     * {@see \Phpdup\Tests\Unit\Index\NgramInvertedIndexMassTest}.
     *
     * @var array<int,array<string|int,int>> internal int id → build-time bag
     */
    private array $bagsByInt = [];

    /** @var array<int,int> internal int id → total bag mass (Σ of counts) */
    private array $massByInt = [];

    /** @var float|null document-frequency cap the {@see $skippedByInt} pass was computed for */
    private ?float $skipDf = null;

    /** @var array<int,int> internal int id → mass sitting in df-FAILING grams (for {@see $skipDf} only) */
    private array $skippedByInt = [];

    private int $blockCount = 0;

    /**
     * @param callable(int $indexed, int $total): void|null $progressCallback
     */
    public function build(BlockIndex $index, ?callable $progressCallback = null): void
    {
        $this->postings = [];
        $this->idByInt = [];
        $this->intById = [];
        $this->bagsByInt = [];
        $this->massByInt = [];
        $this->skipDf = null;
        $this->skippedByInt = [];
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
        // F3 mass substrate (item 0.5): the bag reference + its total ride
        // alongside the postings. array_sum walks the bag once per block
        // here — the O(corpus·bag) pass the enumeration later amortises
        // across every pair touching the block (candidates run 787-3,400
        // per block on measured corpora).
        $this->bagsByInt[$intId] = $b->ngramBag;
        $this->massByInt[$intId] = array_sum($b->ngramBag);
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
        $maxDf = $this->maxDfFor($maxDocumentFrequency);
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

    /**
     * Rareness cap: ceil(df × N), floored at 2. The floor keeps the
     * smallest informative signal — a gram shared by exactly two
     * blocks — proposable on ANY corpus; without it, every corpus
     * under 1/df blocks prunes all postings (df ≥ 2 there) and
     * near-dup candidate generation is dead. What the pair actually
     * is, the score gates decide. Shared verbatim between
     * {@see candidatesFor()} and the F3 walk below — the two MUST
     * agree on which grams pass, or the exact shared mass the bound
     * credits would not be the mass the proposal stream saw.
     */
    private function maxDfFor(float $maxDocumentFrequency): int
    {
        return max(2, (int)ceil($this->blockCount * $maxDocumentFrequency));
    }

    /**
     * Candidate list + Σmin mass evidence in one walk (item 0.5, F3).
     *
     * Same ids, same first-touch order, same self-exclusion as
     * {@see candidatesFor()} — the returned list is byte-identical to what
     * candidatesFor() hands the enumeration. Alongside it rides the raw
     * material for the multiset upper-bound pre-test
     * ({@see \Phpdup\Clustering\Clusterer} kills with it): for each
     * candidate, the EXACT sum Σ min(count_a(g), count_b(g)) over the
     * shared grams that passed the rareness cap, plus both blocks' total
     * bag mass and df-failed (skipped) mass.
     *
     * Soundness contract (improve_logic_info.md §3): the true multiset
     * intersection I of the two live bags satisfies
     *     I ≤ min(mass_min, shared + min(skipped_a, skipped_b))
     * — df-passing shared grams contribute exactly their min (the walk
     * sums them); df-failing grams contribute at most the smaller side's
     * skipped mass; nothing exceeds the smaller bag. The caller uses only
     * these figures; the bound arithmetic (threshold algebra, epsilon)
     * lives beside the scorer's comparisons it must mirror.
     *
     * @return array{ids: list<string>, shared: list<int>, mass: list<int>,
     *               skipped: list<int>, ownMass: int, ownSkipped: int}
     *         six parallel arrays/lists (index i describes ids[i])
     */
    public function candidatesWithSharedMass(Block $block, float $maxDocumentFrequency): array
    {
        if ($block->ngramBag === null) {
            return ['ids' => [], 'shared' => [], 'mass' => [], 'skipped' => [], 'ownMass' => 0, 'ownSkipped' => 0];
        }
        $maxDf = $this->maxDfFor($maxDocumentFrequency);
        $this->ensureSkippedMass($maxDocumentFrequency);

        $ownInt = $this->intById[$block->id] ?? -1;
        /** @var array<int,int> internal int id → exact df-passing shared mass (first-touch order) */
        $sharedSeen = [];
        $ownSkipped = 0;
        foreach ($block->ngramBag as $gram => $count) {
            $posting = $this->postings[$gram] ?? [];
            if (count($posting) > $maxDf) {
                // Too common to propose through — its mass is invisible to
                // the shared sum and must be credited back as bound slack
                // (exactly the grams ensureSkippedMass() counts per block).
                $ownSkipped += $count;
                continue;
            }
            foreach ($posting as $otherInt) {
                if ($otherInt === $ownInt) {
                    continue;
                }
                $theirBag = $this->bagsByInt[$otherInt] ?? [];
                $sharedSeen[$otherInt] = ($sharedSeen[$otherInt] ?? 0) + min($count, $theirBag[$gram] ?? 0);
            }
        }

        $ids = [];
        $shared = [];
        $mass = [];
        $skipped = [];
        foreach ($sharedSeen as $otherInt => $sum) {
            $ids[]     = $this->idByInt[$otherInt];
            $shared[]  = $sum;
            $mass[]    = $this->massByInt[$otherInt];
            $skipped[] = $this->skippedByInt[$otherInt];
        }
        return [
            'ids'        => $ids,
            'shared'     => $shared,
            'mass'       => $mass,
            'skipped'    => $skipped,
            'ownMass'    => $this->massByInt[$ownInt] ?? array_sum($block->ngramBag),
            'ownSkipped' => $ownSkipped,
        ];
    }

    /**
     * One lazy pass computing every interned block's df-failed mass for the
     * given cap (the bound's slack term). Recomputed only when the cap
     * changes — the enumeration queries one fixed df per run, so the walk
     * costs O(corpus·bag) exactly once per candidate generation.
     */
    private function ensureSkippedMass(float $maxDocumentFrequency): void
    {
        if ($this->skipDf === $maxDocumentFrequency) {
            return;
        }
        $maxDf = $this->maxDfFor($maxDocumentFrequency);
        $skipped = [];
        foreach ($this->bagsByInt as $intId => $bag) {
            $sum = 0;
            foreach ($bag as $gram => $count) {
                if (count($this->postings[$gram] ?? []) > $maxDf) {
                    $sum += $count;
                }
            }
            $skipped[$intId] = $sum;
        }
        $this->skippedByInt = $skipped;
        $this->skipDf = $maxDocumentFrequency;
    }
}
