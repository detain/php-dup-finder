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

    // ---- output-S28: float repr via var_export (int/float typing honesty) ----

    public function testIntegralFloatRowsClassifyFloatNotInt(): void
    {
        // The shapes AntiUnifier::repr() now emits for Float_ nodes that
        // the old (string) cast rendered as bare ints.
        $cluster = $this->clusterWithHoles([new Hole('__P0', 'literal', ['1.0', '2.0'])]);
        (new ParameterSynthesizer())->synthesize($cluster);

        $hole = $cluster->holes[0];
        $this->assertSame('float', $hole->inferredType);
        $this->assertSame('$threshold', $hole->suggestedName);
    }

    public function testIntAndFloatHolesClassifyDistinctly(): void
    {
        // '1' vs '1.0' is the typing-honesty pair: identical VALUE,
        // distinct type. The cascade must not merge them.
        $cluster = $this->clusterWithHoles([
            new Hole('__P0', 'literal', ['1', '2']),
            new Hole('__P1', 'literal', ['1.0', '2.0']),
        ]);
        (new ParameterSynthesizer())->synthesize($cluster);

        $this->assertSame('int',   $cluster->holes[0]->inferredType);
        $this->assertSame('float', $cluster->holes[1]->inferredType);
    }

    public function testExponentFloatHoleTypesFloatNotMixed(): void
    {
        // '1.0E+25' is the acceptance shape: the old cascade matched
        // neither ^-?\d+$ nor ^-?\d+\.\d+$ and fell through to 'mixed'.
        $cluster = $this->clusterWithHoles([new Hole('__P0', 'literal', ['1.0E+25', '2.0E+25'])]);
        (new ParameterSynthesizer())->synthesize($cluster);

        $hole = $cluster->holes[0];
        $this->assertSame('float', $hole->inferredType);
        $this->assertSame('$threshold', $hole->suggestedName);
    }

    public function testExponentSpellingsAcrossFormsAllFloat(): void
    {
        // Lowercase-e and negative-magnitude var_export spellings, plus
        // the legacy (string)-cast shapes a pre-025 cached payload may
        // still carry (long precision=17 mantissa, leading-dot form).
        $cluster = $this->clusterWithHoles([
            new Hole('__P0', 'literal', ['1.0E-5', '2.5E+13']),
            new Hole('__P1', 'literal', ['1.0000000000000001E+25', '1.5e-3']),
            new Hole('__P2', 'literal', ['.5', '-.25']),
        ]);
        (new ParameterSynthesizer())->synthesize($cluster);

        $this->assertSame('float', $cluster->holes[0]->inferredType);
        $this->assertSame('float', $cluster->holes[1]->inferredType);
        $this->assertSame('float', $cluster->holes[2]->inferredType);
    }

    public function testNonFiniteFloatSpellingsTypeFloat(): void
    {
        // var_export(INF) === 'INF', var_export(NAN) === 'NAN'; a 1e309-style
        // literal surfaces in that shape, never as a digit form.
        $cluster = $this->clusterWithHoles([
            new Hole('__P0', 'literal', ['INF', '-INF']),
            new Hole('__P1', 'literal', ['1.0', 'NAN']),
        ]);
        (new ParameterSynthesizer())->synthesize($cluster);

        $this->assertSame('float', $cluster->holes[0]->inferredType);
        $this->assertSame('float', $cluster->holes[1]->inferredType);
    }

    public function testIntegralFloatVsIntRowsProduceHonestUnion(): void
    {
        $cluster = $this->clusterWithHoles([new Hole('__P0', 'subtree', ['1', '1.0'])]);
        (new ParameterSynthesizer())->synthesize($cluster);

        $this->assertSame('int|float', $cluster->holes[0]->inferredType);
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
        // output-S27 canonical form: the verified-absence member row is
        // Hole::MISSING (''), no longer the printable sentinel.
        $cluster = $this->clusterWithHoles([
            new Hole('__O0', 'optional_block', ['some_other_logic($here);', Hole::MISSING]),
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
            new Hole('__O0', 'optional_block', ['if ($x) doExtraWork();', Hole::MISSING]),
        ]);
        (new ParameterSynthesizer())->synthesize($cluster);

        $this->assertSame('$includeDoExtraWork', $cluster->holes[0]->suggestedName);
    }

    public function testOptionalBlockNameFallsBackToOptionalBlockWhenNoIdentifier(): void
    {
        // Segment with no identifier-like token at all (just punctuation/numbers).
        $cluster = $this->clusterWithHoles([
            new Hole('__O0', 'optional_block', [Hole::MISSING, '<missing>']),
        ]);
        (new ParameterSynthesizer())->synthesize($cluster);

        $this->assertSame('$includeOptionalBlock', $cluster->holes[0]->suggestedName);
    }

    public function testOptionalBlockNameConvertsSnakeToCamel(): void
    {
        $cluster = $this->clusterWithHoles([
            new Hole('__O0', 'optional_block', ['some_snake_thing();', Hole::MISSING]),
        ]);
        (new ParameterSynthesizer())->synthesize($cluster);

        $this->assertSame('$includeSomeSnakeThing', $cluster->holes[0]->suggestedName);
    }

    public function testMixedIntsAndStringsProduceUnion(): void
    {
        $cluster = $this->clusterWithHoles([new Hole('__P0', 'literal', ['1', '2', "'admin'"])]);
        (new ParameterSynthesizer())->synthesize($cluster);
        $this->assertSame('int|string', $cluster->holes[0]->inferredType);
    }

    public function testMixedIntFloatBoolProduceStableOrderedUnion(): void
    {
        $cluster = $this->clusterWithHoles([new Hole('__P0', 'literal', ['1', '2.5', 'true'])]);
        (new ParameterSynthesizer())->synthesize($cluster);
        // Stable order: int before float before bool
        $this->assertSame('int|float|bool', $cluster->holes[0]->inferredType);
    }

    public function testUnclassifiableValueFallsBackToMixed(): void
    {
        $cluster = $this->clusterWithHoles([new Hole('__P0', 'subtree', ['$some->call()', '$other'])]);
        (new ParameterSynthesizer())->synthesize($cluster);
        $this->assertSame('mixed', $cluster->holes[0]->inferredType);
    }

    public function testNullAndStringValuesProduceNullableUnion(): void
    {
        $cluster = $this->clusterWithHoles([new Hole('__P0', 'literal', ["'foo'", "'bar'", 'null'])]);
        (new ParameterSynthesizer())->synthesize($cluster);
        $this->assertSame('string|null', $cluster->holes[0]->inferredType);
    }

    /** @param list<Hole> $holes */
    private function clusterWithHoles(array $holes): Cluster
    {
        $cluster = new Cluster('TEST', [], 1.0, false);
        $cluster->holes = $holes;
        return $cluster;
    }
}
