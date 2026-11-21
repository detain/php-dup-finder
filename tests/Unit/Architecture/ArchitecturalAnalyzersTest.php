<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use Phpdup\Architecture\Analyzers\AntiPatternAnalyzer;
use Phpdup\Architecture\Analyzers\DesignPatternAnalyzer;
use Phpdup\Architecture\Analyzers\SolidAnalyzer;
use Phpdup\Architecture\Finding;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Refactor\Hole;
use Phpdup\Refactor\PatternRecognizer;
use Phpdup\Util\LineRange;

final class ArchitecturalAnalyzersTest extends TestCase
{
    public function testAntiPatternFlagsLongParameterList(): void
    {
        $cluster = $this->cluster();
        for ($i = 0; $i < 7; $i++) {
            $cluster->holes[] = new Hole("__P{$i}", 'literal', ['1']);
        }
        $findings = (new AntiPatternAnalyzer())->analyze($cluster);
        $codes = array_column($findings, 'code');
        $this->assertContains('long-parameter-list', $codes);
    }

    public function testAntiPatternFlagsPrimitiveObsession(): void
    {
        $cluster = $this->cluster();
        $cluster->holes = [
            $this->intHole('a'), $this->intHole('b'), $this->intHole('c'),
        ];
        $findings = (new AntiPatternAnalyzer())->analyze($cluster);
        $codes = array_column($findings, 'code');
        $this->assertContains('primitive-obsession', $codes);
    }

    public function testDesignPatternRecognisesStrategy(): void
    {
        $cluster = $this->cluster();
        $cluster->patternTags = ['strategy'];
        $findings = (new DesignPatternAnalyzer())->analyze($cluster);
        $codes = array_column($findings, 'code');
        $this->assertContains('pattern-strategy', $codes);
    }

    public function testDesignPatternRecognisesFactoryFromHoles(): void
    {
        $cluster = $this->cluster();
        $h = new Hole('__P0', 'name', ['Foo', 'Bar']);
        $h->inferredType = 'class-string';
        $cluster->holes = [$h];
        $findings = (new DesignPatternAnalyzer())->analyze($cluster);
        $this->assertContains('pattern-factory', array_column($findings, 'code'));
    }

    public function testSolidFlagsConcreteClassStringHole(): void
    {
        $cluster = $this->cluster();
        $h = new Hole('__P0', 'name', ['UserService']);
        $h->inferredType = 'class-string';
        $cluster->holes = [$h];
        $findings = (new SolidAnalyzer())->analyze($cluster);
        $codes = array_column($findings, 'code');
        $this->assertContains('dip-concrete-strategy', $codes);
    }

    public function testFindingExposesSeverityAndSuggestion(): void
    {
        $f = new Finding('A', 'c', 'msg', Finding::SEVERITY_WARNING, 'try X');
        $this->assertSame(Finding::SEVERITY_WARNING, $f->severity);
        $this->assertSame('try X', $f->suggestion);
    }

    // ---------------------------------------------------------------
    // output-042: shared single-pass MemberFacts collector
    // ---------------------------------------------------------------

    public function testCollectFactsGathersEveryDetectorFamilyInOneWalk(): void
    {
        $code = '<?php function f($db, $s, $xs, $acc, $z) {
            if ($s) { return 1; }
            if ($s) { throw new \RuntimeException("no"); }
            foreach ($xs as $x) { $acc[] = $x; }
            switch ($s) { case "a": p(); break; case "b": p(); break; }
            $m = match ($s) { "c" => 1, default => 2 };
            try { g(); } catch (\RuntimeException $e) { return null; }
            $db->prepare("SELECT a FROM t");
            curl_init("https://example.com");
            \DB::table("users");
            return $q->one(1)->two(2)->three(3) . $z;
        }';
        $stmts = (new \Phpdup\Parsing\AstParser())->parseCode($code);
        $facts = PatternRecognizer::collectFacts($stmts[0]);

        $this->assertCount(2, $facts->ifs);
        $this->assertCount(1, $facts->switches);
        $this->assertCount(1, $facts->matches);
        $this->assertCount(1, $facts->foreaches);
        $this->assertCount(1, $facts->tries);
        $this->assertCount(4, $facts->methodCalls);
        $this->assertCount(4, $facts->funcCalls);
        $this->assertCount(1, $facts->staticCalls);
        $this->assertTrue($facts->hasConcat);
        $this->assertFalse($facts->hasDbOpAttribute);

        // String_ literals only, document order — interpolation parts are
        // Node\InterpolatedStringPart (not String_) and numbers are Int_,
        // matching the old findInstanceOf(String_::class) roster exactly.
        $this->assertSame(
            ['no', 'a', 'b', 'c', 'SELECT a FROM t', 'https://example.com', 'users'],
            $facts->strings,
        );

        // Pre-order chain walk visits the OUTER call first: a->b()->c()
        // yields c, then b — the same order NodeFinder yielded, which is
        // what the SRP persist/effect scan iterates.
        $this->assertSame(['prepare', 'three', 'two', 'one'], $facts->methodNames());
    }

    public function testCollectFactsFlagsDbOpAttributeWithoutAnyCall(): void
    {
        // isDbOp's second arm used to be a full second traversal per
        // member; the collector now carries one boolean.
        $stmts = (new \Phpdup\Parsing\AstParser())->parseCode('<?php function f() { $x = 1; }');
        $stmts[0]->stmts[0]->setAttribute('phpdup.dbOp', 'db.read');
        $facts = PatternRecognizer::collectFacts($stmts[0]);

        $this->assertTrue($facts->hasDbOpAttribute);
        $this->assertSame([], $facts->funcCalls);
        $this->assertSame([], $facts->strings);
    }

    public function testSolidAnalyzerSrpFiresthroughSharedCollector(): void
    {
        $mixed  = $this->clusterFromCode('<?php function h($db, $mail) { $db->query("x"); $mail->send("y"); }');
        $codes  = array_column((new SolidAnalyzer())->analyze($mixed), 'code');
        $this->assertContains('srp-mixed-concerns', $codes);

        // Single-concern body must stay silent — and persistence-only
        // names must not leak into the side-effect bucket.
        $pure   = $this->clusterFromCode('<?php function h($db) { $db->query("x"); $db->insert("y"); }');
        $codes2 = array_column((new SolidAnalyzer())->analyze($pure), 'code');
        $this->assertNotContains('srp-mixed-concerns', $codes2);
    }

    private function clusterFromCode(string $code): Cluster
    {
        $stmts = (new \Phpdup\Parsing\AstParser())->parseCode($code);
        $b = new Block(
            file: 'x.php', range: new LineRange(1, 1), kind: 'method',
            namespace: null, class: null, name: null, ast: $stmts[0],
        );
        return new Cluster('TEST', [$b], 1.0, false);
    }

    private function cluster(): Cluster
    {
        $stmts = (new \Phpdup\Parsing\AstParser())->parseCode('<?php $x;');
        $b = new Block(
            file: 'x.php', range: new LineRange(1, 1), kind: 'method',
            namespace: null, class: null, name: null, ast: $stmts[0],
        );
        return new Cluster('TEST', [$b, $b], 1.0, false);
    }

    private function intHole(string $name): Hole
    {
        $h = new Hole("__P_{$name}", 'literal', ['1', '2']);
        $h->inferredType = 'int';
        return $h;
    }
}
