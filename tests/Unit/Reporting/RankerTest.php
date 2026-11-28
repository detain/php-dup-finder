<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use PhpParser\Node;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Reporting\ClusterSort;
use Phpdup\Reporting\Ranker;
use Phpdup\Util\LineRange;

final class RankerTest extends TestCase
{
    public function testDefaultSortIsImpactDescending(): void
    {
        $clusters = [
            $this->cluster('A', members: 2, blockSize: 4),  // removable ≈ 5 lines
            $this->cluster('B', members: 5, blockSize: 10), // removable ≈ 20 lines
            $this->cluster('C', members: 3, blockSize: 6),  // removable ≈ 10 lines
        ];
        $sorted = (new Ranker(minImpact: 0))->rank($clusters);
        $this->assertSame(['B', 'C', 'A'], array_map(fn(Cluster $c) => $c->id, $sorted));
    }

    public function testRespectsExplicitSortByMembersAsc(): void
    {
        $clusters = [
            $this->cluster('A', members: 2, blockSize: 100),
            $this->cluster('B', members: 5, blockSize: 4),
            $this->cluster('C', members: 3, blockSize: 50),
        ];
        $sorted = (new Ranker(minImpact: 0, sort: new ClusterSort('members', 'asc')))->rank($clusters);
        $this->assertSame(['A', 'C', 'B'], array_map(fn(Cluster $c) => $c->id, $sorted));
    }

    public function testRespectsExplicitSortByBlockSizeDesc(): void
    {
        $clusters = [
            $this->cluster('A', members: 2, blockSize: 5),
            $this->cluster('B', members: 2, blockSize: 50),
            $this->cluster('C', members: 2, blockSize: 25),
        ];
        $sorted = (new Ranker(minImpact: 0, sort: new ClusterSort('block-size', 'desc')))->rank($clusters);
        $this->assertSame(['B', 'C', 'A'], array_map(fn(Cluster $c) => $c->id, $sorted));
    }

    public function testMinImpactStillFilters(): void
    {
        $clusters = [
            $this->cluster('Big',   members: 10, blockSize: 20), // removable = 45 lines
            $this->cluster('Small', members: 2,  blockSize: 1),  // removable = 5 lines
        ];
        $sorted = (new Ranker(minImpact: 20))->rank($clusters);
        $this->assertCount(1, $sorted);
        $this->assertSame('Big', $sorted[0]->id);
    }

    public function testImpactAndConfidenceArePopulatedBeforeSorting(): void
    {
        // Sorting by confidence requires confidence to be computed first.
        $clusters = [
            $this->cluster('A', members: 3, blockSize: 5, similarity: 0.9),
            $this->cluster('B', members: 3, blockSize: 5, similarity: 1.0),
        ];
        $sorted = (new Ranker(minImpact: 0, sort: new ClusterSort('confidence', 'desc')))->rank($clusters);
        $this->assertSame('B', $sorted[0]->id);
        $this->assertGreaterThan(0.0, $sorted[0]->confidence);
    }

    // ---------------------------------------------------------------
    // Item 6.2 — impact is duplicated LINES, not AST nodes
    // ---------------------------------------------------------------

    public function testImpactCountsUnionLinesNotMembersTimesNodes(): void
    {
        // Two members, 10 source lines each, disjoint files: node sizes
        // are deliberately wild (100 vs 1) — under the old node formula
        // the impact was size-driven; on lines it is exactly half the
        // union coverage (all-but-one copy).
        $a = $this->block('x/A.php', 1, 10, size: 100);
        $b = $this->block('y/B.php', 1, 10, size: 1);
        $c = new Cluster('N1', [$a, $b], 1.0, true);
        $this->assertSame(10, (new Ranker(minImpact: 0))->rank([$c])[0]->impact);
    }

    public function testOverlappingMemberRangesDedupedByIntervalUnion(): void
    {
        // Nested ranges in one file: [1-10] and [5-12] cover 12 lines,
        // never 10 + 8 = 18. Removable share = round(12 × 1/2) = 6.
        $outer = $this->block('x/A.php', 1, 10, size: 40);
        $inner = $this->block('x/A.php', 5, 12, size: 30);
        $c = new Cluster('N2', [$outer, $inner], 1.0, true);
        $this->assertSame(6, (new Ranker(minImpact: 0))->rank([$c])[0]->impact);
    }

