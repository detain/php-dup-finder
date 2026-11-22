<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Index;

use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\Block;
use Phpdup\Index\BlockIndex;
use Phpdup\Index\NgramInvertedIndex;
use Phpdup\Util\LineRange;

/**
 * Tests for {@see NgramInvertedIndex::candidatesFor()} — the rare-gram
 * posting union that {@see \Phpdup\Clustering\Clusterer::generateCandidatePairs()}
 * walks, and therefore the behaviour the candidate-budget guard sits on.
 *
 * Locks the three facts the guard's design rests on:
 *   1. any single shared rare gram makes a pair (storm mechanism,
 *      improve_logic.md §3.6 item 0.5 / filing 03 §1 row 1);
 *   2. the document-frequency cap prunes grams above ceil(df × N),
 *      and the cap floors at 2 — a gram shared by exactly two blocks
 *      is the smallest informative signal and must seed candidates on
 *      any corpus (finding engine-001: a cap of 1 pruned every pair
 *      and killed near-dup detection below 1/df blocks);
 *   3. inside a clone family the per-block candidate set grows linearly in
 *      family size while the TOTAL pair set grows quadratically — which is
 *      precisely what the Clusterer budget bounds.
 *
 * Filing 03 §7 names this file as the repo's index-behaviour coverage gap.
 */
final class NgramInvertedIndexTest extends TestCase
{
    private function makeBlockWithNgrams(string $id, string $uniqueHash, array $ngrams): Block
    {
        $node = new \PhpParser\Node\Stmt\Return_(
            new \PhpParser\Node\Scalar\String_($id)
        );

        $block = new Block(
            file: 'synthetic.php',
            range: new LineRange(1, 1),
            kind: 'function',
            namespace: null,
            class: null,
            name: null,
            ast: $node,
        );
        $block->id = $id;
        $block->structuralHash = $uniqueHash;
        $block->ngramBag = $ngrams;

        return $block;
    }

    /** @param list<Block> $blocks */
    private function buildIndex(array $blocks): BlockIndex
    {
        $index = new BlockIndex();
        foreach ($blocks as $b) {
            $index->add($b);
        }
        return $index;
    }

    public function testSingleSharedRareGramMakesEachBlockTheOthersCandidate(): void
    {
        $a = $this->makeBlockWithNgrams('A', 'hash_a', ['shared' => 1, 'only_a' => 1]);
        $b = $this->makeBlockWithNgrams('B', 'hash_b', ['shared' => 1, 'only_b' => 1]);
        $stranger = $this->makeBlockWithNgrams('S', 'hash_s', ['lonely' => 1]);

        $index = $this->buildIndex([$a, $b, $stranger]);
        $inverted = new NgramInvertedIndex();
        $inverted->build($index);

        // df 1.0 over 3 blocks ⇒ maxDf = 3, every gram counts as rare.
        $this->assertSame(['B'], $inverted->candidatesFor($a, 1.0));
        $this->assertSame(['A'], $inverted->candidatesFor($b, 1.0));
        $this->assertSame([], $inverted->candidatesFor($stranger, 1.0));
    }

    public function testBlockIsNeverItsOwnCandidate(): void
    {
        $a = $this->makeBlockWithNgrams('A', 'hash_a', ['g' => 1]);
        $index = $this->buildIndex([$a]);
        $inverted = new NgramInvertedIndex();
        $inverted->build($index);

        $this->assertSame([], $inverted->candidatesFor($a, 1.0), 'self must be filtered from postings');
    }

    public function testGramAboveDocumentFrequencyCapIsSkipped(): void
    {
        // Four blocks; all four share "common" (4 postings), one pair
        // shares "rare" (2 postings). With N=4 and df=0.5 ⇒ maxDf=2:
        // "common" is strictly above the cap and must seed nothing.
        $a = $this->makeBlockWithNgrams('A', 'hash_a', ['common' => 1, 'rare' => 1]);
        $b = $this->makeBlockWithNgrams('B', 'hash_b', ['common' => 1, 'rare' => 1]);
        $c = $this->makeBlockWithNgrams('C', 'hash_c', ['common' => 1]);
        $d = $this->makeBlockWithNgrams('D', 'hash_d', ['common' => 1]);

        $index = $this->buildIndex([$a, $b, $c, $d]);
        $inverted = new NgramInvertedIndex();
        $inverted->build($index);

        $candidates = $inverted->candidatesFor($a, 0.5);
        sort($candidates);
        $this->assertSame(['B'], $candidates, 'only the 2-posting gram may seed; the 4-posting gram is pruned');
    }

