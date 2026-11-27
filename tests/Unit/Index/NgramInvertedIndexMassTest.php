<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Index;

use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\Block;
use Phpdup\Index\BlockIndex;
use Phpdup\Index\NgramInvertedIndex;
use Phpdup\Util\LineRange;

/**
 * E1 (improve_logic.md item 0.5 / §3 'The 80 GB memory story', F3): the
 * mass-census side of NgramInvertedIndex — candidatesWithSharedMass(),
 * the per-query Σmin table the F3 upper-bound filter reads, plus the
 * build-time mass/skipped-mass snapshots.
 *
 * The filter's LOSSLESSNESS (see {@see \Phpdup\Tests\Unit\Clustering\CandidateMassFilterTest})
 * rests on two contracts pinned here:
 *   1. CANDIDATE-PARITY: the ids() leg is BYTE-IDENTICAL (order and all) to
 *      candidatesFor() at every df — the filter can only KILL pairs the old
 *      enumeration also proposed, never invent or reorder;
 *   2. BOUND-REACH: shared[i] is the EXACT Σmin over the df-PASSING grams,
 *      and ownSkipped/skipped[i] cover every gram the df-cap excluded — so
 *      shared + min(skips) >= the true full-bag Σmin the scorer would compute.
 *
 * Numbers below are hand-computed from the fixture (N=5):
 *   df 0.01 -> cap 2 (hot fails at df 3); df 0.5 -> cap 3 (hot passes).
 */