    public function testEqualSameFileRangesUnionOnceAcrossPerCopyShare(): void
    {
        // Three copies of the same block recorded at identical ranges
        // (window re-packing artefact): union = 5 lines, removable =
        // round(5 × 2/3) = 3 — membership count still scales it.
        $blocks = [
            $this->block('x/A.php', 1, 5, size: 9),
            $this->block('x/A.php', 1, 5, size: 9),
            $this->block('x/A.php', 1, 5, size: 9),
        ];
        $c = new Cluster('N3', $blocks, 1.0, true);
        $this->assertSame(3, (new Ranker(minImpact: 0))->rank([$c])[0]->impact);
    }

    public function testSingleMemberClusterImpactIsZero(): void
    {
        $c = new Cluster('N4', [$this->block('x/A.php', 1, 50, size: 9)], 1.0, true);
        $this->assertSame(0, (new Ranker(minImpact: 0))->rank([$c])[0]->impact);
    }

    public function testMinImpactFilterMeasuresLinesWithDefaultTwentyIntact(): void
    {
        // A 2-member pair of 9-line blocks: removable 9 < 20 floor.
        $pair = new Cluster('Below', [
            $this->block('a/A.php', 1, 9, size: 99),   // node formula would have passed
            $this->block('b/B.php', 1, 9, size: 99),
        ], 1.0, true);
        $this->assertCount(0, (new Ranker(minImpact: 20))->rank([$pair]));
    }

    // ---------------------------------------------------------------
    // Item 6.2 — composite score = lines × spread × divergence × confidence
    // ---------------------------------------------------------------

    public function testScoreKeySortsAndRewardsSpread(): void
    {
        // Identical per-copy content; Wide spans three files/dirs,
        // Narrow two members in one dir. Same impact (10 lines), so the
        // default impact sort ties — the score must break for Wide.
        $narrow = new Cluster('Narrow', [
            $this->block('p/A.php', 1, 10, size: 20),
            $this->block('p/B.php', 1, 10, size: 20),
        ], 1.0, true);
        $wide = new Cluster('Wide', [
            $this->block('q1/A.php', 1, 10, size: 20),
            $this->block('q2/B.php', 1, 10, size: 20),
            $this->block('q3/C.php', 1, 10, size: 20),
        ], 1.0, true);
        $sorted = (new Ranker(minImpact: 0, sort: new ClusterSort('score', 'desc')))
            ->rank([$narrow, $wide]);
        $this->assertSame('Wide', $sorted[0]->id);
        $this->assertGreaterThan($narrow->score, $wide->score);
    }

    public function testSpreadFactorIsOneForSingleFileSingleDir(): void
    {
        $c = new Cluster('One', [
            $this->block('only/A.php', 1, 10, size: 20),
            $this->block('only/A.php', 12, 21, size: 20),
        ], 1.0, true);
        $this->assertSame(1.0, (new Ranker(minImpact: 0))->spreadFactor($c));
    }

    /**
     * E3 r1 m-1: the spread ladder saturates per-leg and the total
     * clamps exactly at SPREAD_MAX. Cases: [files, dirs, repos, expected].
     *  - 11 distinct files = SPREAD_FILE_CAP steps exactly; more files
     *    (20) must not pay another step — the cap row is saturation.
     *  - 7 dirs: dirs−1 = 6 > SPREAD_DIR_CAP (5) -> leg stays 0.25 while
     *    files (7) still contribute their 6 steps.
     *  - 5 repos: repos−1 = 4 > SPREAD_REPO_CAP (3) -> leg stays 0.60.
     *  - all three legs over-capped: raw 1 + 1.0 + 0.25 + 0.6 = 2.85
     *    exceeds the ceiling -> clamped EXACTLY to Ranker::SPREAD_MAX.
     */
    public function testSpreadFactorSaturatesCapsAndClampsAtTheCeiling(): void
    {
        $cases = [
            'files cap reached at 11'           => [11, 1, 0, 1.0 + 0.10 * Ranker::SPREAD_FILE_CAP],
            'files cap saturates past 11'       => [20, 1, 0, 1.0 + 0.10 * Ranker::SPREAD_FILE_CAP],
            'dirs cap saturates at 7 dirs'      => [7, 7, 0, 1.0 + 0.10 * 6 + 0.05 * Ranker::SPREAD_DIR_CAP],
            'repos cap saturates at 5 repos'    => [5, 1, 5, 1.0 + 0.10 * 4 + 0.20 * Ranker::SPREAD_REPO_CAP],
            'all caps exceed the SPREAD_MAX'    => [13, 8, 5, Ranker::SPREAD_MAX],
        ];
        $ranker = new Ranker(minImpact: 0);
        foreach ($cases as $label => [$files, $dirs, $repos, $expected]) {
            $members = [];
            for ($i = 0; $i < $files; $i++) {
                $block = $this->block(
                    sprintf('d%d/f%d.php', min($i, $dirs - 1), $i),
                    1 + $i * 5,
                    5 + $i * 5,
                    size: 20,
                );
                if ($i < $repos) {
                    $block->repo = 'r' . $i;
                }
                $members[] = $block;
            }
            $cluster = new Cluster('Spread-' . $label, $members, 1.0, true);
            $this->assertEqualsWithDelta(
                $expected,
                $ranker->spreadFactor($cluster),
                1e-12,
                $label,
            );
        }
        // The ceiling row must be CLAMPED, not saturated arithmetic: the
        // raw capped sum (2.85) is strictly above the pinned value.
        $this->assertGreaterThan(
            Ranker::SPREAD_MAX,
            1.0 + 0.10 * Ranker::SPREAD_FILE_CAP
                + 0.05 * Ranker::SPREAD_DIR_CAP
                + 0.20 * Ranker::SPREAD_REPO_CAP,
            'the clamp case is vacuous unless raw over-capped sum exceeds the ceiling',
        );
    }