    public function testCapFloorsAtTwoSoSharedPairsSeedCandidatesOnSmallCorpora(): void
    {
        // maxDf = max(2, ceil(N × df)) — with the default 0.01 df on 50
        // blocks the cap floors at 2, so a gram shared by exactly two
        // blocks still seeds a candidate (finding engine-001: the old
        // max(1, floor(…)) made near-dup generation dead below 200
        // blocks, since any shared gram has ≥ 2 postings). A gram in
        // three blocks stays above the cap and is still pruned.
        $blocks = [];
        for ($i = 0; $i < 50; $i++) {
            $blocks[] = $this->makeBlockWithNgrams(sprintf('B%02d', $i), 'hash_' . $i, ['unique_' . $i => 1]);
        }
        $blocks[0]->ngramBag['pair_with_1'] = 1;
        $blocks[1]->ngramBag['pair_with_1'] = 1;
        $blocks[0]->ngramBag['trio_with_1_2'] = 1;
        $blocks[1]->ngramBag['trio_with_1_2'] = 1;
        $blocks[2]->ngramBag['trio_with_1_2'] = 1;

        $index = $this->buildIndex($blocks);
        $inverted = new NgramInvertedIndex();
        $inverted->build($index);

        $candidates = $inverted->candidatesFor($blocks[0], 0.01);
        sort($candidates);
        $this->assertSame(
            ['B01'],
            $candidates,
            'max(2, ceil(50×0.01)) = 2 ⇒ the 2-posting pair seeds; the 3-posting gram stays pruned',
        );

        // --max-df extremes: the cap never drops below the pair floor…
        $this->assertSame(
            ['B01'],
            $inverted->candidatesFor($blocks[0], 0.0),
            'df=0 still proposes pairs (cap floor 2); score gates prune the noise',
        );
        // …and df=1.0 keeps every gram rare (cap = N), unchanged behaviour.
        $atOne = $inverted->candidatesFor($blocks[0], 1.0);
        sort($atOne);
        $this->assertSame(['B01', 'B02'], $atOne, 'df=1.0 ⇒ cap N=50, both shared grams seed');
    }

    public function testCapUsesCeilOfTheRawRatioAboveThePairFloor(): void
    {
        // Above the floor the cap rounds UP (ceil), never down: with 50
        // blocks and df=0.045 the raw cap is 2.25 → 3, so a gram in
        // exactly 3 blocks seeds (the old floor(2.25)=2 would have pruned
        // it), while a gram in 4 blocks is strictly above the cap → pruned.
        $blocks = [];
        for ($i = 0; $i < 50; $i++) {
            $blocks[] = $this->makeBlockWithNgrams(sprintf('B%02d', $i), 'hash_' . $i, ['unique_' . $i => 1]);
        }
        foreach ([0, 1, 2] as $k) {
            $blocks[$k]->ngramBag['trio'] = 1;
        }
        foreach ([0, 10, 11, 12] as $k) {
            $blocks[$k]->ngramBag['quad'] = 1;
        }

        $index = $this->buildIndex($blocks);
        $inverted = new NgramInvertedIndex();
        $inverted->build($index);

        $candidates = $inverted->candidatesFor($blocks[0], 0.045);
        sort($candidates);
        $this->assertSame(
            ['B01', 'B02'],
            $candidates,
            'ceil(50×0.045) = 3 ⇒ the 3-posting gram seeds; the 4-posting gram is pruned',
        );
    }

