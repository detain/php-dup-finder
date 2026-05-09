<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Refactor;

use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Refactor\Hole;
use Phpdup\Refactor\ParameterSynthesizer;

final class ParameterSynthesizerTest extends TestCase
{
    public function testIntegerLiteralsBecomeIntThreshold(): void
    {
        $cluster = $this->clusterWithHoles([new Hole('__P0', 'literal', ['10', '20', '30'])]);
        (new ParameterSynthesizer())->synthesize($cluster);

        $hole = $cluster->holes[0];
        $this->assertSame('int', $hole->inferredType);
        $this->assertSame('$threshold', $hole->suggestedName);
    }

    public function testStringLiteralsBecomeStringValue(): void
    {
        $cluster = $this->clusterWithHoles([new Hole('__P0', 'literal', ["'admin'", "'moderator'", "'editor'"])]);
        (new ParameterSynthesizer())->synthesize($cluster);

        $this->assertSame('string', $cluster->holes[0]->inferredType);
        $this->assertSame('$value', $cluster->holes[0]->suggestedName);
    }

    public function testFloatLiteralsBecomeFloatThreshold(): void
    {
        $cluster = $this->clusterWithHoles([new Hole('__P0', 'literal', ['1.5', '2.5'])]);
        (new ParameterSynthesizer())->synthesize($cluster);

        $this->assertSame('float', $cluster->holes[0]->inferredType);
        $this->assertSame('$threshold', $cluster->holes[0]->suggestedName);
    }

    public function testBoolLiteralsBecomeBool(): void
    {
        $cluster = $this->clusterWithHoles([new Hole('__P0', 'literal', ['true', 'false'])]);
        (new ParameterSynthesizer())->synthesize($cluster);

        $this->assertSame('bool', $cluster->holes[0]->inferredType);
    }

    public function testClassLikeIdentifiersBecomeClassString(): void
    {
        $cluster = $this->clusterWithHoles([new Hole('__P0', 'name', ['User', 'Order', 'Product'])]);
        (new ParameterSynthesizer())->synthesize($cluster);

        $this->assertSame('class-string', $cluster->holes[0]->inferredType);
    }

    public function testCallableLikeNamesGetCallableType(): void
    {
        $cluster = $this->clusterWithHoles([new Hole('__P0', 'name', ['validate', 'compile', 'render'])]);
        (new ParameterSynthesizer())->synthesize($cluster);

        $this->assertSame('callable|string', $cluster->holes[0]->inferredType);
    }

    public function testNameCollisionsResolvedWithNumericSuffix(): void
    {
        $cluster = $this->clusterWithHoles([
            new Hole('__P0', 'literal', ['1', '2']),
            new Hole('__P1', 'literal', ['3', '4']),
            new Hole('__P2', 'literal', ['5', '6']),
        ]);
        (new ParameterSynthesizer())->synthesize($cluster);

        $names = array_map(static fn(Hole $h) => $h->suggestedName, $cluster->holes);
        $this->assertSame(['$threshold', '$threshold1', '$threshold2'], $names);
    }

    public function testOptionalBlockGetsBoolTypeAndIncludeName(): void
    {
        $cluster = $this->clusterWithHoles([
            new Hole('__O0', 'optional_block', ['some_other_logic($here);', '<absent>']),
        ]);
        (new ParameterSynthesizer())->synthesize($cluster);

        $hole = $cluster->holes[0];
        $this->assertSame('bool', $hole->inferredType);
        $this->assertSame('$includeSomeOtherLogic', $hole->suggestedName);
    }

    public function testOptionalBlockNameSkipsStopWords(): void
    {
        // First identifier in the segment is "if" — a stop-word. Synthesizer
        // should reach for the next identifier ("doExtraWork") instead.
        $cluster = $this->clusterWithHoles([
            new Hole('__O0', 'optional_block', ['if ($x) doExtraWork();', '<absent>']),
        ]);
        (new ParameterSynthesizer())->synthesize($cluster);

        $this->assertSame('$includeDoExtraWork', $cluster->holes[0]->suggestedName);
    }

    public function testOptionalBlockNameFallsBackToOptionalBlockWhenNoIdentifier(): void
    {
        // Segment with no identifier-like token at all (just punctuation/numbers).
        $cluster = $this->clusterWithHoles([
            new Hole('__O0', 'optional_block', ['<absent>', '<missing>']),
        ]);
        (new ParameterSynthesizer())->synthesize($cluster);

        $this->assertSame('$includeOptionalBlock', $cluster->holes[0]->suggestedName);
    }

    public function testOptionalBlockNameConvertsSnakeToCamel(): void
    {
        $cluster = $this->clusterWithHoles([
            new Hole('__O0', 'optional_block', ['some_snake_thing();', '<absent>']),
        ]);
        (new ParameterSynthesizer())->synthesize($cluster);

        $this->assertSame('$includeSomeSnakeThing', $cluster->holes[0]->suggestedName);
    }

    /** @param list<Hole> $holes */
    private function clusterWithHoles(array $holes): Cluster
    {
        $cluster = new Cluster('TEST', [], 1.0, false);
        $cluster->holes = $holes;
        return $cluster;
    }
}