    public function testScoreIsZeroWhenImpactIsZero(): void
    {
        $c = new Cluster('One', [$this->block('only/A.php', 1, 10, size: 20)], 1.0, true);
        $ranked = (new Ranker(minImpact: 0))->rank([$c]);
        $this->assertSame(0.0, $ranked[0]->score);
    }

    // ---------------------------------------------------------------
    // Item 6.2 — declarative-shape demotion
    // ---------------------------------------------------------------

    public function testDtoSerializerClusterIsHardDemoted(): void
    {
        // Equal impact, equal spread, equal confidence/safety — the ONLY
        // difference is shape, so score(dto) / score(logic) is exactly
        // DEMOTION_HARD (geometric means apply too: every member dto).
        $dto = new Cluster('Dto', [
            $this->dtoBlock('m/D1.php', 1, 8, ['alpha', 'beta']),
            $this->dtoBlock('m/D2.php', 1, 8, ['gamma', 'delta']),
        ], 1.0, true);
        $logic = new Cluster('Logic', [
            $this->logicBlock('s/S1.php', 1, 8),
            $this->logicBlock('s/S2.php', 1, 8),
        ], 1.0, true);

        $ranker = new Ranker(minImpact: 0);
        $ranker->rank([$dto, $logic]);

        $this->assertSame($dto->impact, $logic->impact);
        $this->assertEqualsWithDelta(
            $this->compositeWithoutDemotion($ranker, $logic) * Ranker::DEMOTION_HARD,
            $dto->score,
            1e-9,
        );
        $this->assertLessThan($logic->score, $dto->score);
    }

    public function testRouteTableClusterIsHardDemoted(): void
    {
        $routes = new Cluster('Routes', [
            $this->routeBlock('r/R1.php', 1, 12),
            $this->routeBlock('r/R2.php', 1, 12),
        ], 1.0, true);
        $ranker = new Ranker(minImpact: 0);
        $ranker->rank([$routes]);
        $this->assertEqualsWithDelta(
            $this->compositeWithoutDemotion($ranker, $routes) * Ranker::DEMOTION_HARD,
            $routes->score,
            1e-9,
        );
    }