    public function testIntegerProductCapBehaviorUnchangedAcrossFloorCeilBoundary(): void
    {
        // df=0.04 on 50 blocks puts the raw product exactly on an integer
        // (2.0): floor and ceil agree, so behavior there is IDENTICAL to
        // the pre-fix formula — gram at the cap seeds.
        $blocks = [];
        for ($i = 0; $i < 50; $i++) {
            $blocks[] = $this->makeBlockWithNgrams(sprintf('B%02d', $i), 'hash_' . $i, ['unique_' . $i => 1]);
        }
        $blocks[0]->ngramBag['pair_with_1'] = 1;
        $blocks[1]->ngramBag['pair_with_1'] = 1;

        $index = $this->buildIndex($blocks);
        $inverted = new NgramInvertedIndex();
        $inverted->build($index);

        $this->assertSame(['B01'], $inverted->candidatesFor($blocks[0], 0.04), 'ceil(50×0.04)=2 ⇒ gram at the cap seeds (unchanged at integer products)');
    }

    public function testNullNgramBagProducesNoCandidates(): void
    {
        $a = $this->makeBlockWithNgrams('A', 'hash_a', ['g' => 1]);
        $b = $this->makeBlockWithNgrams('B', 'hash_b', ['g' => 1]);
        $a->ngramBag = null;

        $index = $this->buildIndex([$a, $b]);
        $inverted = new NgramInvertedIndex();
        $inverted->build($index);

        $this->assertSame([], $inverted->candidatesFor($a, 1.0));
        // B's posting for "g" still contains only A and B…
        $this->assertSame([], $inverted->candidatesFor($b, 1.0), 'bag-less block is not indexed, so nothing seeds it either');
    }

    public function testCandidateOrderFollowsBagKeyOrderAcrossGramsAndInsertionOrderWithinAGram(): void
    {
        // Determinism contract the budget guard relies on: candidates arrive
        // in first-touch order — the queried block's bag key order picks
        // which gram's postings union first, and within one gram the
        // postings run in index insertion order. Nothing is ever shuffled.
        $a = $this->makeBlockWithNgrams('A', 'hash_a', ['g1' => 1, 'g2' => 1]);
        $b2 = $this->makeBlockWithNgrams('B2', 'hash_b2', ['g1' => 1]);
        $c3 = $this->makeBlockWithNgrams('C3', 'hash_c3', ['g2' => 1]);

        $forward = $this->buildIndex([$a, $b2, $c3]);
        $invertedForward = new NgramInvertedIndex();
        $invertedForward->build($forward);
        $this->assertSame(['B2', 'C3'], $invertedForward->candidatesFor($a, 1.0), 'g1 postings before g2 postings (bag key order)');

        // Re-inserting the OTHER blocks in reverse must not reorder A's
        // candidate stream: A's own bag key order dominates across grams…
        $reversed = $this->buildIndex([$a, $c3, $b2]);
        $invertedReversed = new NgramInvertedIndex();
        $invertedReversed->build($reversed);
        $this->assertSame(['B2', 'C3'], $invertedReversed->candidatesFor($a, 1.0), 'cross-gram order follows the bag keys, not index churn');

        // …while WITHIN one shared gram, posting order (hence candidate
        // order) follows index insertion order.
        $x1 = $this->makeBlockWithNgrams('X1', 'hash_x1', ['shared' => 1]);
        $x2 = $this->makeBlockWithNgrams('X2', 'hash_x2', ['shared' => 1]);
        $probe = $this->makeBlockWithNgrams('P', 'hash_p', ['shared' => 1]);

        $early = $this->buildIndex([$probe, $x1, $x2]);
        $invertedEarly = new NgramInvertedIndex();
        $invertedEarly->build($early);
        $this->assertSame(['X1', 'X2'], $invertedEarly->candidatesFor($probe, 1.0));

        $late = $this->buildIndex([$probe, $x2, $x1]);
        $invertedLate = new NgramInvertedIndex();
        $invertedLate->build($late);
        $this->assertSame(['X2', 'X1'], $invertedLate->candidatesFor($probe, 1.0), 'intra-gram order is insertion order');
    }

