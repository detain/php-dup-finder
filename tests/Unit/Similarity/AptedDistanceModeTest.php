<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Similarity;

use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstParser;
use Phpdup\Similarity\AptedDistance;
use Phpdup\Similarity\TreeEditDistance;

/**
 * Item 1.1 (§5a) — TED-label mode pins.
 *
 * The label side of the lockstep: un-numbered labels make the
 * renumber-cascade invisible (the §3.3 "TED 0.923→1.000" class) and
 * can only ever raise similarity, since coarser label equality never
 * adds edit cost. Default must stay byte-identical.
 */
final class AptedDistanceModeTest extends TestCase
{
    /** @return list<\PhpParser\Node> */
    private static function blocksFrom(string $code): array
    {
        $stmts = (new AstParser())->parseCode($code);
        self::assertNotNull($stmts);
        $blocks = (new BlockExtractor(minSize: 1))->extract('t.php', $stmts);
        foreach ($blocks as $b) {
            (new Normalizer('aggressive'))->normalize($b);
        }
        return array_map(static fn($b) => $b->canonical, $blocks);
    }

    public function testParamSlotFlipReachesOneOnlyUnnumbered(): void
    {
        // Measured (diag2.php): numbered TED = 0.7778 (every __V slot
        // misaligned), un-numbered TED = 1.0000 (identical streams).
        [$a] = self::blocksFrom('<?php function s($log, $r) { $log->go($r); return $log->back($r); }');
        [$b] = self::blocksFrom('<?php function s($r, $log) { $log->go($r); return $log->back($r); }');

        $numbered   = (new TreeEditDistance())->similarity($a, $b);
        $unnumbered = (new TreeEditDistance(null, true))->similarity($a, $b);

        $this->assertLessThan(1.0, $numbered,
            'numbered labels must charge substitution cost when slots re-deal (cascade)');
        $this->assertEqualsWithDelta(1.0, $unnumbered, 1e-9,
            'un-numbered labels must score a slot-flip pair as identical (doc §3.3 class)');
    }

    public function testGuardDriftIsNumberingInvariantAfterNameStamps(): void
    {
        // The §3.3 row-2 shape. Rewritten by engine-S02a: the strict
        // numbered→un-numbered improvement this fixture used to show
        // (.5714 → .6429) came from first-occurrence ordinals on call
        // names — the head guard shifted every later __CALL slot in
        // the numbered labels only. Name-stamped calls fold to family
        // tokens in BOTH modes, so the pair is now numbering-invariant
        // at the un-numbered value (.6428…): coarsening no longer
        // changes it, and the score stays honestly < 1 because the
        // trees genuinely differ (statement counts). The strict
        // improvement class survives on variable-slot misalignment,
        // pinned above by testParamSlotFlipReachesOneOnlyUnnumbered.
        [$a] = self::blocksFrom('<?php function s($log, $r) { $r->go($log); return $r->back($log); }');
        [$b] = self::blocksFrom('<?php function s($log, $r) { $r->warn(); if (!$log) { return false; } $r->go($log); return $r->back($log); }');

        $numbered   = (new TreeEditDistance())->similarity($a, $b);
        $unnumbered = (new TreeEditDistance(null, true))->similarity($a, $b);

        $this->assertSame($numbered, $unnumbered,
            'post-S02a the guard-drift class carries no numbered divergence (stamps fold in both modes)');
        $this->assertLessThan(1.0, $numbered, 'and honest fixtures stay <1 when shapes genuinely differ');
    }

    public function testMonotoneOverFixtureBlocks(): void
    {
        // Every fixture-block pair: TED_u >= TED_n. This is the property
        // behind "every transition is a strict improvement" — a label
        // alphabet coarsening can only lower edit cost, never raise it.
        $roots = ['notify', 'sql', 'optional', 'exact'];
        $count = 0;
        foreach ($roots as $root) {
            $dir = realpath(__DIR__ . '/../../Fixtures/' . $root);
            if ($dir === false) {
                continue;
            }
            $nodes = [];
            foreach (glob($dir . '/*.php') ?: [] as $file) {
                $stmts = (new AstParser())->parseFile($file);
                if ($stmts === null) {
                    continue;
                }
                foreach ((new BlockExtractor(minSize: 5))->extract($file, $stmts) as $blk) {
                    (new Normalizer('aggressive'))->normalize($blk);
                    $nodes[] = $blk->canonical;
                    if (count($nodes) > 12) {
                        break;
                    }
                }
            }
            $numberedTree   = new TreeEditDistance();
            $unnumberedTree = new TreeEditDistance(null, true);
            $n = count($nodes);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $sn = $numberedTree->similarity($nodes[$i], $nodes[$j]);
                    $su = $unnumberedTree->similarity($nodes[$i], $nodes[$j]);
                    $this->assertGreaterThanOrEqual($sn - 1e-9, $su,
                        "TED must be monotone >= under un-numbering (corpus $root pair $i/$j)");
                    $count++;
                }
            }
        }
        $this->assertGreaterThan(50, $count, 'the monotonicity sweep must actually have compared pairs');
    }

    public function testDefaultConstructorMatchesLegacyAptedDirectly(): void
    {
        [$a] = self::blocksFrom('<?php function f($x) { $x->go(); return $x->id; }');
        [$b] = self::blocksFrom('<?php function g($y) { $y->go(); return $y->id; }');
        $legacy = new AptedDistance();
        $explicitOff = new AptedDistance(null, false);
        $this->assertSame(
            $legacy->similarity($a, $b),
            $explicitOff->similarity($a, $b),
            'explicit numbered must equal the historical default byte-for-byte',
        );
    }

    public function testModeIsInvisibleToIdenticalTrees(): void
    {
        // Coarsening cannot split what was already identical: a pair that
        // scores 1.0 numbered still scores 1.0 un-numbered.
        [$a] = self::blocksFrom('<?php function m() { return 42; }');
        $this->assertEqualsWithDelta(1.0, (new TreeEditDistance())->similarity($a, $a), 1e-9);
        $this->assertEqualsWithDelta(1.0, (new TreeEditDistance(null, true))->similarity($a, $a), 1e-9);
    }

    public function testStatementOrderIsInvisibleToTheNumberingKnob(): void
    {
        // engine-007 (S08a): sibling ordering is orthogonal to the
        // __V-numbering knob — an order-permuted body is a real edit in
        // BOTH modes. Before flatten() kept physical order, this pair
        // collapsed to 1.0 in both modes; now both modes charge it. The
        // numbering strip only coarsens labels, it never restores a
        // permutation, so numbered and un-numbered must agree that the
        // permuted pair is below one (and an identical body stays one).
        [$a] = self::blocksFrom('<?php function f() { $u = 1; if ($u) { g(); } }');
        [$b] = self::blocksFrom('<?php function f() { if ($u) { g(); } $u = 1; }');

        $numbered   = (new TreeEditDistance())->similarity($a, $b);
        $unnumbered = (new TreeEditDistance(null, true))->similarity($a, $b);

        $this->assertLessThan(1.0, $numbered,
            'numbered mode must charge a statement permutation');
        $this->assertLessThan(1.0, $unnumbered,
            'un-numbered mode must charge a statement permutation too (order ⊥ numbering)');
        $this->assertEqualsWithDelta(1.0, (new TreeEditDistance(null, true))->similarity($a, $a), 1e-9,
            'and an unchanged body stays identical in un-numbered mode');
    }
}
