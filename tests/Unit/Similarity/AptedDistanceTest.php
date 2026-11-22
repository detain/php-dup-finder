<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Similarity;

use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Normalization\Normalizer;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Parsing\AstParser;
use Phpdup\Similarity\AptedDistance;
use Phpdup\Similarity\EditCostModel;

final class AptedDistanceTest extends TestCase
{
    public function testIdenticalTreesAreSimilarityOne(): void
    {
        $a = $this->canonical('<?php function f($x) { if ($x > 10) { return "a"; } return "b"; }');
        $sim = (new AptedDistance())->similarity($a, $a);
        $this->assertEqualsWithDelta(1.0, $sim, 0.001);
    }

    public function testRenamedVariablesAreFullySimilarUnderAggressiveNormalization(): void
    {
        $a = $this->canonical('<?php function f($items) { $sum = 0; foreach ($items as $i) { $sum += $i; } return $sum; }');
        $b = $this->canonical('<?php function g($entries) { $total = 0; foreach ($entries as $e) { $total += $e; } return $total; }');
        $this->assertEqualsWithDelta(1.0, (new AptedDistance())->similarity($a, $b), 0.001);
    }

    public function testCompletelyDifferentTreesAreLowSimilarity(): void
    {
        $a = $this->canonical('<?php function f($x) { return $x * 2; }');
        $b = $this->canonical('<?php function g($items) { foreach ($items as $i) { for ($j = 0; $j < 10; $j++) { echo $i + $j; } } }');
        $sim = (new AptedDistance())->similarity($a, $b);
        $this->assertLessThan(0.5, $sim, "unrelated trees produced similarity $sim");
    }

    public function testBoundedDistanceShortCircuitsBelowThreshold(): void
    {
        $a = $this->canonical('<?php function f($x) { return $x * 2; }');
        $b = $this->canonical('<?php function g($items) { foreach ($items as $i) { for ($j = 0; $j < 10; $j++) { echo $i + $j; } } }');
        $sim = (new AptedDistance())->similarity($a, $b, 0.95);
        $this->assertSame(0.0, $sim, 'pair below threshold must short-circuit to 0.0');
    }

    public function testNearDuplicatesScoreHighButNotPerfect(): void
    {
        // Same shape, different literal value (under aggressive both literals → 0
        // so they should fully match — this asserts canonicalisation
        // is doing its job before TED).
        $a = $this->canonical('<?php function f($u, $s) { if ($s > 10) { send("admin", $u); } }');
        $b = $this->canonical('<?php function g($u, $s) { if ($s > 99) { send("editor", $u); } }');
        $sim = (new AptedDistance())->similarity($a, $b);
        $this->assertGreaterThan(0.95, $sim, "expected high similarity for parameterizable variants, got $sim");
    }

    public function testSimilarityIsDeterministicAcrossMultipleCalls(): void
    {
        $a = $this->canonical('<?php function f($x) { if ($x > 10) { return "a"; } return "b"; }');
        $b = $this->canonical('<?php function g($y) { if ($y > 20) { return "x"; } return "y"; }');

        $sim1 = (new AptedDistance())->similarity($a, $b);
        $sim2 = (new AptedDistance())->similarity($a, $b);
        $sim3 = (new AptedDistance())->similarity($a, $b);

        $this->assertEquals($sim1, $sim2, 'similarity must be deterministic across calls');
        $this->assertEquals($sim2, $sim3, 'similarity must be deterministic across calls');
    }

    public function testNoMemoryLeakFromRepeatedSimilarityCalls(): void
    {
        $a = $this->canonical('<?php function f($x) { if ($x > 10) { return "a"; } return "b"; }');
        $b = $this->canonical('<?php function g($y) { if ($y > 20) { return "x"; } return "y"; }');

        $apted = new AptedDistance();

        $memBefore = memory_get_usage(true);
        for ($i = 0; $i < 1000; $i++) {
            $apted->similarity($a, $b);
        }
        $memAfter = memory_get_usage(true);

        $growth = $memAfter - $memBefore;
        $this->assertLessThanOrEqual(
            4 * 1024 * 1024,
            $growth,
            'Repeated similarity() calls should not cause significant memory growth'
        );
    }

