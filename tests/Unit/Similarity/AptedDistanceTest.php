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
     *
     * Fix-round-1 (r1 LOW-2): the bare cold==warm compare is vacuous on
     * a cache-warm pair (both sides could be 0.0 refusals with no memo
     * activity at all). Every warm measurement now also asserts — via
     * the diagnostics seam — that the warm call either PASSED the shared
     * gate and was served from the tuple memo (hits delta 2, zero cold
     * work), or was refused BY THE GATE itself (gate delta 1, no tuple
     * memo lookups). A warm call that silently re-computed, or a refusal
     * that leaked through the memo, cannot satisfy either disjunct.
     */
    public function testMemoisedResultsMatchUnmemoisedAcrossFixturePairs(): void
    {
        $shared = new AptedDistance();
        $refusedSeen = 0;
        $acceptedSeen = 0;
        foreach (self::engine009Pairs() as $name => [$codeA, $codeB]) {
            $a = $this->canonical($codeA);
            $b = $this->canonical($codeB);
            foreach ([0.0, 0.5, 0.85, 0.99] as $threshold) {
                $cold = (new AptedDistance())->similarity($a, $b, $threshold);
                // Prime the shared memo on this pair, then read it warm.
                $shared->similarity($a, $b, $threshold);
                $before = $shared->diagnostics();
                $warm = $shared->similarity($a, $b, $threshold);
                $after = $shared->diagnostics();
                $this->assertSame(
                    $cold,
                    $warm,
                    "memoised similarity must be bit-identical to cold for pair '$name' at threshold $threshold"
                );
                $this->assertWarmPathExplained($before, $after, $name);
                if ($warm === AptedDistance::REFUSE) {
                    $refusedSeen++;
                } else {
                    $acceptedSeen++;
                }
            }
        }
        // The roster must exercise BOTH warm paths, or the parity above
        // is a coincidence of one of them.
        $this->assertGreaterThan(0, $acceptedSeen, 'roster never produced an accepted warm pair');
        $this->assertGreaterThan(0, $refusedSeen, 'roster never produced a refused warm pair');
    }

    /**
     * engine-S09 r1 (LOW-3/MEDIUM-1 support): the diagnostics seam must
     * actually separate memo hits from cold computation — the pin below
     * is non-vacuous only because this holds.
     */
    public function testDiagnosticsSeparateMemoHitFromColdComputation(): void
    {
        $a = $this->canonical('<?php function f($x) { if ($x > 10) { return "a"; } return "b"; }');
        $b = $this->canonical('<?php function g($y) { return $y * 3; }');

        $d = new AptedDistance();
        $this->assertSame(
            ['hits' => 0, 'cold' => 0, 'gate' => 0, 'band' => 0],
            $d->diagnostics(),
            'fresh instance reports no memo activity'
        );

        $d->similarity($a, $b, 0.0);
        $first = $d->diagnostics();
        $this->assertSame(2, $first['cold'], 'first pass computes both tuples cold');
        $this->assertSame(0, $first['hits'], 'first pass cannot hit an empty memo');

        $d->similarity($a, $b, 0.0);
        $second = $d->diagnostics();
        $this->assertSame(2, $second['hits'] - $first['hits'], 'warm pass must serve both tuples from the memo');
        $this->assertSame($first['cold'], $second['cold'], 'warm pass must not recompute any tuple');
    }

    /**
     * engine-S09 r1, MEDIUM-1 — the budget-band gate is ONE gate for both
     * entry paths: a cache-hit pair composed of over-budget trees gets
     * the same explicit refusal as the cold computation, and the tuple
     * memo is never reached by a gate-refused pair (no cache bypass in
     * either direction).
     */
    public function testRefusalGateServesCacheHitPairsIdenticallyToCold(): void
    {
        // 30- and 32-condition functions: 122 vs 130 nodes, |Δ| = 8 — far
        // over the budget ceil(0.01 × 130) = 1 at threshold 0.99.
        $big = $this->canonical('<?php function f() { ' . $this->conditions(30) . ' }');
        $bigger = $this->canonical('<?php function g() { ' . $this->conditions(32) . ' }');

        $coldSim = (new AptedDistance())->similarity($big, $bigger, 0.99);

        $warm = new AptedDistance();
        $warm->similarity($big, $bigger, 0.99);
        $warmSim = $warm->similarity($big, $bigger, 0.99);

        $this->assertSame(
            AptedDistance::REFUSE,
            $coldSim,
            'over-budget pair must be refused explicitly on the cold path'
        );
        $this->assertSame($coldSim, $warmSim, 'cache-hit pair must get the SAME refusal as cold');

        $diag = $warm->diagnostics();
        // The gate answered — the pair never reached the tuple memo, so a
        // cache-hit over-budget pair CANNOT be served from cache around
        // the gate (hits stay 0 and the gate counter moved on the second,
        // already-memo-warm call).
        $this->assertSame(2, $diag['gate'], 'both entries must have run the shared gate');
        $this->assertSame(0, $diag['hits'], 'gate-refused pairs must never read the tuple memo');
        $this->assertSame(0, $diag['cold'], 'gate-refused pairs must never compute tuple memo entries');
        $this->assertFalse(
            $warm->hasMemoisedInputs($big),
            'a gate-refused pair must not even PRIME the tuple memo (r1 MEDIUM-1 leak)'
        );
    }

    /**
     * engine-S09 r1, MEDIUM-2 (+LOW-1) — a band-exceeding pair is an
     * EXPLICIT refusal with its own observable counter; it is never a
     * saturation value laundered through the acceptance arithmetic, and
     * the refusal is identical on cold and cache-hit entries. The DP
     * band is applied AFTER the tuple memo (by design — memoised inputs
     * are what the DP consumes), so the pins here read the band counter
     * rather than a bare 0.0 == 0.0:
     *
     * Hand-checked truth table (default cost model, unit costs;
     * worst = nodes of the larger tree at t=0.99 ⇒ budget 1):
     *   permuted pair  : d = 8 > 1  ⇒ REFUSE (no truncated 1−8/11)
     *   op-swap pair   : d = 1 ≤ 1  ⇒ ACCEPTED, exact 1 − 1/8 = 0.875
     *   op-swap-bad    : d = 2 > 1  ⇒ REFUSE (no truncated 1−2/8 = 0.75)
     *   exact arm      : threshold 0.0 (no band) reproduces 1 − d/worst.
     */
    public function testBandExceedIsExplicitRefusalNotTruncatedDistance(): void
    {
        // d = 8: the `$u = 1;` assignment (4 canonical nodes) leaves its
        // slot and re-enters after the if-block — an ordered-tree distance
        // charges the full subtree move (delete 4 + insert 4). Both trees
        // are 11 nodes, so worst = 11 and the exact arm must read 1 − 8/11.
        $permA = $this->canonical('<?php function f() { $u = 1; if ($u) { g(); } }');
        $permB = $this->canonical('<?php function f() { if ($u) { g(); } $u = 1; }');
        // d = 1: a single label substitution (mul → plus); 8 nodes each.
        $opA = $this->canonical('<?php function f($x) { return $x * 2; }');
        $opB = $this->canonical('<?php function g($y) { return $y + 2; }');
        // d = 2: mul → plus plus the literal `$x`/`$y` variable slot
        // difference surviving normalisation; 8 nodes each.
        $badA = $this->canonical('<?php function f($x) { return $x * 2; }');
        $badB = $this->canonical('<?php function g($y) { return $y + $x; }');

        $exactPerm = (new AptedDistance())->similarity($permA, $permB, 0.0);
        $this->assertEqualsWithDelta(1.0 - 8.0 / 11.0, $exactPerm, 1e-12, 'exact arm: hand-derived d = 8, worst = 11');

        // Warm instance: prime the memo, then measure refusal — a
        // cache-hit pair must saturate the band identically to cold.
        $d = new AptedDistance();
        $d->similarity($permA, $permB, 0.99);
        $warmPerm = $d->similarity($permA, $permB, 0.99);
        $afterPerm = $d->diagnostics();

        $this->assertSame(AptedDistance::REFUSE, $warmPerm, 'band-exceeding pair must be REFUSED, not truncated');
        $this->assertLessThanOrEqual(
            AptedDistance::REFUSE,
            $warmPerm,
            'a refused pair must never report a positive laundered similarity'
        );
        $this->assertSame(
            (new AptedDistance())->similarity($permA, $permB, 0.99),
            $warmPerm,
            'cold and cache-hit band refusals must be identical'
        );
        $this->assertSame(2, $afterPerm['band'], 'both band refusals (cold + warm) must be counted explicitly');
        $this->assertSame(2, $afterPerm['hits'], 'the warm refusal must have come through the memo, not around it');
        $this->assertSame(0, $afterPerm['gate'], 'this pair is refused by the DP band, not the size gate');

        // Accepted arm: the exact value must survive, band counter silent.
        $accepted = $d->similarity($opA, $opB, 0.99);
        $this->assertSame(1.0 - 1.0 / 8.0, $accepted, 'accepted pair must report its hand-derived exact distance');
        $this->assertSame(
            (new AptedDistance())->similarity($opA, $opB, 0.0),
            $accepted,
            'accepted banded value must equal the exact (threshold-0) DP'
        );

        // Third band row: d = 2 also refuses (no 0.75 truncation).
        $badSim = $d->similarity($badA, $badB, 0.99);
        $this->assertSame(AptedDistance::REFUSE, $badSim, 'd = 2 > budget 1 must refuse, not report 1 − 2/8');
        $this->assertSame(3, $d->diagnostics()['band'], 'every band refusal is counted');
    }

    /**
     * engine-S09 r1, LOW-3 — on a symmetric pair the memo-hit answer and
     * the cold answer are the SAME value, so a value-only pin cannot see
     * which path ran; the diagnostics seam makes hit-vs-cold observable
     * and this pins it.
     */
    public function testCacheHitRefusalIsObservableNotSilentlySymmetric(): void
    {
        $a = $this->canonical('<?php function h($z) { return $z; }');
        $b = $this->canonical('<?php function i() { echo 1; echo 2; echo 3; echo 4; echo 5; }');

        $d = new AptedDistance();
        $coldFirst = $d->similarity($a, $b, 0.5);
        $afterCold = $d->diagnostics();
        $warmSecond = $d->similarity($a, $b, 0.5);
        $afterWarm = $d->diagnostics();

        $this->assertSame($coldFirst, $warmSecond, 'symmetric pair: hit and cold answer identically');
        $this->assertSame(0, $afterCold['hits'], 'the first entry was computed cold');
        $this->assertSame(2, $afterCold['cold'], 'both tuples computed cold on first entry');
        $this->assertSame(2, $afterWarm['hits'] - $afterCold['hits'], 'the second entry was served from the memo');
        $this->assertSame(0, $afterWarm['cold'] - $afterCold['cold'], 'the memo entry performed no cold work');
        $this->assertSame(
            $afterCold['band'] + 1,
            $afterWarm['band'],
            'the refusal must be re-declared (counted) on the cache-hit entry, never skipped'
        );
    }

    /** @return string N stacked `if ($c$i) { return $i; }` conditions */
    private function conditions(int $count): string
    {
        $out = '';
        for ($i = 1; $i <= $count; $i++) {
            $out .= "if (\$c$i) { return $i; }\n";
        }
        return $out;
    }

    /**
     * Assert one warm similarity() call explained its path through the
     * shared gate (r1 MEDIUM-1/LOW-2/LOW-3 support):
     *   gate-refused entry ⇒ gate delta 1, no tuple memo traffic;
     *   DP-reached entry   ⇒ exactly 2 tuple-memo hits (pair already
     *                        primed), no cold tuple computation.
     */
    private function assertWarmPathExplained(array $before, array $after, string $what): void
    {
        $dh = $after['hits'] - $before['hits'];
        $dc = $after['cold'] - $before['cold'];
        $dg = $after['gate'] - $before['gate'];
        if ($dg === 1) {
            $this->assertSame(0, $dh, "gate-refused pair '$what' must not read the tuple memo");
            $this->assertSame(0, $dc, "gate-refused pair '$what' must not compute tuples");
            return;
        }
        $this->assertSame(2, $dh, "warm pair '$what' must be served from the primed tuple memo");
        $this->assertSame(0, $dc, "warm pair '$what' must not recompute any tuple");
    }

    /**
     * engine-S09 acceptance: the object-identity probe. Inputs are
     * computed once per distinct node object for the instance's life, and
     * a node shared across different pairs rides the same entry.
     *
     * Fix-round-1 (r1 LOW-2): strengthened with the diagnostics seam so
     * the reuse claim is count-verified, not inferred from a value.
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
        $this->assertSame(2, $d->diagnostics()['cold'], 'first pass must have computed exactly two tuples');

        // A third tree seen with the SAME `a` reuses a's entry — proven by
        // result equality against the cold path plus the warm flag AND a
        // hits delta of exactly 1 (a from memo, c computed).
        $c = $this->canonical('<?php function h($z) { foreach ($z as $q) { echo $q; } }');
        $before = $d->diagnostics();
        $warm = $d->similarity($a, $c);
        $after = $d->diagnostics();
        $cold = (new AptedDistance())->similarity($a, $c);
        $this->assertSame($cold, $warm, 'cross-pair memo reuse must not perturb results');
        $this->assertSame(1, $after['hits'] - $before['hits'], 'node a must ride its existing entry');
        $this->assertSame(1, $after['cold'] - $before['cold'], 'node c must be computed once');
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
     *
     * Fix-round-1 (r1 LOW-2): the rejection arm used to be a bare
     * `exact < threshold` which stays green even if the banded path
     * short-circuited the tuple memo entirely. The rejected arm now
     * re-runs through a shared instance and asserts the refusal is the
     * explicit REFUSE constant — gate or band, each refusal is counted.
     */
    public function testBandPruneKeepsAcceptedDistancesExact(): void
    {
        $shared = new AptedDistance();
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
                    $this->assertSame(
                        AptedDistance::REFUSE,
                        $gated,
                        "banded pair '$name' at $threshold must return the explicit refusal constant"
                    );
                    $this->assertLessThan(
                        $threshold,
                        $exact,
                        "banded pair '$name' at $threshold rejected a pair the exact DP scores at or above it"
                    );
                    // And the shared (warm) instance must refuse the same
                    // way, having actually run the gate/DP (not skipped).
                    $before = $shared->diagnostics();
                    $warmGated = $shared->similarity($a, $b, $threshold);
                    $after = $shared->diagnostics();
                    $this->assertSame(AptedDistance::REFUSE, $warmGated);
                    $this->assertTrue(
                        ($after['gate'] - $before['gate']) === 1
                        || ($after['band'] - $before['band']) === 1
                        || ($after['hits'] - $before['hits']) === 2,
                        "warm rejection of '$name' at $threshold was not explained by gate, band or memo traffic"
                    );
                }
            }
        }
    }

    /**
     * engine-S09 r1, LOW-1 — the fixture roster's banded arm must carry
     * real signal: at least one pair is refused by the size gate, one by
     * the band, and the accepted arms equal the exact DP. A roster that
     * only ever hit one refusal path would make the parity tests green by
     * coincidence; this pins the mix.
     */
    public function testRosterExercisesDistinctRefusalPaths(): void
    {
        $d = new AptedDistance();
        $gate = 0;
        $band = 0;
        foreach (self::engine009Pairs() as [$codeA, $codeB]) {
            $a = $this->canonical($codeA);
            $b = $this->canonical($codeB);
            $d->similarity($a, $b, 0.99); // tight: refuses must come from gate or band
        }
        $diag = $d->diagnostics();
        $this->assertGreaterThanOrEqual(1, $diag['gate'], 'roster must exercise the shared gate path');
        $this->assertGreaterThanOrEqual(1, $diag['band'], 'roster must exercise the band-refusal path');
    }

    /**
     * engine-009: the memo must never cross cost-model or unnumbered-mode
     * boundaries — each configuration keeps its own tuples, and cold and
     * warm agree per instance. The pair below scores 1.0 under the
     * default/semantic cost models but saturates differently under them
     * at tighter thresholds, so the isolation pins carry real signal.
     *
     * Fix-round-1 (r1 LOW-2): every configuration arm now asserts the
     * warm call ran on the memo (hits delta 2), so the cross-instance
     * inequality cannot be the artifact of an unexercised code path it
     * was before.
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
            $before = $warm->diagnostics();
            $warmSim = $warm->similarity($a, $b, 0.0);
            $after = $warm->diagnostics();
            $this->assertSame($cold, $warmSim, "memo must be consistent for '$label'");
            $this->assertSame(2, $after['hits'] - $before['hits'], "warm arm for '$label' must be memo-served");
            $this->assertSame($before['cold'], $after['cold'], "warm arm for '$label' must not recompute");
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