    public function testAccessorClusterIsHardDemoted(): void
    {
        $a = $this->block('acc/A.php', 1, 3, size: 5);
        $a->canonical = new Node\Stmt\ClassMethod('getAlpha', [
            'stmts' => [new Node\Stmt\Return_(new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), 'alpha'))],
        ]);
        $b = $this->block('acc/B.php', 1, 3, size: 5);
        $b->canonical = new Node\Stmt\ClassMethod('getBeta', [
            'stmts' => [new Node\Stmt\Return_(new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), 'beta'))],
        ]);
        $acc = new Cluster('Acc', [$a, $b], 1.0, true);
        $ranker = new Ranker(minImpact: 0);
        $ranker->rank([$acc]);
        $expected = $acc->impact * $ranker->spreadFactor($acc)
            * (Ranker::DIVERGENCE_FLOOR + (1 - Ranker::DIVERGENCE_FLOOR) * $acc->safety)
            * $acc->confidence * Ranker::DEMOTION_HARD;
        $this->assertEqualsWithDelta($expected, $acc->score, 1e-9);
    }

    public function testConstructorPromotionCopyIsHardDemoted(): void
    {
        $mk = function (string $file): Block {
            $b = $this->block($file, 1, 6, size: 12);
            $b->canonical = new Node\Stmt\ClassMethod('__FN', [
                'stmts' => [
                    new Node\Stmt\Expression(new Node\Expr\Assign(
                        new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), 'alpha'),
                        new Node\Expr\Variable('alpha'))),
                    new Node\Stmt\Expression(new Node\Expr\Assign(
                        new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), 'beta'),
                        new Node\Expr\Variable('beta'))),
                ],
            ]);
            return $b;
        };
        $c = new Cluster('Promo', [$mk('p/P1.php'), $mk('p/P2.php')], 1.0, true);
        $ranker = new Ranker(minImpact: 0);
        $ranker->rank([$c]);
        $expected = $c->impact * $ranker->spreadFactor($c)
            * (Ranker::DIVERGENCE_FLOOR + (1 - Ranker::DIVERGENCE_FLOOR) * $c->safety)
            * $c->confidence * Ranker::DEMOTION_HARD;
        $this->assertEqualsWithDelta($expected, $c->score, 1e-9);
    }

    public function testLowEntropyNoCalleeBodyIsSoftDemoted(): void
    {
        // Four repeated assignment statements, no calls at all: the
        // doc's "statement-kind entropy plus callee diversity" leg.
        $mk = function (string $file, string $prop): Block {
            $stmts = [];
            for ($i = 0; $i < 4; $i++) {
                $stmts[] = new Node\Stmt\Expression(new Node\Expr\Assign(
                    new Node\Expr\Variable("v$i"),
                    new Node\Scalar\LNumber($i)));
            }
            $b = $this->block($file, 1, 10, size: 30);
            $b->canonical = new Node\Stmt\ClassMethod($prop, ['stmts' => $stmts]);
            return $b;
        };
        $c = new Cluster('Wall', [$mk('w/W1.php', 'one'), $mk('w/W2.php', 'two')], 1.0, true);
        $ranker = new Ranker(minImpact: 0);
        $ranker->rank([$c]);
        $expected = $c->impact * $ranker->spreadFactor($c)
            * (Ranker::DIVERGENCE_FLOOR + (1 - Ranker::DIVERGENCE_FLOOR) * $c->safety)
            * $c->confidence * Ranker::DEMOTION_SOFT;
        $this->assertEqualsWithDelta($expected, $c->score, 1e-9);
    }

    public function testMixedClusterFadesGeometrically(): void
    {
        // Half DTO serializers, half real logic: factor is the geometric
        // mean sqrt(HARD × 1.0), strictly between HARD and 1.0.
        $c = new Cluster('Mix', [
            $this->dtoBlock('d/D1.php', 1, 8, ['a', 'b']),
            $this->logicBlock('d/D2.php', 1, 8),
        ], 1.0, true);
        $ranker = new Ranker(minImpact: 0);
        $ranker->rank([$c]);
        $neutral = $c->impact * $ranker->spreadFactor($c)
            * (Ranker::DIVERGENCE_FLOOR + (1 - Ranker::DIVERGENCE_FLOOR) * $c->safety)
            * $c->confidence;
        $this->assertEqualsWithDelta(
            $neutral * sqrt(Ranker::DEMOTION_HARD),
            $c->score,
            1e-9,
        );
    }

    public function testDemotionIsNeutralWithoutAnyTree(): void
    {
        // Both AST species absent (lazy-unloaded legacy row): the factor
        // must be exactly 1.0 — no demotion from missing evidence.
        $a = new Block('t/T1.php', new LineRange(1, 8), 'method', null, null, 'm', new Node\Stmt\Nop());
        $a->unloadAst();
        $b = new Block('t/T2.php', new LineRange(1, 8), 'method', null, null, 'm', new Node\Stmt\Nop());
        $b->unloadAst();
        $c = new Cluster('Bare', [$a, $b], 1.0, true);
        $this->assertSame(1.0, (new Ranker(minImpact: 0))->declarativeDemotion($c));
    }

    public function testDemotionFallsBackToAstWhenCanonicalUnset(): void
    {
        // A member without canonical but with the original AST is still
        // demoted from it (serial pre-normalize hand-built rows).
        $getter = new Block('f/F1.php', new LineRange(1, 3), 'method', null, null, 'getA',
            new Node\Stmt\ClassMethod('getA', [
                'stmts' => [new Node\Stmt\Return_(new Node\Expr\PropertyFetch(
                    new Node\Expr\Variable('this'), 'a'))],
            ]));
        $b = $this->block('f/F2.php', 1, 3, size: 5);
        $b->canonical = new Node\Stmt\ClassMethod('getB', [
            'stmts' => [new Node\Stmt\Return_(new Node\Expr\PropertyFetch(
                new Node\Expr\Variable('this'), 'b'))],
        ]);
        $c = new Cluster('Mixed', [$getter, $b], 1.0, true);
        $this->assertSame(Ranker::DEMOTION_HARD, (new Ranker(minImpact: 0))->declarativeDemotion($c));
    }

    // ---------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------

    private function compositeWithoutDemotion(Ranker $r, Cluster $c): float
    {
        return $c->impact * $r->spreadFactor($c)
            * (Ranker::DIVERGENCE_FLOOR + (1 - Ranker::DIVERGENCE_FLOOR) * $c->safety)
            * $c->confidence;
    }

    private function dtoBlock(string $file, int $start, int $end, array $props): Block
    {
        $items = [];
        foreach ($props as $p) {
            $items[] = new Node\ArrayItem(
                new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), $p),
                new Node\Scalar\String_($p),
            );
        }
        $b = $this->block($file, $start, $end, size: 16);
        $b->canonical = new Node\Stmt\ClassMethod('toArray', [
            'stmts' => [new Node\Stmt\Return_(new Node\Expr\Array_($items))],
        ]);
        return $b;
    }

    /**
     * A body the demotion legs must leave alone: assignment, a real
     * method call, and a return of a computed value — mixed statement
     * kinds and one genuine callee.
     */
    private function logicBlock(string $file, int $start, int $end): Block
    {
        $b = $this->block($file, $start, $end, size: 16);
        $b->canonical = new Node\Stmt\ClassMethod('process', [
            'stmts' => [
                new Node\Stmt\Expression(new Node\Expr\Assign(
                    new Node\Expr\Variable('sum'),
                    new Node\Expr\MethodCall(new Node\Expr\Variable('repo'), 'totals'))),
                new Node\Stmt\If_(new Node\Expr\BinaryOp\Greater(
                    new Node\Expr\Variable('sum'), new Node\Scalar\LNumber(0)),
                    ['stmts' => [new Node\Stmt\Return_(new Node\Expr\Variable('sum'))]]),
                new Node\Stmt\Return_(new Node\Expr\BinaryOp\Mul(
                    new Node\Expr\Variable('sum'), new Node\Scalar\LNumber(2))),
            ],
        ]);
        return $b;
    }

    private function routeBlock(string $file, int $start, int $end): Block
    {
        $items = [];
        foreach (['GET /a', 'POST /b', 'PUT /c', 'DELETE /d'] as $route) {
            $items[] = new Node\ArrayItem(
                new Node\Expr\ArrowFunction([
                    'expr' => new Node\Expr\New_(new Node\Name('Handler')),
                ]),
                new Node\Scalar\String_($route),
            );
        }
        $b = $this->block($file, $start, $end, size: 40);
        $b->canonical = new Node\Stmt\ClassMethod('routes', [
            'stmts' => [new Node\Stmt\Return_(new Node\Expr\Array_($items))],
        ]);
        return $b;
    }

    private function block(string $file, int $start, int $end, int $size): Block
    {
        $b = new Block(
            file:      $file,
            range:     new LineRange($start, $end),
            kind:      'method',
            namespace: null,
            class:     null,
            name:      'm',
            ast:       new Node\Stmt\Nop(),
        );
        $b->size = $size;
        return $b;
    }

    private function cluster(
        string $id,
        int $members,
        int $blockSize,
        float $similarity = 1.0,
    ): Cluster {
        $blocks = [];
        for ($i = 0; $i < $members; $i++) {
            $blocks[] = $this->block('test.php', 1 + $i * 5, 5 + $i * 5, $blockSize);
        }
        return new Cluster($id, $blocks, $similarity, false);
    }
}
