<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Reporting\CoherenceAnalyzer;
use Phpdup\Util\LineRange;

final class CoherenceAnalyzerTest extends TestCase
{
    public function testNoOutliersWhenAllMembersHaveIdenticalBags(): void
    {
        $bag = ['a' => 1, 'b' => 1, 'c' => 1];
        $cluster = $this->cluster([
            $this->blk($bag), $this->blk($bag), $this->blk($bag),
        ]);
        (new CoherenceAnalyzer())->analyze([$cluster]);
        $this->assertSame([], $cluster->outlierMemberIds);
    }

    public function testFlagsClearOutlierMember(): void
    {
        $core = ['a' => 1, 'b' => 1, 'c' => 1, 'd' => 1, 'e' => 1];
        $cluster = $this->cluster([
            $this->blk($core),
            $this->blk($core),
            $this->blk($core),
            $this->blk(['z' => 1, 'y' => 1, 'x' => 1, 'w' => 1, 'v' => 1]),
        ]);
        (new CoherenceAnalyzer())->analyze([$cluster]);
        $this->assertSame([3], $cluster->outlierMemberIds);
    }

    public function testSkipsTinyClusters(): void
    {
        $cluster = $this->cluster([
            $this->blk(['a' => 1]),
            $this->blk(['z' => 1]),
        ]);
        (new CoherenceAnalyzer())->analyze([$cluster]);
        $this->assertSame([], $cluster->outlierMemberIds, 'no outliers below k=3');
    }

    public function testThresholdControlsSensitivity(): void
    {
        $cluster = $this->cluster([
            $this->blk(['a' => 1, 'b' => 1, 'c' => 1]),
            $this->blk(['a' => 1, 'b' => 1, 'c' => 1]),
            $this->blk(['a' => 1, 'b' => 1, 'd' => 1]),  // partial overlap
        ]);
        (new CoherenceAnalyzer(0.99))->analyze([$cluster]);
        $this->assertNotEmpty($cluster->outlierMemberIds, 'high threshold flags partial-overlap member');
    }

    public function testConstructorRejectsNonFiniteThreshold(): void
    {
        foreach ([NAN, INF, -INF] as $poison) {
            try {
                new CoherenceAnalyzer($poison);
                $this->fail('constructor accepted non-finite threshold ' . var_export($poison, true));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('outlierThreshold', $e->getMessage());
            }
        }
    }

    public function testConstructorRejectsOutOfRangeFiniteThreshold(): void
    {
        foreach ([-0.1, 1.1] as $poison) {
            try {
                new CoherenceAnalyzer($poison);
                $this->fail('constructor accepted out-of-range threshold ' . var_export($poison, true));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('outlierThreshold', $e->getMessage());
            }
        }
    }

    public function testConstructorAcceptsBoundaryThresholds(): void
    {
        $this->assertNotNull(new CoherenceAnalyzer(0.0));
        $this->assertNotNull(new CoherenceAnalyzer(1.0));
    }

    public function testEachUnorderedPairScoredExactlyOnce(): void
    {
        // output-027 pin: k=6 non-empty-bag members must cost exactly
        // k(k-1)/2 = 15 similarity() calls; the old ordered-pair loop made 30.
        $cluster = $this->cluster([
            $this->blk(['a' => 1, 'b' => 1, 'c' => 1, 'd' => 1]),
            $this->blk(['a' => 1, 'b' => 2, 'e' => 1]),
            $this->blk(['c' => 1, 'f' => 1, 'g' => 1]),
            $this->blk(['a' => 3, 'h' => 1]),
            $this->blk(['b' => 1, 'c' => 2, 'i' => 1]),
            $this->blk(['d' => 1, 'e' => 1, 'j' => 2]),
        ]);
        $before = CoherenceAnalyzer::$similarityCallCount;
        (new CoherenceAnalyzer())->analyze([$cluster]);
        $this->assertSame(15, CoherenceAnalyzer::$similarityCallCount - $before);
    }

    public function testEmptyAndNullBagsConsumeNoPairCalls(): void
    {
        // Five members, two of them empty ([] and null — the analyzer folds
        // both via `$m->ngramBag ?? []`): only the three comparable members
        // pair up → 3 unordered calls (the ordered loop made 6), and the
        // outlier set stays the pinned [0,2,4] — empty-bag members are never
        // flagged (their default mean is 1.0).
        $m0 = $this->blk(['a' => 1, 'b' => 1]);
        $m1 = $this->blk([]);
        $m2 = $this->blk(['a' => 1, 'c' => 1]);
        $m3 = $this->blk([]);
        $m3->ngramBag = null;
        $m4 = $this->blk(['b' => 1, 'c' => 1, 'd' => 1]);
        $before = CoherenceAnalyzer::$similarityCallCount;
        $cluster = $this->cluster([$m0, $m1, $m2, $m3, $m4]);
        (new CoherenceAnalyzer())->analyze([$cluster]);
        $this->assertSame(3, CoherenceAnalyzer::$similarityCallCount - $before);
        $this->assertSame([0, 2, 4], $cluster->outlierMemberIds);
    }