    public function testCliqueFamilyGrowsPerBlockLinearlyAndTotalQuadratically(): void
    {
        // The storm seed: k blocks all sharing one gram under the df cap.
        // Each block's candidate list has k-1 entries; the total unordered
        // pair set is k(k-1)/2 — quadratic in family size. This is the
        // unbounded fan-out that Clusterer::MAX_CANDIDATE_PAIRS bounds.
        $k = 9;
        $blocks = [];
        for ($i = 0; $i < $k; $i++) {
            $blocks[] = $this->makeBlockWithNgrams(sprintf('F%02d', $i), 'hash_' . $i, ['family_gram' => 1]);
        }

        $index = $this->buildIndex($blocks);
        $inverted = new NgramInvertedIndex();
        $inverted->build($index);

        foreach ($blocks as $b) {
            $this->assertCount(
                $k - 1,
                $inverted->candidatesFor($b, 1.0),
                'each of the ' . $k . ' family members must see the other ' . ($k - 1)
            );
        }

        $uniquePairs = [];
        foreach ($blocks as $b) {
            foreach ($inverted->candidatesFor($b, 1.0) as $other) {
                $uniquePairs[strcmp($b->id, $other) < 0 ? $b->id . '|' . $other : $other . '|' . $b->id] = true;
            }
        }
        $this->assertCount(
            (int)($k * ($k - 1) / 2),
            $uniquePairs,
            'family pair mass is quadratic — the pathological growth mode the gen-loop guard caps'
        );
    }

    // ---------------------------------------------------------------
    // engine-S18a (php-dup-finder-engine-011, half A): int postings.
    // ---------------------------------------------------------------

    /** @return array<string|int,list<int>> */
    private function postingsOf(NgramInvertedIndex $inverted): array
    {
        return (new \ReflectionProperty(NgramInvertedIndex::class, 'postings'))->getValue($inverted);
    }

    /** @return list<string> */
    private function idByIntOf(NgramInvertedIndex $inverted): array
    {
        return (new \ReflectionProperty(NgramInvertedIndex::class, 'idByInt'))->getValue($inverted);
    }

    /** @return array<string,int> */
    private function intByIdOf(NgramInvertedIndex $inverted): array
    {
        return (new \ReflectionProperty(NgramInvertedIndex::class, 'intById'))->getValue($inverted);
    }

    public function testPostingsStoreSequentialIntsNotBlockIdStrings(): void
    {
        // The int-posting substrate: every posting slot is a sequential int
        // (0..k-1 in build/insertion order), never a block-id string. The id
        // strings live once each in the decode table. The old shape stored an
        // ~8-byte slot pointing at one shared zend_string, plus refcount
        // traffic, per posting; an int slot removes the string indirection
        // and the intern tables make ids addressable (engine-011).
        $a = $this->makeBlockWithNgrams('A', 'hash_a', ['shared' => 1, 'only_a' => 1]);
        $b = $this->makeBlockWithNgrams('B', 'hash_b', ['shared' => 1, 'only_b' => 1]);

        $index = $this->buildIndex([$a, $b]);
        $inverted = new NgramInvertedIndex();
        $inverted->build($index);

        $this->assertSame(
            ['shared' => [0, 1], 'only_a' => [0], 'only_b' => [1]],
            $this->postingsOf($inverted),
            'postings must be exactly the interned sequential ints, in insertion order'
        );
        foreach ($this->postingsOf($inverted) as $gram => $posting) {
            foreach ($posting as $intId) {
                $this->assertIsInt($intId, 'posting for gram ' . $gram . ' leaked a non-int');
            }
        }
        $this->assertSame(['A', 'B'], $this->idByIntOf($inverted));
        $this->assertSame(['A' => 0, 'B' => 1], $this->intByIdOf($inverted));
    }