final class NgramInvertedIndexMassTest extends TestCase
{
    private function block(string $id, ?array $bag): Block
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
        $block->structuralHash = 'hash_' . $id;
        $block->ngramBag = $bag;
        return $block;
    }

    /** @param array<string,?array<string,int>> $bags id => bag (null = never interned) */
    private function build(array $bags): array
    {
        $index = new BlockIndex();
        $blocks = [];
        foreach ($bags as $id => $bag) {
            $b = $this->block($id, $bag);
            $index->add($b);
            $blocks[$id] = $b;
        }
        $inverted = new NgramInvertedIndex();
        $inverted->build($index);
        return [$index, $inverted, $blocks];
    }

    /** @return array<string,array<string,int>> */
    private function fixtureBags(): array
    {
        return [
            'B1' => ['hot' => 3, 'x' => 2, 'cold' => 1],
            'B2' => ['hot' => 2, 'x' => 1, 'y' => 5],
            'B3' => ['hot' => 1, 'z' => 4],
            'B4' => ['cold' => 7, 'w' => 2],
            'B5' => ['only_b5' => 1],
        ];
    }

    // ------------------------------------------------------------------
    // Contract 1: id-stream parity with candidatesFor()
    // ------------------------------------------------------------------

    public function testIdsAreByteIdenticalToCandidatesForAcrossEveryQueryAndDf(): void
    {
        [, $inverted, $blocks] = $this->build($this->fixtureBags());
        foreach ($blocks as $b) {
            foreach ([0.01, 0.5, 1.0] as $df) {
                $this->assertSame(
                    $inverted->candidatesFor($b, $df),
                    $inverted->candidatesWithSharedMass($b, $df)['ids'],
                    sprintf('%s ids must equal candidatesFor at df %s (order included)', $b->id, $df),
                );
            }
        }
    }

    public function testDfCapPrunesTheSameGramsInBothViews(): void
    {
        [, $inverted, $blocks] = $this->build($this->fixtureBags());
        // cap 2 at df 0.01: 'hot' (df 3) fails, so B1 sees only x->B2, cold->B4.
        $this->assertSame(['B2', 'B4'], $inverted->candidatesWithSharedMass($blocks['B1'], 0.01)['ids']);
        // cap 3 at df 0.5: 'hot' passes and B3 joins, in first-touch posting order.
        $this->assertSame(['B2', 'B3', 'B4'], $inverted->candidatesWithSharedMass($blocks['B1'], 0.5)['ids']);
    }

    // ------------------------------------------------------------------
    // Contract 2: exact shared sums + census tables (hand-computed)
    // ------------------------------------------------------------------

    public function testSharedMassSumsAreExactUnderTheDfCap(): void
    {
        [, $inverted, $blocks] = $this->build($this->fixtureBags());

        // df 0.01 (cap 2, 'hot' excluded): B1-B2 share only x:min(2,1)=1;
        // B1-B4 share cold:min(1,7)=1.
        $r = $inverted->candidatesWithSharedMass($blocks['B1'], 0.01);
        $this->assertSame([1, 1], $r['shared']);

        // df 0.5 (cap 3, 'hot' included): B1-B2 = hot:min(3,2)=2 + x:1 = 3;
        // B1-B3 = hot:min(3,1)=1; B1-B4 = cold:1.
        $r = $inverted->candidatesWithSharedMass($blocks['B1'], 0.5);
        $this->assertSame([3, 1, 1], $r['shared']);

        // B4-B1 symmetric direction: cold min(7,1)=1.
        $r = $inverted->candidatesWithSharedMass($blocks['B4'], 0.01);
        $this->assertSame([1], $r['shared']);
    }

    public function testMassAndSkippedCensusTables(): void
    {
        [, $inverted, $blocks] = $this->build($this->fixtureBags());

        $r = $inverted->candidatesWithSharedMass($blocks['B1'], 0.01);
        $this->assertSame(6, $r['ownMass']);          // 3+2+1
        $this->assertSame(3, $r['ownSkipped']);        // 'hot' mass, df-failed
        $this->assertSame([8, 9], $r['mass']);         // B2, B4 totals
        $this->assertSame([2, 0], $r['skipped']);      // B2's 'hot' 2; B4 none

        // At df 0.5 nothing exceeds the cap: every skipped leg is zero.
        $r = $inverted->candidatesWithSharedMass($blocks['B1'], 0.5);
        $this->assertSame(0, $r['ownSkipped']);
        $this->assertSame([0, 0, 0], $r['skipped']);

        // A block whose every shared gram is df-failed still reports its
        // ownSkipped exactly (the filter credits it as reachable mass).
        $r = $inverted->candidatesWithSharedMass($blocks['B3'], 0.01);
        $this->assertSame([], $r['ids']);
        $this->assertSame(5, $r['ownMass']);
        $this->assertSame(1, $r['ownSkipped']);
    }

    public function testSkipCensusRecomputesWhenTheDfChanges(): void
    {
        [, $inverted, $blocks] = $this->build($this->fixtureBags());
        $this->assertSame([0, 0, 0], $inverted->candidatesWithSharedMass($blocks['B1'], 0.5)['skipped']);
        $this->assertSame([2, 0], $inverted->candidatesWithSharedMass($blocks['B1'], 0.01)['skipped']);
        // Same df again: cache path, same values.
        $this->assertSame([2, 0], $inverted->candidatesWithSharedMass($blocks['B1'], 0.01)['skipped']);
        $this->assertSame(3, $inverted->candidatesWithSharedMass($blocks['B1'], 0.01)['ownSkipped']);
    }

    public function testBruteForceOracleMatchesEveryCellAtEveryDf(): void
    {
        [, $inverted, $blocks] = $this->build($this->fixtureBags());
        $bags = $this->fixtureBags();
        foreach ($blocks as $a) {
            foreach ([0.01, 0.5, 1.0] as $df) {
                $maxDf = max(2, (int)ceil(5 * $df));
                $dfPassed = static function (string $g) use ($bags, $maxDf): bool {
                    $n = 0;
                    foreach ($bags as $b) {
                        if (isset($b[$g])) {
                            $n++;
                        }
                    }
                    return $n <= $maxDf;
                };
                $r = $inverted->candidatesWithSharedMass($a, $df);
                foreach ($r['ids'] as $i => $cid) {
                    $shared = 0;
                    $skipped = 0;
                    foreach ($bags[$cid] as $g => $c) {
                        if ($dfPassed($g)) {
                            if (isset($a->ngramBag[$g])) {
                                $shared += min($a->ngramBag[$g], $c);
                            }
                        } else {
                            $skipped += $c;
                        }
                    }
                    $this->assertSame($shared, $r['shared'][$i], "$a->id/$cid shared at df $df");
                    $this->assertSame(array_sum($bags[$cid]), $r['mass'][$i], "$a->id/$cid mass at df $df");
                    $this->assertSame($skipped, $r['skipped'][$i], "$a->id/$cid skipped at df $df");
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Degenerate shapes
    // ------------------------------------------------------------------

    public function testNullAndEmptyBagsProduceEmptyCensusShapes(): void
    {
        $bags = $this->fixtureBags();
        $bags['N1'] = null;
        $bags['Z0'] = [];
        [, $inverted, $blocks] = $this->build($bags);

        $r = $inverted->candidatesWithSharedMass($blocks['N1'], 0.01);
        $this->assertSame([], $r['ids']);
        $this->assertSame(0, $r['ownMass']);
        $this->assertSame(0, $r['ownSkipped']);

        $r = $inverted->candidatesWithSharedMass($blocks['Z0'], 0.01);
        $this->assertSame([], $r['ids']);
        $this->assertSame(0, $r['ownMass']);

        // Null/empty blocks are never candidates either (never interned).
        $this->assertSame([], array_intersect(['N1', 'Z0'], $inverted->candidatesWithSharedMass($blocks['B1'], 1.0)['ids']));
    }

    public function testUnindexedQueryBlockYieldsEmptyShape(): void
    {
        [, $inverted, ] = $this->build($this->fixtureBags());
        $ghost = $this->block('GHOST', ['x' => 4]);
        $r = $inverted->candidatesWithSharedMass($ghost, 1.0);
        // Not interned: ids still walk its LIVE bag against real postings.
        $this->assertSame(['B1', 'B2'], $r['ids']);
        // ownMass falls back to the live bag (not in the snapshot table).
        $this->assertSame(4, $r['ownMass']);
        // Σmin uses the live query-side count against snapshot candidates:
        // x:min(4,2)=2 for B1, x:min(4,1)=1 for B2.
        $this->assertSame([2, 1], $r['shared']);
    }

    // ------------------------------------------------------------------
    // Retention invariant: guard-style clone writes must not poison the
    // snapshot the filter bounds are computed from.
    // ------------------------------------------------------------------

    public function testGuardStyleCloneRewriteDoesNotDisturbTheOriginalCensus(): void
    {
        [$index, $inverted, $blocks] = $this->build($this->fixtureBags());
        $before = $inverted->candidatesWithSharedMass($blocks['B1'], 0.01);

        // The §5a R-4 guard rewrites $view->ngramBag on a CLONE. Copy-on-write
        // means the original bag array (and thus every index bound reading it)
        // is untouched; this is the real mutation shape in generateCandidatePairs.
        $view = clone $blocks['B1'];
        $view->id = 'VIEW';
        $view->ngramBag = ['fresh_gram' => 9, 'another' => 1];
        $index->add($view);

        $after = $inverted->candidatesWithSharedMass($blocks['B1'], 0.01);
        $this->assertSame($before, $after, 'clone-slot rewrite must not move the original block\'s census');
    }
}
