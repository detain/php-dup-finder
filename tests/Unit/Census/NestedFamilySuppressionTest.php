<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Census;

use PHPUnit\Framework\TestCase;
use Phpdup\Census\NestedFamilySuppression;

/**
 * §5c law: THE nested-family rule, implemented once for 3.1/6.3/7.2c.
 * These pins exercise the pure function directly so every consumer
 * lane inherits the same adjudicated truth table.
 */
final class NestedFamilySuppressionTest extends TestCase
{
    public function testEqualSupportParentSuppressesChild(): void
    {
        $keep = NestedFamilySuppression::keepMaximal(
            ['P' => 4, 'C' => 4],
            ['P' => ['C' => 4]]
        );
        self::assertSame(['P'], $keep);
    }

    public function testStrongerChildEscapesSuppression(): void
    {
        $keep = NestedFamilySuppression::keepMaximal(
            ['P' => 3, 'C' => 7],
            ['P' => ['C' => 3]]
        );
        self::assertSame(['C', 'P'], $keep, 'extra standalone sightings of C are real idiom use');
    }

    public function testMaxParentWinsNotLastSeen(): void
    {
        $keep = NestedFamilySuppression::keepMaximal(
            ['Big' => 9, 'Small' => 2, 'C' => 5],
            ['Small' => ['C' => 2], 'Big' => ['C' => 3]]
        );
        self::assertNotContains('C', $keep, 'Big(9) >= C(5) suppresses regardless of edge order');
    }

    public function testUnlistedParentIsInert(): void
    {
        $keep = NestedFamilySuppression::keepMaximal(
            ['C' => 1, 'Other' => 99],
            ['Ghost' => ['C' => 50]]
        );
        self::assertSame(['C', 'Other'], $keep);
    }

    public function testSelfEdgeWitnessesNothing(): void
    {
        $keep = NestedFamilySuppression::keepMaximal(['K' => 3], ['K' => ['K' => 1]]);
        self::assertSame(['K'], $keep);
    }

    public function testChainsFallInOneShotWithoutRecursion(): void
    {
        // G(10) ⊃ P(6) ⊃ C(3): P suppressed by G; C suppressed by its
        // ORIGINAL parent support — no iterative re-evaluation, no
        // resurrection after P drops (the occurrences nested inside P
        // are nested inside G too).
        $keep = NestedFamilySuppression::keepMaximal(
            ['G' => 10, 'P' => 6, 'C' => 3],
            ['G' => ['P' => 6], 'P' => ['C' => 3]]
        );
        self::assertSame(['G'], $keep);
    }

    public function testEmptyInputsKeepNothingAndEverything(): void
    {
        self::assertSame([], NestedFamilySuppression::keepMaximal([], []));
        self::assertSame(['a', 'b'], NestedFamilySuppression::keepMaximal(['b' => 1, 'a' => 2], []), 'sorted output');
    }
}
