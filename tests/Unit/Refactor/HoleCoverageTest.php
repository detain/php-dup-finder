<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Refactor;

use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Parsing\AstParser;
use Phpdup\Refactor\AntiUnifier;
use Phpdup\Refactor\Hole;
use Phpdup\Refactor\HoleCoverage;

/**
 * Item 5.4(c): the coverage measurement + fragment span + canonical
 * template resolution feeding the re-expression posture.
 */
final class HoleCoverageTest extends TestCase
{
    public function testCoverageIsZeroWithoutHoles(): void
    {
        $cluster = $this->clusterFor([
            'function a() { x(); y(); }',
            'function a() { x(); y(); }',
        ]);
        (new AntiUnifier())->unify($cluster);

        $this->assertSame([], $cluster->holes);
        $this->assertEqualsWithDelta(0.0, HoleCoverage::coverage($cluster), 0.0001);
    }

    public function testCoverageIsOneForWholeTemplateHole(): void
    {
        // Two structurally different bodies (fallback array hole) leave the
        // divergence... use directly-conflicting statement classes instead:
        // `echo 1;` vs `x();` at stmts/0 → class mismatch → subtree hole
        // over that stmt only, so coverage < 1. The clean whole-template
        // hole is a single-member-free trick: hand-set it.
        $cluster         = $this->clusterFor([
            'function a() { x(); y(); }',
            'function a() { x(); y(); }',
        ]);
        (new AntiUnifier())->unify($cluster);
        $cluster->holePaths = ['__P9' => []];

        $this->assertEqualsWithDelta(1.0, HoleCoverage::coverage($cluster), 0.0001);
        $this->assertNull(HoleCoverage::fragmentSpan($cluster));
    }

    public function testPartialHoleCoverageSitsBetween(): void
    {
        // Same skeleton, one literal hole deep inside stmt 0's condition of a
        // 3-statement body → strictly between 0 and 1.
        $cluster = $this->clusterFor([
            'function a($s) { if ($s > 1) { x(); } y(); z(); }',
            'function a($s) { if ($s > 2) { x(); } y(); z(); }',
        ]);
        (new AntiUnifier())->unify($cluster);

        $this->assertNotEmpty($cluster->holes);
        $coverage = HoleCoverage::coverage($cluster);
        $this->assertNotNull($coverage);
        $this->assertGreaterThan(0.0, $coverage);
        $this->assertLessThan(0.5, $coverage, 'one literal deep in stmts/0 is a small fraction of the body');
    }

    public function testFragmentSpanSkipsHoleTouchedStatements(): void
    {
        // Hole lives inside the FIRST top-level statement; the run of
        // untouched statements is the tail pair.
        $cluster = $this->clusterFor([
            'function a($s) { if ($s > 1) { x(); } y(); z(); }',
            'function a($s) { if ($s > 2) { x(); } y(); z(); }',
        ]);
        (new AntiUnifier())->unify($cluster);

        $span = HoleCoverage::fragmentSpan($cluster);
        $this->assertNotNull($span);
        $this->assertSame(1, $span['start'], 'stmts/0 is hole-touched; the run starts at index 1');
        $this->assertSame(2, $span['length'], 'y(); z(); remain hole-free');
    }

    public function testCoverageNullWithoutGeneralizedAst(): void
    {
        $cluster = new Cluster('NOAST', [], 1.0, false);
        $this->assertNull(HoleCoverage::coverage($cluster));
        $this->assertNull(HoleCoverage::fragmentSpan($cluster));
        $this->assertNull(HoleCoverage::canonicalTemplate($cluster));
    }

