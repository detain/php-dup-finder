<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Similarity;

use PHPUnit\Framework\TestCase;
use Phpdup\Similarity\ContainmentSimilarity;

final class ContainmentSimilarityTest extends TestCase
{
    public function testIdenticalBagsScoreOne(): void
    {
        $sim = new ContainmentSimilarity();
        $this->assertSame(1.0, $sim->similarity(['a' => 2, 'b' => 1], ['a' => 2, 'b' => 1]));
    }

    public function testEmptyBagsScoreZero(): void
    {
        $sim = new ContainmentSimilarity();
        $this->assertSame(0.0, $sim->similarity([], ['a' => 1]));
        $this->assertSame(0.0, $sim->similarity(['a' => 1], []));
    }

    public function testSubsetScoresOne(): void
    {
        // B is contained in A — every gram of B is in A. Score should be 1.0
        // even though Jaccard would be ~0.5.
        $sim = new ContainmentSimilarity();
        $a = ['a' => 1, 'b' => 1, 'c' => 1, 'd' => 1, 'e' => 1];
        $b = ['a' => 1, 'b' => 1, 'c' => 1];
        $this->assertSame(1.0, $sim->similarity($a, $b));
    }

    public function testPartialOverlapBetweenZeroAndOne(): void
    {
        $sim = new ContainmentSimilarity();
        // |A∩B|min = 1 (only 'a'), smaller = 2 (sum of B), so 0.5.
        $a = ['a' => 1, 'b' => 1, 'c' => 1];
        $b = ['a' => 1, 'd' => 1];
        $this->assertEqualsWithDelta(0.5, $sim->similarity($a, $b), 0.0001);
    }

    public function testMultisetCountsRespected(): void
    {
        $sim = new ContainmentSimilarity();
        // A has two 'a's; B has one 'a' (and one 'b'). Intersection min = 1.
        // smaller (B) sum = 2. So 0.5.
        $a = ['a' => 2];
        $b = ['a' => 1, 'b' => 1];
        $this->assertEqualsWithDelta(0.5, $sim->similarity($a, $b), 0.0001);
    }

    public function testSizeRatioReportsRelativeMass(): void
    {
        $sim = new ContainmentSimilarity();
        $this->assertSame(1.0, $sim->sizeRatio(['a' => 1], ['b' => 1]));
        $this->assertEqualsWithDelta(0.5, $sim->sizeRatio(['a' => 1], ['a' => 1, 'b' => 1]), 0.0001);
        $this->assertSame(0.0, $sim->sizeRatio([], ['a' => 1]));
    }
}