    public function testTinyClustersMakeNoCalls(): void
    {
        $before = CoherenceAnalyzer::$similarityCallCount;
        (new CoherenceAnalyzer())->analyze([
            $this->cluster([$this->blk(['a' => 1]), $this->blk(['b' => 1])]),
        ]);
        $this->assertSame(0, CoherenceAnalyzer::$similarityCallCount - $before);
    }

    /**
     * Behaviour pin for the symmetric-pair rewrite: every outlier set below
     * was captured against the pre-rewrite ordered-pair implementation on
     * these exact fixtures and thresholds (harness in the step report,
     * 108 cases total — this test carries the structurally distinct subset).
     * The rewrite must reproduce them byte-identically.
     */
    public function testOutlierSetsPinnedToOrderedPairImplementation(): void
    {
        $core = ['a' => 1, 'b' => 1, 'c' => 1];
        $disjoint = ['z' => 1, 'y' => 1, 'x' => 1, 'w' => 1, 'v' => 1];

        $cases = [
            ['identical@0', [$core, $core, $core], 0.0, []],
            ['identical@1', [$core, $core, $core], 1.0, []],
            ['clear-outlier@0.6', [$core, $core, $core, $disjoint], 0.6, [3]],
            ['clear-outlier@1', [$core, $core, $core, $disjoint], 1.0, [0, 1, 2, 3]],
            ['clear-outlier@0', [$core, $core, $core, $disjoint], 0.0, []],
            ['partial-overlap@0.6', [
                ['a' => 1, 'b' => 1, 'c' => 1, 'd' => 1],
                ['a' => 1, 'b' => 1, 'e' => 2, 'f' => 1],
                ['a' => 1, 'b' => 1, 'c' => 1, 'g' => 1],
                ['h' => 1, 'i' => 3, 'j' => 1],
                ['a' => 2, 'b' => 1, 'k' => 1],
                ['a' => 1, 'l' => 1, 'm' => 1, 'n' => 1, 'o' => 1, 'p' => 1, 'q' => 1],
            ], 0.6, [0, 1, 2, 3, 4, 5]],
            ['empty-bags@0.6', [['a' => 1, 'b' => 1], [], ['a' => 1, 'c' => 1], [], ['b' => 1, 'c' => 1, 'd' => 1]], 0.6, [0, 2, 4]],
            ['empty-bags@1', [['a' => 1, 'b' => 1], [], ['a' => 1, 'c' => 1], [], ['b' => 1, 'c' => 1, 'd' => 1]], 1.0, [0, 2, 4]],
            ['all-empty@1', [[], [], []], 1.0, []],
        ];
        for ($s = 0; $s < 6; $s++) {
            $bags = [];
            for ($i = 0; $i < 4 + $s; $i++) {
                $bags[] = $this->genBag($s * 13 + $i);
            }
            $all = range(0, 3 + $s);
            $cases[] = ["stress-s$s@0.6", $bags, 0.6, $all];
        }
        $cases[] = ['stress-s3@0.5', array_map([$this, 'genBag'], [39, 40, 41, 42, 43, 44, 45]), 0.5, [0, 1, 2, 3, 4, 5, 6]];

        $big = [];
        for ($i = 0; $i < 50; $i++) {
            $big[] = $this->genBag($i * 7);
        }
        foreach ([0.0, 0.3, 0.6, 0.9, 1.0] as $t) {
            $cases[] = ["big50@$t", $big, $t, []];
        }

        foreach ($cases as [$name, $bags, $threshold, $expected]) {
            $members = [];
            foreach ($bags as $bag) {
                $members[] = $this->blk($bag);
            }
            $cluster = $this->cluster($members);
            (new CoherenceAnalyzer($threshold))->analyze([$cluster]);
            $this->assertSame($expected, $cluster->outlierMemberIds, "pin mismatch for $name");
        }
    }

    /** Deterministic generator shared with the rewrite's 108-case before/after pin. */
    /** @return array<string,int> */
    private function genBag(int $seed): array
    {
        $bag = [];
        for ($i = 0; $i < 24; $i++) {
            $v = ($seed * 31 + $i * 17) % 7;
            if ($v > 2) {
                $bag['k' . $i] = $v;
            }
        }
        return $bag;
    }

    /** @param list<Block> $members */
    private function cluster(array $members): Cluster
    {
        return new Cluster('TEST', $members, 1.0, false);
    }

    /** @param array<string,int> $bag */
    private function blk(array $bag): Block
    {
        $stmts = (new \Phpdup\Parsing\AstParser())->parseCode('<?php $x;');
        $b = new Block(
            file: 'x.php',
            range: new LineRange(1, 1),
            kind: 'method',
            namespace: null, class: null, name: null,
            ast: $stmts[0],
        );
        $b->ngramBag = $bag;
        return $b;
    }
}
