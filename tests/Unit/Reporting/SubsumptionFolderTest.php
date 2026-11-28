<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Reporting\SubsumptionFolder;
use Phpdup\Util\LineRange;

/**
 * Item 6.3 — the subsumption fold, pure semantics (improve_logic.md
 * §6.3 letter + improve_logic_info.md §6.3 design: injective member
 * map, chains fold to the maximal ancestor, one shared suppression
 * rule). Wiring sits in ReportStageSubsumptionTest; the conditional
 * JSON surface in JsonReporterSubsumesTest.
 *
 * Synthetic members only — containment is decided from file + line
 * ranges, so no corpus and no parser is needed for the fold law.
 */
final class SubsumptionFolderTest extends TestCase
{
    /**
     * A={outer x2}, B={inner x2} pairing file-by-file: B folds under A.
     */
    public function testSimpleFoldAnnotatesNotMultiplies(): void
    {
        $a = $this->cluster('A', [
            $this->member('/p/f1.php', 10, 100),
            $this->member('/p/f2.php', 10, 100),
        ]);
        $b = $this->cluster('B', [
            $this->member('/p/f1.php', 20, 30),
            $this->member('/p/f2.php', 20, 30),
        ]);

        $fold = (new SubsumptionFolder())->fold([$a, $b]);

        $this->assertSame(['A'], array_map(static fn(Cluster $c) => $c->id, $fold['maximal']),
            'subsumed families leave the ranked set (report maximal clones only)');
        $this->assertSame(['B'], array_map(static fn(Cluster $c) => $c->id, $fold['subsumed']));
        $this->assertSame(['B'], $a->subsumes);
        $this->assertSame('A', $b->subsumedBy);
        $this->assertSame([], $b->subsumes, 'a folded family reports no family of its own');
    }

    /**
     * The pairing law's reason for existing: two sibling witnesses
     * inside ONE member of A are not a same-pairing fold — a naive
     * per-member containment test would false-fold here.
     */
    public function testSiblingTrapNeedsDistinctParentMembers(): void
    {
        $a = $this->cluster('A', [
            $this->member('/p/f1.php', 1, 50),
            $this->member('/p/f2.php', 1, 50),
        ]);
        $b = $this->cluster('B', [
            $this->member('/p/f1.php', 10, 20),
            $this->member('/p/f1.php', 25, 35),
        ]);

        $fold = (new SubsumptionFolder())->fold([$a, $b]);

        $this->assertSameCanonical(['A', 'B'], array_map(static fn(Cluster $c) => $c->id, $fold['maximal']),
            'both siblings fit only a1 — no injective map, no fold');
        $this->assertSame([], $b->subsumes);
        $this->assertNull($b->subsumedBy);
    }

    /**
     * C ⊆ B ⊆ A chains fold to the maximal ancestor: C's attribution
     * is A (via its own direct injective edge — the composition is
     * witnessable), B's is A, and A names both.
     */
    public function testChainFoldsToMaximalAncestor(): void
    {
        [$a, $b, $c] = $this->chainPair();

        $fold = (new SubsumptionFolder())->fold([$a, $b, $c]);

        $this->assertSame(['A'], array_map(static fn(Cluster $x) => $x->id, $fold['maximal']));
        $this->assertSameCanonical(['B', 'C'], array_map(static fn(Cluster $x) => $x->id, $fold['subsumed']));
        $this->assertSame(['B', 'C'], $a->subsumes, 'deterministic sorted attribution');
        $this->assertSame('A', $b->subsumedBy);
        $this->assertSame('A', $c->subsumedBy, 'C skips the already-suppressed parent B — maximal ancestor');
    }

    /**
     * Overlapping parent members need an alternating-path steal for
     * the pairing to saturate: greedy first-fit strands it, the
     * matching must re-assign. A={p 2-50, q 40-120}, B listed so the
     * FIRST child (45-48, fits both) grabs p; the second child
     * (10-20, fits ONLY p) succeeds through p only if the matching
     * augmenting path moves the first child to q.
     */
    public function testFoldFiresWhenThePairingNeedsAnAlternatingPathSteal(): void
    {
        $a = $this->cluster('A', [
            $this->member('/p/f1.php', 2, 50),
            $this->member('/p/f1.php', 40, 120),
        ]);
        $b = $this->cluster('B', [
            $this->member('/p/f1.php', 45, 48),
            $this->member('/p/f1.php', 10, 20),
        ]);

        $fold = (new SubsumptionFolder())->fold([$a, $b]);

        $this->assertSame(['A'], array_map(static fn(Cluster $c) => $c->id, $fold['maximal']),
            'b1 steals p→q via augment, b2 lands on p: saturates, fold fires');
        $this->assertSame(['B'], $a->subsumes);
    }

