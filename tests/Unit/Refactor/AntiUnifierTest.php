<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Refactor;

use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Parsing\AstParser;
use Phpdup\Refactor\AntiUnifier;
use Phpdup\Refactor\ParameterSynthesizer;
use Phpdup\Refactor\PatternRecognizer;
use Phpdup\Refactor\SignatureBuilder;

final class AntiUnifierTest extends TestCase
{
    public function testThresholdAndRoleHolesAreRecovered(): void
    {
        $cluster = $this->clusterFor([
            'function notifyHigh($u, $s) { if ($s > 10) { send("admin", $u); } }',
            'function notifyMid($u, $s) { if ($s > 20) { send("moderator", $u); } }',
        ]);

        (new AntiUnifier())->unify($cluster);
        (new ParameterSynthesizer())->synthesize($cluster);
        (new SignatureBuilder())->buildSignature($cluster);

        $this->assertCount(2, $cluster->holes);

        $intHole = null;
        $stringHole = null;
        foreach ($cluster->holes as $h) {
            $values = array_values(array_unique($h->observedValues));
            sort($values);
            if ($values === ['10', '20']) {
                $intHole = $h;
            }
            if ($values === ["'admin'", "'moderator'"]) {
                $stringHole = $h;
            }
        }
        $this->assertNotNull($intHole, 'expected a hole with observed values 10 and 20');
        $this->assertNotNull($stringHole, 'expected a hole with observed values "admin" and "moderator"');
        $this->assertSame('int',    $intHole->inferredType);
        $this->assertSame('string', $stringHole->inferredType);

        $this->assertNotNull($cluster->signature);
        $this->assertStringContainsString('notifyBy', (string)$cluster->signature);
    }

    public function testThreeMembersAccumulateObservedValues(): void
    {
        $cluster = $this->clusterFor([
            'function notifyHigh($u, $s) { if ($s > 10) { send("admin", $u); } }',
            'function notifyMid($u, $s) { if ($s > 20) { send("moderator", $u); } }',
            'function notifyLow($u, $s) { if ($s > 30) { send("editor", $u); } }',
        ]);

        (new AntiUnifier())->unify($cluster);

        foreach ($cluster->holes as $h) {
            // every hole must have one observed value per member
            $this->assertCount(3, $h->observedValues, "hole {$h->placeholder} ({$h->kind}) has " . count($h->observedValues) . ' observations');
        }
    }

    public function testStrategyPatternIsTagged(): void
    {
        $cluster = $this->clusterFor([
            'function f($x) { return $this->validate($x); }',
            'function f($x) { return $this->compile($x); }',
        ]);
        (new AntiUnifier())->unify($cluster);
        (new ParameterSynthesizer())->synthesize($cluster);
        (new PatternRecognizer())->tag($cluster);

        // exactly one hole — the method name — so strategy tag should fire
        $this->assertContains('strategy', $cluster->patternTags);
    }

    /** @param list<string> $functions */
    private function clusterFor(array $functions): Cluster
    {
        $parser = new AstParser();
        $extractor = new BlockExtractor(minSize: 1);
        $blocks = [];
        foreach ($functions as $i => $code) {
            $stmts = $parser->parseCode('<?php ' . $code);
            $extracted = $extractor->extract("test{$i}.php", $stmts);
            $this->assertNotEmpty($extracted);
            $blocks[] = $extracted[0];
        }
        return new Cluster('TEST', $blocks, 1.0, false);
    }
}
