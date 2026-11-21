<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Refactor;

use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Parsing\AstParser;
use Phpdup\Refactor\AntiUnifier;
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
