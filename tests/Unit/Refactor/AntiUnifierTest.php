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

    public function testStmtArraysOfDifferentLengthsProduceOptionalBlockHoles(): void
    {
        // Same skeleton, but the second function is missing the last two
        // statements. With type-3 detection on, AntiUnifier should emit
        // optional_block holes for the trailing pair.
        $cluster = $this->clusterFor([
            'function long()  { a(); b(); c(); d(); e(); }',
            'function short() { a(); b(); c(); }',
        ]);

        (new AntiUnifier(optionalBlocksEnabled: true))->unify($cluster);

        $optionals = array_values(array_filter(
            $cluster->holes,
            static fn($h) => $h->kind === 'optional_block',
        ));
        $this->assertCount(2, $optionals, 'two trailing statements should each become optional_block holes');

        // Each optional hole should have exactly one <absent> per member that
        // lacked the segment, plus the seed's repr for the member that had it.
        foreach ($optionals as $hole) {
            $absent = array_filter($hole->observedValues, static fn($v) => $v === '<absent>');
            $this->assertCount(1, $absent, "{$hole->placeholder}: exactly one member should be <absent>");
            $this->assertCount(2, $hole->observedValues, 'one observed value per cluster member');
        }
    }

    public function testOptionalBlocksDisabledFallsBackToWholeArrayHole(): void
    {
        // Same fixture as the previous test, but with the feature off.
        // The legacy code path should kick in: differing array lengths →
        // a single subtree hole rather than per-statement optional holes.
        $cluster = $this->clusterFor([
            'function long()  { a(); b(); c(); d(); e(); }',
            'function short() { a(); b(); c(); }',
        ]);

        (new AntiUnifier(optionalBlocksEnabled: false))->unify($cluster);

        foreach ($cluster->holes as $h) {
            $this->assertNotSame('optional_block', $h->kind, 'no optional_block holes when feature is disabled');
        }
        $this->assertNotEmpty($cluster->holes, 'legacy whole-array subtree hole should still form');
    }

    public function testOptionalBlocksMaxPerClusterCapsSegments(): void
    {
        // Five trailing-statement gaps would exceed the cap of 2; the unifier
        // should fall back to a whole-array hole instead.
        $cluster = $this->clusterFor([
            'function long()  { a(); b(); c(); d(); e(); f(); g(); }',
            'function short() { a(); b(); }',
        ]);

        (new AntiUnifier(optionalBlocksEnabled: true, optionalBlocksMaxPerCluster: 2))->unify($cluster);

        $optionals = array_filter(
            $cluster->holes,
            static fn($h) => $h->kind === 'optional_block',
        );
        $this->assertCount(0, $optionals, 'cap exceeded — should fall back to a whole-array hole');
    }

    public function testSeedIsTheLargestMember(): void
    {
        // Place the longer block second; AntiUnifier must still pick it as
        // the seed so the optional_block holes form for the missing tail
        // (instead of the alignment failing because the seed is the shorter
        // member). After unification, observed values are remapped to the
        // original cluster.members order, so member[0] is the short one.
        $cluster = $this->clusterFor([
            'function short() { a(); b(); c(); }',
            'function long()  { a(); b(); c(); d(); e(); }',
        ]);

        (new AntiUnifier(optionalBlocksEnabled: true))->unify($cluster);

        $optionals = array_values(array_filter(
            $cluster->holes,
            static fn($h) => $h->kind === 'optional_block',
        ));
        $this->assertCount(2, $optionals);

        // Member 0 (the short function) was absent; member 1 (the long one)
        // had each segment.
        foreach ($optionals as $hole) {
            $this->assertSame('<absent>', $hole->observedValues[0], 'short member is index 0; should be <absent>');
            $this->assertNotSame('<absent>', $hole->observedValues[1], 'long member is index 1; should have the stmt');
        }
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