    public function testCanonicalTemplatePrefersTheMedoid(): void
    {
        $members = $this->blocks([
            'function a() { x(); }',
            'function b() { y(); }',
        ]);
        $cluster                 = new Cluster('MED', $members, 1.0, false);
        $cluster->medoidId       = $members[1]->id;

        $this->assertSame($members[1], HoleCoverage::canonicalTemplate($cluster));

        // Unrecorded medoid (residue clusters) → historical members[0].
        $cluster->medoidId = null;
        $this->assertSame($members[0], HoleCoverage::canonicalTemplate($cluster));

        // Dangling medoid id (member not in this cluster) → members[0], never null.
        $cluster->medoidId = 'ghost-id';
        $this->assertSame($members[0], HoleCoverage::canonicalTemplate($cluster));
    }

    public function testRootStmtsRoundTrip(): void
    {
        $cluster = $this->clusterFor(['function a() { x(); y(); }', 'function a() { x(); y(); }']);
        (new AntiUnifier())->unify($cluster);

        $this->assertCount(2, HoleCoverage::rootStmts($cluster));
        // Non-stmt root (the parsed Function_ IS a stmt with stmts; an Expr
        // root would yield []) — exercise the empty path:
        $cluster->generalizedAst = null;
        $this->assertSame([], HoleCoverage::rootStmts($cluster));
    }

    // ── output-S26 (php-dup-finder-output-018): degraded surface ─────────────

    public function testDegradedSurfaceReportsNothingOnCleanEvidence(): void
    {
        $cluster = $this->clusterFor([
            'function a() { x(); y(); }',
            'function a() { x(); y(); }',
        ]);
        (new AntiUnifier())->unify($cluster);

        $cluster->holes = [
            new Hole('__A', 'literal', ['1', '2'], false),
        ];

        $this->assertFalse(HoleCoverage::isDegraded($cluster));
        $this->assertSame([], HoleCoverage::degradedHoles($cluster));
    }

    public function testDegradedSurfaceListsExactlyTheDegradedHoles(): void
    {
        $cluster = $this->clusterFor([
            'function a() { x(); y(); }',
            'function a() { x(); y(); }',
        ]);

        $clean  = new Hole('__A', 'literal', ['1', '2'], false);
        $broken = new Hole('__B', 'subtree', ['m();', ''], true);
        $cluster->holes = [$clean, $broken];

        $this->assertTrue(HoleCoverage::isDegraded($cluster));
        // Object identity: reporters get the very holes to annotate, not copies.
        $this->assertSame([$broken], HoleCoverage::degradedHoles($cluster));
    }

    public function testDegradedSurfaceFromRealUnificationOfMisrecordClass(): void
    {
        // End-to-end: the collision shape from output-018 (a value hole at
        // stmts/1 plus a short member whose slot content is unrecoverable)
        // must be discoverable through this surface alone.
        $cluster = $this->clusterFor([
            'function h($x) { k(); m(); }',
            'function h($x) { k(); return 5; }',
            'function h($x) { k(); q(); }',
            'function h($x) { k(); }',
        ]);
        (new AntiUnifier())->unify($cluster);

        $this->assertTrue(HoleCoverage::isDegraded($cluster));
        $degraded = HoleCoverage::degradedHoles($cluster);
        $this->assertCount(1, $degraded);
        $this->assertSame('__P0', $degraded[0]->placeholder);
        $this->assertSame(['m();', 'return 5;', 'q();', ''], $degraded[0]->observedValues);
    }

    /** @param list<string> $functions */
    private function clusterFor(array $functions): Cluster
    {
        return new Cluster('TEST', $this->blocks($functions), 1.0, false);
    }

    /** @param list<string> $functions @return list<Block> */
    private function blocks(array $functions): array
    {
        $parser    = new AstParser();
        $extractor = new BlockExtractor(minSize: 1);
        $blocks    = [];
        foreach ($functions as $i => $code) {
            $stmts     = $parser->parseCode('<?php ' . $code);
            $extracted = $extractor->extract("test{$i}.php", $stmts);
            $this->assertNotEmpty($extracted);
            // Block ids are assigned by PreprocessStage in the real pipeline;
            // stamp deterministic stand-ins so id-keyed logic is testable.
            $extracted[0]->id = 'BLK' . $i;
            $blocks[] = $extracted[0];
        }
        return $blocks;
    }
}
