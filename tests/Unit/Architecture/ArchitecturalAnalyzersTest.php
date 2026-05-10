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
