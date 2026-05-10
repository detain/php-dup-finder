<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Fingerprint;

use PHPUnit\Framework\TestCase;
use Phpdup\Fingerprint\MinHashSignature;

final class MinHashSignatureTest extends TestCase
{
    public function testIdenticalBagsHaveJaccardOne(): void
    {
        $bag = array_fill_keys(['a', 'b', 'c', 'd', 'e'], 1);
        $sigA = MinHashSignature::compute($bag);
        $sigB = MinHashSignature::compute($bag);
        $this->assertSame(1.0, MinHashSignature::jaccard($sigA, $sigB));
    }

    public function testDisjointBagsHaveLowJaccard(): void
    {
        $a = MinHashSignature::compute(array_fill_keys(['a', 'b', 'c', 'd', 'e'], 1));
        $b = MinHashSignature::compute(array_fill_keys(['x', 'y', 'z', 'q', 'r'], 1));
        $this->assertLessThan(0.1, MinHashSignature::jaccard($a, $b));
    }

    public function testEstimateClosesActualJaccardWithinTolerance(): void
    {
        // 50% overlap: 5 shared, 5 unique each → true Jaccard = 5/15 ≈ 0.333
        $shared = array_fill_keys(['s1', 's2', 's3', 's4', 's5'], 1);
        $a = MinHashSignature::compute($shared + array_fill_keys(['a1', 'a2', 'a3', 'a4', 'a5'], 1));
        $b = MinHashSignature::compute($shared + array_fill_keys(['b1', 'b2', 'b3', 'b4', 'b5'], 1));
        $est = MinHashSignature::jaccard($a, $b);
        // Bounded error ~0.1 at K=128.
        $this->assertGreaterThan(0.20, $est);
        $this->assertLessThan(0.50, $est);
    }

    public function testEmptyBagYieldsSentinelSignature(): void
    {
        $sig = MinHashSignature::compute([]);
        $this->assertCount(MinHashSignature::SIZE, $sig);
        foreach ($sig as $v) {
            $this->assertSame(PHP_INT_MAX, $v);
        }
    }
}