    public function testOneInternPerBlockAcrossAllItsGrams(): void
    {
        // "Map block ids → sequential ints once": a block with many grams
        // still contributes exactly one table entry, and every posting on
        // its side of the index carries that single int.
        $grams = [];
        for ($i = 0; $i < 40; $i++) {
            $grams['gram_' . $i] = 1;
        }
        $a = $this->makeBlockWithNgrams('A', 'hash_a', $grams);
        $b = $this->makeBlockWithNgrams('B', 'hash_b', ['gram_0' => 1]);

        $index = $this->buildIndex([$a, $b]);
        $inverted = new NgramInvertedIndex();
        $inverted->build($index);

        $this->assertSame(['A' => 0, 'B' => 1], $this->intByIdOf($inverted), 'one intern per block, not per gram');
        $this->assertSame(['A', 'B'], $this->idByIntOf($inverted));
        $postings = $this->postingsOf($inverted);
        $this->assertSame([0], $postings['gram_1'], 'A-only gram posts A\'s single int');
        $this->assertSame([0, 1], $postings['gram_0'], 'the shared gram posts both ints');
        // The decode must give the real id strings back on every candidate.
        $this->assertSame(['B'], $inverted->candidatesFor($a, 1.0));
    }

    public function testAllDecimalBlockIdDecodesBackToString(): void
    {
        // Truthfulness pin: the old `$seen[$otherId]` hash key-coerced an
        // all-decimal id string to an int, so candidatesFor() could yield
        // ints while promising list<string> (same coercion class engine-S32
        // met on gram keys). Int postings decode through the table, so the
        // original string always comes back byte-identical.
        $a = $this->makeBlockWithNgrams('123456', 'hash_a', ['shared' => 1]);
        $b = $this->makeBlockWithNgrams('789012', 'hash_b', ['shared' => 1]);

        $index = $this->buildIndex([$a, $b]);
        $inverted = new NgramInvertedIndex();
        $inverted->build($index);

        $candidates = $inverted->candidatesFor($a, 1.0);
        $this->assertSame(['789012'], $candidates, 'candidate must be the id string, not a coerced int');
        $this->assertIsString($candidates[0]);
    }

    public function testCandidateDedupKeepsFirstTouchOrderUnderInts(): void
    {
        // Dedup moved from string keys to int keys. The observable stream
        // must be unchanged: first-touch order across gram keys, one entry
        // even when several grams share the same poster (the budget guard's
        // determinism contract, same as the order test above).
        $a = $this->makeBlockWithNgrams('A', 'hash_a', ['g1' => 1, 'g2' => 1]);
        $b = $this->makeBlockWithNgrams('B', 'hash_b', ['g1' => 1, 'g2' => 1]);
        $c = $this->makeBlockWithNgrams('C', 'hash_c', ['g2' => 1]);

        $index = $this->buildIndex([$a, $b, $c]);
        $inverted = new NgramInvertedIndex();
        $inverted->build($index);

        $this->assertSame(
            ['B', 'C'],
            $inverted->candidatesFor($a, 1.0),
            'B posts on both g1 and g2 but appears once, at its first touch'
        );
    }

    public function testBuildResetsInternTables(): void
    {
        // Rebuilding the same instance must not alias stale ints: a leftover
        // idByInt entry would decode a second build's postings to dead ids.
        $a = $this->makeBlockWithNgrams('A', 'hash_a', ['g' => 1]);
        $b = $this->makeBlockWithNgrams('B', 'hash_b', ['g' => 1]);
        $x = $this->makeBlockWithNgrams('X', 'hash_x', ['h' => 1]);

        $inverted = new NgramInvertedIndex();
        $inverted->build($this->buildIndex([$a, $b]));
        $inverted->build($this->buildIndex([$x]));

        $this->assertSame(['h' => [0]], $this->postingsOf($inverted));
        $this->assertSame(['X'], $this->idByIntOf($inverted));
        $this->assertSame(['X' => 0], $this->intByIdOf($inverted));

        // And the queried-in-index self-exclusion still runs on ints:
        $this->assertSame([], $inverted->candidatesFor($x, 1.0));
    }

