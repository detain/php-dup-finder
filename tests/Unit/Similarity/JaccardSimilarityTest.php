<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Similarity;

use PHPUnit\Framework\TestCase;
use Phpdup\Similarity\JaccardSimilarity;

final class JaccardSimilarityTest extends TestCase
{
    public function testEqualBagsIsOne(): void
    {
        $j = new JaccardSimilarity();
        $this->assertSame(1.0, $j->similarity(['a' => 1, 'b' => 2], ['a' => 1, 'b' => 2]));
    }

    public function testDisjointBagsIsZero(): void
    {
        $j = new JaccardSimilarity();
        $this->assertSame(0.0, $j->similarity(['a' => 1], ['b' => 1]));
    }

    public function testEmptyInputsAreZero(): void
    {
        $j = new JaccardSimilarity();
        $this->assertSame(0.0, $j->similarity([], ['a' => 1]));
        $this->assertSame(0.0, $j->similarity(['a' => 1], []));
    }

    public function testMultisetIntersectionUsesPerKeyMin(): void
    {
        $j = new JaccardSimilarity();
        // |A∩B|min = min(2,1)+min(0,1) = 1
        // |A∪B|max = max(2,1)+max(0,1) = 3
        $this->assertEqualsWithDelta(1 / 3, $j->similarity(['a' => 2], ['a' => 1, 'b' => 1]), 0.001);
    }
}
