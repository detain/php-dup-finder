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
 *   2. the document-frequency cap prunes grams above the cap, and the cap
 *      floors at 1 on small corpora;
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

    public function testDocumentFrequencyCapFloorsToOneOnSmallCorpora(): void
    {
        // maxDf = max(1, floor(N × df)) — with a default 0.01 df on 50 blocks
        // the cap floors at 1, so ANY gram shared by two blocks is pruned.
        // (Explains why fixture-scale corpora need an explicit df to surface
        // near-duplicate candidates at all.)
        $blocks = [];
        for ($i = 0; $i < 50; $i++) {
            $blocks[] = $this->makeBlockWithNgrams(sprintf('B%02d', $i), 'hash_' . $i, ['unique_' . $i => 1]);
        }
        $blocks[0]->ngramBag['pair_with_1'] = 1;
        $blocks[1]->ngramBag['pair_with_1'] = 1;

        $index = $this->buildIndex($blocks);
        $inverted = new NgramInvertedIndex();
        $inverted->build($index);

        $this->assertSame([], $inverted->candidatesFor($blocks[0], 0.01), '2 postings > floored cap of 1');
        $this->assertSame(['B01'], $inverted->candidatesFor($blocks[0], 0.04), 'floor(50×0.04)=2 ⇒ gram at the cap seeds');
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
}