    public function testDifferentParserInstancesProduceSameSimilarity(): void
    {
        $code = '<?php function f($x) { return $x * 2; }';

        $a1 = $this->canonical($code);
        $a2 = $this->canonical($code);

        $b1 = $this->canonical('<?php function g($y) { return $y * 3; }');
        $b2 = $this->canonical('<?php function h($z) { return $z * 3; }');

        $sim1 = (new AptedDistance())->similarity($a1, $b1);
        $sim2 = (new AptedDistance())->similarity($a2, $b2);
        $this->assertEquals($sim1, $sim2, 'same tree structure must give same similarity');
    }

    public function testTreeEditDistanceWithBudgetExceededNeverUnderestimates(): void
    {
        $a = $this->canonical('<?php
        class A {
            public function f($x) { return $x + 1; }
            public function g($x) { return $x + 2; }
            public function h($x) { return $x + 3; }
        }');
        $b = $this->canonical('<?php
        class B {
            public function p($y) { return $y * 1; }
            public function q($y) { return $y * 2; }
            public function r($y) { return $y * 3; }
        }');

        $sim = (new AptedDistance())->similarity($a, $b, 0.99);

        $this->assertLessThan(
            0.95,
            $sim,
            "Very different trees must not get falsely inflated similarity near 1.0"
        );
    }

    public function testForestDpCompletesAllCellsBeforeBudgetCheck(): void
    {
        $codeA = '<?php
        function foo($a, $b, $c, $d, $e) {
            if ($a) { return 1; }
            if ($b) { return 2; }
            if ($c) { return 3; }
            if ($d) { return 4; }
            return 5;
        }';
        $codeB = '<?php
        function bar($w, $x, $y, $z) {
            if ($w) { return 10; }
            if ($x) { return 20; }
            if ($y) { return 30; }
            return 40;
        }';

        $a = $this->canonical($codeA);
        $b = $this->canonical($codeB);

        $sim = (new AptedDistance())->similarity($a, $b);
        $this->assertLessThan(
            0.95,
            $sim,
            "Structurally different functions must not get falsely inflated similarity near 1.0"
        );
    }

    /**
     * engine-007 (S08a) — flatten() must not reorder siblings by
     * subtree size. The reviewer's canonical repro: `{ $u=1;
     * if($u){g();} }` against its statement-permuted form used to
     * score TED similarity 1.0000 (edit distance 0) because the size
     * sort collapsed the two trees to the same canonical sequence.
     * With physical child order restored the permuted pair is a real
     * edit and scores strictly below one.
     */
    public function testPermutedStatementsScoreBelowOne(): void
    {
        $a = $this->canonical('<?php function f() { $u = 1; if ($u) { g(); } }');
        $b = $this->canonical('<?php function f() { if ($u) { g(); } $u = 1; }');

        $sim = (new AptedDistance())->similarity($a, $b);
        $this->assertLessThan(
            1.0000,
            $sim,
            "statement-permuted bodies must charge edit distance, got $sim (engine-007 regression)"
        );
        $this->assertGreaterThan(0.0, $sim, 'a permutation of the same body is not totally unrelated');
    }

    /**
     * engine-007 (S08a) — swapped UNEQUAL-size siblings must yield a
     * positive distance, never 1.0. The old size-sort pulled the
     * small statement in front of the large one in both trees, so a
     * size-skewed reordering was silently erased; order now survives.
     */
    public function testSwappedUnequalSizeSiblingsHavePositiveDistance(): void
    {
        $a = $this->canonical('<?php function f() { $x = 1; if ($c) { g(); h(); } }');
        $b = $this->canonical('<?php function f() { if ($c) { g(); h(); } $x = 1; }');

        $sim = (new AptedDistance())->similarity($a, $b);
        $this->assertLessThan(1.0, $sim, 'size-skewed swap must not collapse to identity');
        $this->assertGreaterThan(0.0, $sim, 'size-skewed swap is still the same statement multiset');
    }

    /**
     * engine-007 (S08a) — the order-sensitive structuralHash and the
     * TED similarity must never DISAGREE on identical-multiset bodies.
     * structuralHash is a pre-order token stream (order matters); TED
     * is an ordered-tree distance (statement order matters). For a body
     * and its permutation, "identical" must mean the same thing to both.
     */
    public function testStructuralHashOrderAgreesWithTedOrder(): void
    {
        $hasher = new SubtreeHasher();

        $base = '<?php function f() { $u = 1; if ($u) { g(); } }';
        $perm = '<?php function f() { if ($u) { g(); } $u = 1; }';

        $nBase = $this->canonical($base);
        $nPerm = $this->canonical($perm);

        // Same statement order → hash-equal AND TED-identical.
        $nBase2 = $this->canonical($base);
        $this->assertSame($hasher->hash($nBase), $hasher->hash($nBase2), 'same order → same hash');
        $this->assertEqualsWithDelta(
            1.0,
            (new AptedDistance())->similarity($nBase, $nBase2),
            1e-9,
            'same order → TED identity (hash and TED agree: duplicate)'
        );

        // Permuted order → hash-different AND TED-not-identity.
        $this->assertNotSame(
            $hasher->hash($nBase),
            $hasher->hash($nPerm),
            'structuralHash is order-sensitive (pre-order tokens)'
        );
        $this->assertLessThan(
            1.0,
            (new AptedDistance())->similarity($nBase, $nPerm),
            'TED charges the permutation too (hash and TED agree: not a duplicate)'
        );
    }

    private function canonical(string $code, string $mode = 'aggressive'): \PhpParser\Node
    {
        $parser = new AstParser();
        $extractor = new BlockExtractor(minSize: 1);
        $stmts = $parser->parseCode($code);
        $blocks = $extractor->extract('test.php', $stmts);
        $this->assertNotEmpty($blocks);
        (new Normalizer($mode))->normalize($blocks[0]);
        return $blocks[0]->canonical;
    }

    // ------------------------------------------------------------------
    // engine-009 — per-pair input memoisation + budget-banded DP
    // ------------------------------------------------------------------

    /**
     * Fixture roster for the engine-009 property pins: pairs chosen so the
     * band actually prunes (size-skewed near-dupes at production-like
     * thresholds) and so the memo actually gets shared (node `A` recurs in
     * three pairs).
     *
     * @return array<string, array{0: string, 1: string}> code-pair keyed by label
     */
    private static function engine009Pairs(): array
    {
        $a = '<?php function f($x) { if ($x > 10) { return "a"; } return "b"; }';
        return [
            // Pure near-dupe: renamed vars, equal shape → sim 1.0.
            'renamed-dupe' => [
                '<?php function f($items) { $sum = 0; foreach ($items as $i) { $sum += $i; } return $sum; }',
                '<?php function g($entries) { $total = 0; foreach ($entries as $e) { $total += $e; } return $total; }',
            ],
            // Statement permutation (engine-007 shape): real edit distance.
            'permuted' => [
                '<?php function f() { $u = 1; if ($u) { g(); } }',
                '<?php function f() { if ($u) { g(); } $u = 1; }',
            ],
            // Size-skewed pair: forces the |r − c| band to bound real work.
            'size-skew' => [
                '<?php function f($a, $b, $c, $d, $e) { if ($a) { return 1; } if ($b) { return 2; } if ($c) { return 3; } if ($d) { return 4; } if ($e) { return 5; } return 6; }',
                '<?php function g($w, $x, $y) { if ($w) { return 10; } if ($x) { return 20; } return 30; }',
            ],
            // Same trunk, unrelated tails: the DP must saturate, never mis-report.
            'shared-prefix' => [$a, '<?php function g($y) { return $y * 3; }'],
            // Structurally unrelated: budget abort path.
            'unrelated' => [
                '<?php function f($x) { return $x * 2; }',
                '<?php function g($items) { foreach ($items as $i) { for ($j = 0; $j < 10; $j++) { echo $i + $j; } } }',
            ],
        ];
    }

    /**
     * engine-009 acceptance: memoised vs unmemoised results identical,
     * property-tested across the fixture roster at four thresholds. A
     * fresh instance per measurement is the unmemoised baseline (cold
     * flatten/worstCost); the shared instance's second call is fully
     * memo-warm.
     */
    public function testMemoisedResultsMatchUnmemoisedAcrossFixturePairs(): void
    {
        $shared = new AptedDistance();
        foreach (self::engine009Pairs() as $name => [$codeA, $codeB]) {
            $a = $this->canonical($codeA);
            $b = $this->canonical($codeB);
            foreach ([0.0, 0.5, 0.85, 0.99] as $threshold) {
                $cold = (new AptedDistance())->similarity($a, $b, $threshold);
                // Prime the shared memo on this pair, then read it warm.
                $shared->similarity($a, $b, $threshold);
                $warm = $shared->similarity($a, $b, $threshold);
                $this->assertSame(
                    $cold,
                    $warm,
                    "memoised similarity must be bit-identical to cold for pair '$name' at threshold $threshold"
                );
            }
        }
    }

    /**
     * engine-009 acceptance: the object-identity probe. Inputs are
     * computed once per distinct node object for the instance's life, and
     * a node shared across different pairs rides the same entry.
     */
    public function testRepeatedCallsReuseMemoisedInputs(): void
    {
        $a = $this->canonical('<?php function f($x) { if ($x > 10) { return "a"; } return "b"; }');
        $b = $this->canonical('<?php function g($y) { return $y * 3; }');

        $d = new AptedDistance();
        $this->assertFalse($d->hasMemoisedInputs($a), 'fresh instance must not claim cached inputs');
        $this->assertFalse($d->hasMemoisedInputs($b));

        $d->similarity($a, $b);

        $this->assertTrue($d->hasMemoisedInputs($a), 'similarity() must memoise both trees (object identity)');
        $this->assertTrue($d->hasMemoisedInputs($b));

        // A third tree seen with the SAME `a` reuses a's entry — proven by
        // result equality against the cold path plus the warm flag.
        $c = $this->canonical('<?php function h($z) { foreach ($z as $q) { echo $q; } }');
        $warm = $d->similarity($a, $c);
        $cold = (new AptedDistance())->similarity($a, $c);
        $this->assertSame($cold, $warm, 'cross-pair memo reuse must not perturb results');
        $this->assertTrue($d->hasMemoisedInputs($c));

        // The memo is per-run instance state, never a shared table: a new
        // instance must not inherit another instance's cached tuple
        // (different cost model / unnumbered mode would otherwise leak).
        $this->assertFalse((new AptedDistance())->hasMemoisedInputs($a));
    }

    /**
     * engine-009 (banded DP): an accepted pair reports the EXACT distance
     * the full DP would have computed; a rejected pair must have an exact
     * score below its threshold (every gate — size delta, sketch bound,
     * budgeted DP — rejects only pairs whose true score cannot clear it).
     * The threshold-0.0 run is the no-pruning oracle: budget = worstCost
     * ≥ every positional bound, so its band covers the whole matrix.
     */
    public function testBandPruneKeepsAcceptedDistancesExact(): void
    {
        foreach (self::engine009Pairs() as $name => [$codeA, $codeB]) {
            $a = $this->canonical($codeA);
            $b = $this->canonical($codeB);
            $exact = (new AptedDistance())->similarity($a, $b, 0.0);
            foreach ([0.5, 0.85, 0.99] as $threshold) {
                $gated = (new AptedDistance())->similarity($a, $b, $threshold);
                if ($gated > 0.0) {
                    $this->assertSame(
                        $exact,
                        $gated,
                        "banded pair '$name' at $threshold accepted a distance the exact DP disagrees with"
                    );
                } else {
                    $this->assertLessThan(
                        $threshold,
                        $exact,
                        "banded pair '$name' at $threshold rejected a pair the exact DP scores at or above it"
                    );
                }
            }
        }
    }

    /**
     * engine-009: the memo must never cross cost-model or unnumbered-mode
     * boundaries — each configuration keeps its own tuples, and cold and
     * warm agree per instance. The pair below scores 1.0 under the
     * default/semantic cost models but saturates differently under them
     * at tighter thresholds, so the isolation pins carry real signal.
     */
    public function testMemoRespectsInstanceConfiguration(): void
    {
        $a = $this->canonical('<?php function f($x) { if ($x > 10) { return "a"; } return "b"; }');
        $b = $this->canonical('<?php function g($y) { if ($y > 20) { return "x"; } return "y"; }');

        $semantic = new EditCostModel(EditCostModel::MODEL_SEMANTIC);
        $configs = [
            'default'    => [null, false],
            'semantic'   => [$semantic, false],
            'unnumbered' => [null, true],
        ];
        $gated = [];
        foreach ($configs as $label => [$model, $unnumbered]) {
            $cold = (new AptedDistance($model, $unnumbered))->similarity($a, $b, 0.0);
            $warm = new AptedDistance($model, $unnumbered);
            $warm->similarity($a, $b, 0.0);
            $this->assertSame($cold, $warm->similarity($a, $b, 0.0), "memo must be consistent for '$label'");
            $this->assertGreaterThan(0.0, $cold, "fixture pair must score above zero for '$label'");
            $gated[$label] = (new AptedDistance($model, $unnumbered))->similarity($a, $b, 0.99);
        }
        // Cost-model discrimination survives per-instance memoisation:
        // at the tight threshold the semantic model saturates this
        // literal-heavy pair where the default model still accepts it, so
        // an entry written by one configuration must never be read back
        // by another.
        $this->assertNotSame($gated['default'], $gated['semantic']);
    }
}