    public function testBlockNotInternedByBuildIsNeverSelfExcludedByAccident(): void
    {
        // A block skipped at build (null bag ⇒ no intern, no postings) has
        // no int in the table, so candidatesFor() falls back to the -1
        // sentinel — an int no real posting can ever hold. Reachable shape:
        // the indexed copy had no bag, the queried copy does (post-reload).
        $indexedNoBag = $this->makeBlockWithNgrams('A', 'hash_a', ['g' => 1]);
        $b = $this->makeBlockWithNgrams('B', 'hash_b', ['g' => 1]);
        $indexedNoBag->ngramBag = null;

        $index = $this->buildIndex([$indexedNoBag, $b]);
        $inverted = new NgramInvertedIndex();
        $inverted->build($index);

        // A has no intern entry; queried with a bag again (post-reload
        // shape), it must see B — nothing about the sentinel excludes B.
        $withBag = $this->makeBlockWithNgrams('A', 'hash_a', ['g' => 1]);
        $this->assertSame(['B'], $inverted->candidatesFor($withBag, 1.0));
        // And B never sees A, because A was never posted at all.
        $this->assertSame([], $inverted->candidatesFor($b, 1.0));
    }

    // ---------------------------------------------------------------
    // engine-S18b (php-dup-finder-engine-011, half B): the master gram
    // dictionary must be invisible to this index — key CONTENT, not
    // object identity, is what the S18a external contract rides on.
    // ---------------------------------------------------------------

    private const DICT_INDEX_A = '<?php function ia($x) { $v = 0; $v += 7; $v += 21; $v += 400; return $v; }';
    private const DICT_INDEX_B = '<?php function ib($y) { $v = 0; $v += 21; $v += 990; $v += 7; return $v; }';

    public function testCandidateStreamsAreIdenticalForInternedAndPlainContentBags(): void
    {
        // One fingerprinter interns both bags (shared canonical key strings);
        // a hand-copied bag has identical key CONTENTS but freshly built
        // strings. Indexing both must be indistinguishable — internally
        // (same posting tables) and externally (same list<string> streams).
        // If dictionary interning ever changed key content (coercion,
        // trimming, species), this pin breaks first.
        $fp        = new \Phpdup\Fingerprint\NgramFingerprint(5);
        $internedA = $fp->fingerprint($this->tree(self::DICT_INDEX_A));
        $internedB = $fp->fingerprint($this->tree(self::DICT_INDEX_B));
        $this->assertGreaterThan(0, $fp->dictionarySize(), 'probe must have run through the dictionary');

        $plainA = [];
        foreach ($internedA as $key => $count) {
            $plainA['' . $key] = $count;
        }
        $plainB = [];
        foreach ($internedB as $key => $count) {
            $plainB['' . $key] = $count;
        }

        $dictIndex  = new NgramInvertedIndex();
        $dictIndex->build($this->buildIndex([
            $this->makeBlockWithNgrams('A', 'h_a', $internedA),
            $this->makeBlockWithNgrams('B', 'h_b', $internedB),
        ]));
        $plainIndex = new NgramInvertedIndex();
        $plainIndex->build($this->buildIndex([
            $this->makeBlockWithNgrams('A', 'h_a', $plainA),
            $this->makeBlockWithNgrams('B', 'h_b', $plainB),
        ]));

        $this->assertSame($this->postingsOf($dictIndex), $this->postingsOf($plainIndex),
            'posting tables moved between interned and plain-content bags');

        $blockA = $this->makeBlockWithNgrams('A', 'h_a', $internedA);
        $blockB = $this->makeBlockWithNgrams('B', 'h_b', $internedB);
        $this->assertSame($dictIndex->candidatesFor($blockA, 1.0), $plainIndex->candidatesFor($blockA, 1.0));
        $this->assertSame($dictIndex->candidatesFor($blockB, 1.0), $plainIndex->candidatesFor($blockB, 1.0));
    }

    private function tree(string $code): \PhpParser\Node
    {
        $stmts = (new \Phpdup\Parsing\AstParser())->parseCode($code);
        self::assertNotNull($stmts, 'fixture code must parse');
        $blocks = (new \Phpdup\Extraction\BlockExtractor(minSize: 1))->extract('t.php', $stmts);
        self::assertNotEmpty($blocks, 'fixture must yield a block');
        (new \Phpdup\Normalization\Normalizer('aggressive'))->normalize($blocks[0]);
        return $blocks[0]->canonical;
    }
}