    /**
     * Cross-file families pair legitimately: each B member inside a
     * DIFFERENT-file A member is still an injective containment map.
     */
    public function testCrossFilePairingFolds(): void
    {
        $a = $this->cluster('A', [
            $this->member('/p/f1.php', 1, 90),
            $this->member('/p/f2.php', 1, 90),
        ]);
        $b = $this->cluster('B', [
            $this->member('/p/f1.php', 10, 20),
            $this->member('/p/f2.php', 30, 40),
        ]);

        $fold = (new SubsumptionFolder())->fold([$a, $b]);
        $this->assertSame(['A'], array_map(static fn(Cluster $c) => $c->id, $fold['maximal']));
    }

    /**
     * Identical ranges (one block seen through two kinds, or two
     * windows pinned to the same span) never witness a fold: nothing
     * lies strictly INSIDE an equal span.
     */
    public function testEqualRangesNeverWitness(): void
    {
        $a = $this->cluster('A', [
            $this->member('/p/f1.php', 10, 100),
            $this->member('/p/f2.php', 10, 100),
        ]);
        $b = $this->cluster('B', [
            $this->member('/p/f1.php', 10, 100),
            $this->member('/p/f2.php', 10, 100),
        ]);

        $fold = (new SubsumptionFolder())->fold([$a, $b]);

        $this->assertSameCanonical(['A', 'B'], array_map(static fn(Cluster $c) => $c->id, $fold['maximal']));
        $this->assertSame([], $b->subsumes);
        $this->assertNull($b->subsumedBy);
    }

    /**
     * One member outside every distinct pairing breaks saturation:
     * the family is not subsumed (and stays whole — partial folds are
     * not a thing: "every member ... in the same pairing").
     */
    public function testPartialContainmentDoesNotFold(): void
    {
        $a = $this->cluster('A', [
            $this->member('/p/f1.php', 1, 90),
            $this->member('/p/f2.php', 1, 90),
        ]);
        $b = $this->cluster('B', [
            $this->member('/p/f1.php', 10, 20),
            $this->member('/p/f3.php', 10, 20),
        ]);

        $fold = (new SubsumptionFolder())->fold([$a, $b]);

        $this->assertSameCanonical(['A', 'B'], array_map(static fn(Cluster $c) => $c->id, $fold['maximal']));
        $this->assertNull($b->subsumedBy);
    }

    /**
     * Input-order independence: the same relation graph folds the same
     * way regardless of cluster order. The survivor list and the
     * annotations are permutation-invariant; the subsumed LIST follows
     * input order (its only consumers are follow-on report surfaces,
     * and the payload never carries it).
     */
    public function testDeterminismUnderInputPermutation(): void
    {
        $run = static function (bool $reverse): array {
            $pair = $reverse ? array_reverse(self::chainPair()) : self::chainPair();
            $fold = (new SubsumptionFolder())->fold($pair);
            $annot = [];
            foreach ($pair as $c) {
                $annot[$c->id] = [$c->subsumes, $c->subsumedBy];
            }
            ksort($annot); // compare by id, not by input order
            return [
                'maximal' => array_map(static fn(Cluster $x) => $x->id, $fold['maximal']),
                'annot'   => $annot,
            ];
        };

        $fwd = $run(false);
        $rev = $run(true);

        $this->assertSame($fwd['maximal'], $rev['maximal'], 'maximal list is order-invariant');
        $this->assertSame($fwd['annot'], $rev['annot'], 'annotations are order-invariant');
        $this->assertSame(['A'], $fwd['maximal']);
        $this->assertSame([['B', 'C'], null], $fwd['annot']['A']);
        $this->assertSame([[], 'A'], $fwd['annot']['C']);

        // The documented input-order carriage of the subsumed list:
        $fwdSub = (new SubsumptionFolder())->fold(self::chainPair())['subsumed'];
        $revSub = (new SubsumptionFolder())->fold(array_reverse(self::chainPair()))['subsumed'];
        $this->assertSameCanonical(
            array_map(static fn(Cluster $x) => $x->id, $fwdSub),
            array_map(static fn(Cluster $x) => $x->id, $revSub),
            'same families folded, list order follows the input',
        );
    }

