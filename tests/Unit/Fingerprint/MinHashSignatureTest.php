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

    /**
     * Pin the documented integer-mix idiom (engine-S31a ledger row 8):
     * two base hashes per element, row i = (h1 + i*h2) mod 2^32.
     * Reverting to per-row string reseeding (the 128x cost trap) or
     * changing either base-hash salt fails this pin.
     */
    public function testRowValuesFollowDocumentedIntMixIdiom(): void
    {
        foreach (['notify', 'a', 'updateOrInsert($x, $y)', 'x'] as $element) {
            $h1 = crc32($element) & 0xFFFFFFFF;
            $h2 = crc32($element . MinHashSignature::HASH_SALT) & 0xFFFFFFFF;
            if ($h2 === 0) {
                $h2 = MinHashSignature::ZERO_GUARD;
            }
            $expected = [];
            for ($i = 0; $i < MinHashSignature::SIZE; $i++) {
                $expected[$i] = ($h1 + $i * $h2) & 0xFFFFFFFF;
            }
            $this->assertSame($expected, MinHashSignature::compute([$element => 1]));
        }
    }

    public function testSignatureValuesStayInThirtyTwoBitRange(): void
    {
        $bag = array_fill_keys(['g1', 'g2', 'g3', 'g4', 'g5', 'g6'], 1);
        foreach (MinHashSignature::compute($bag) as $v) {
            $this->assertIsInt($v);
            $this->assertGreaterThanOrEqual(0, $v);
            $this->assertLessThan(0x100000000, $v);
        }
    }

    /**
     * A single-element bag's signature is exactly its row walk, so the
     * walk must never degenerate: with h2 > 0 masked-positive the 128
     * rows of any element are pairwise distinct unless h2 | 2^32 has
     * gcd > 2^25 — astronomically rare (disclosed 2^-25). Pin a batch
     * of real-shaped gram keys to keep that disclosure honest.
     */
    public function testRowsAreDistinctForRepresentativeGramKeys(): void
    {
        foreach (['7f3a9c2b1d4e8801', '0000000000000000', 'notify', 'send', 'save'] as $element) {
            $sig = MinHashSignature::compute([$element => 1]);
            $this->assertCount(MinHashSignature::SIZE, array_unique($sig), $element);
        }
    }

    public function testComputeIsDeterministicAcrossInvocations(): void
    {
        $bag = array_fill_keys(['p', 'q', 'r'], 3);
        $this->assertSame(
            MinHashSignature::compute($bag),
            MinHashSignature::compute(array_flip(array_keys($bag)))
        );
    }
}