    /**
     * Two surviving maximal ancestors both pair over Q: attribution
     * takes the id-sorted first (deterministic, FindingId tie-break
     * style) and only that one names Q. The parents are deliberately
     * incomparable (f1 ranges cross-cover, f2 equal) so both survive.
     */
    public function testAttributionTieBreakIsIdSorted(): void
    {
        $p1 = $this->cluster('P1', [
            $this->member('/p/f1.php', 1, 50), $this->member('/p/f2.php', 1, 50),
        ]);
        $p2 = $this->cluster('P2', [
            $this->member('/p/f1.php', 5, 60), $this->member('/p/f2.php', 1, 50),
        ]);
        $q = $this->cluster('Q', [
            $this->member('/p/f1.php', 20, 30), $this->member('/p/f2.php', 20, 30),
        ]);

        $fold = (new SubsumptionFolder())->fold([$p1, $p2, $q]);

        $this->assertSameCanonical(['P1', 'P2'], array_map(static fn(Cluster $c) => $c->id, $fold['maximal']),
            'neither parent contains the other — both stay maximal');
        $this->assertSame('P1', $q->subsumedBy, 'id-sorted deterministic pick among two maximal witnesses');
        $this->assertSame(['Q'], $p1->subsumes);
        $this->assertSame([], $p2->subsumes);
    }

    public function testDuplicateClusterIdFailsLoud(): void
    {
        $x = $this->cluster('DUP', [$this->member('/p/f1.php', 1, 50), $this->member('/p/f2.php', 1, 50)]);
        $y = $this->cluster('DUP', [$this->member('/p/f1.php', 10, 20), $this->member('/p/f2.php', 10, 20)]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('duplicate cluster id');
        (new SubsumptionFolder())->fold([$x, $y]);
    }

    /**
     * A member-less cluster (defensive legacy shape) is neither a
     * parent nor a child: it passes through untouched.
     */
    public function testMemberlessClusterPassesThrough(): void
    {
        $e = $this->cluster('E', []);
        $a = $this->cluster('A', [
            $this->member('/p/f1.php', 1, 50), $this->member('/p/f2.php', 1, 50),
        ]);

        $fold = (new SubsumptionFolder())->fold([$e, $a]);

        $this->assertSameCanonical(['A', 'E'], array_map(static fn(Cluster $c) => $c->id, $fold['maximal']));
        $this->assertSame([], $fold['subsumed']);
        $this->assertSame([], $e->subsumes);
        $this->assertNull($e->subsumedBy);
    }

    /**
     * No relations at all is the identity pass: maximal keeps the
     * input order, nothing annotated, subsumed empty.
     */
    public function testUnrelatedClustersPassThroughInOrder(): void
    {
        $m = $this->cluster('M', [
            $this->member('/p/f1.php', 1, 9), $this->member('/p/f1.php', 20, 29),
        ]);
        $n = $this->cluster('N', [
            $this->member('/p/f2.php', 40, 49), $this->member('/p/f2.php', 60, 69),
        ]);

        $fold = (new SubsumptionFolder())->fold([$m, $n]);

        $this->assertSame(['M', 'N'], array_map(static fn(Cluster $c) => $c->id, $fold['maximal']),
            'input order preserved exactly (Ranker re-sorts downstream)');
        $this->assertSame([], $fold['subsumed']);
        $this->assertSame([], $m->subsumes);
        $this->assertNull($n->subsumedBy);
    }

    /**
     * Non-vacuity for the demand posture, stated where the law lives:
     * the fold class never reads Config — arming is ReportStage's
     * decision; the shared suppression rule is consumed, never
     * re-implemented (grep-proof in FL.md / one keepMaximal call-site
     * family).
     */
    public function testFoldUsesTheSharedSuppressionRuleOnly(): void
    {
        $this->assertFalse(method_exists(SubsumptionFolder::class, 'keepMaximal'),
            'no second maximal-family implementation may live in the folder');
    }

    /**
     * @return array{Cluster, Cluster, Cluster} A ⊃ B ⊃ C, two-file families
     */
    private static function chainPair(): array
    {
        return [
            $outer = new Cluster('A', [
                self::mk('/p/f1.php', 1, 200), self::mk('/p/f2.php', 1, 200),
            ], 1.0, false),
            new Cluster('B', [
                self::mk('/p/f1.php', 10, 100), self::mk('/p/f2.php', 10, 100),
            ], 1.0, false),
            new Cluster('C', [
                self::mk('/p/f1.php', 20, 30), self::mk('/p/f2.php', 20, 30),
            ], 1.0, false),
        ];
    }

    /** @param list<Block> $members */
    private function cluster(string $id, array $members): Cluster
    {
        return new Cluster($id, $members, 1.0, false);
    }

    private function member(string $file, int $start, int $end): Block
    {
        return self::mk($file, $start, $end);
    }

    private static function mk(string $file, int $start, int $end): Block
    {
        return new Block(
            file: $file,
            range: new LineRange($start, $end),
            kind: 'method',
            namespace: null,
            class: null,
            name: 'm',
            ast: new Node\Stmt\Nop(),
        );
    }

    /** @param list<string> $actual */
    private function assertSameCanonical(array $expected, array $actual, string $message = ''): void
    {
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual, $message);
    }
}
