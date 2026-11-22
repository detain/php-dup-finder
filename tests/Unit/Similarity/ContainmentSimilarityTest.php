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

    /**
     * Deterministic mini-LCG (no global RNG state, PHP 8.1 floor).
     * Yields reproducible pseudo-random ints in [0, modulus).
     *
     * glibc-style 31-bit parameters: the worst-case intermediate
     * (2^31-1) * 1103515245 + 12345 ≈ 2.38e18 stays inside PHP_INT_MAX
     * on 64-bit hosts, so every step is exact integer math — no float
     * overflow, no implicit-conversion deprecation.
     */
    private static function nextRandom(int &$state, int $modulus): int
    {
        $state = ($state * 1103515245 + 12345) % 2147483648;
        return $state % $modulus;
    }

    /**
     * @return array<string|int,int>
     */
    private static function randomBag(int &$state, int $maxKeys): array
    {
        $bag  = [];
        $size = self::nextRandom($state, $maxKeys + 1);
        for ($i = 0; $i < $size; $i++) {
            // Mix string and int keys so both ?? and isset() paths are exercised.
            $key   = $i % 2 === 0 ? 'g' . self::nextRandom($state, 7) : self::nextRandom($state, 5);
            $bag[$key] = self::nextRandom($state, 6); // counts 0..5, incl. zero-weight keys
        }
        return $bag;
    }

    /**
     * Acceptance (engine-035): the combined single pass must return tuples
     * IDENTICAL to the old two-pass (similarity(), sizeRatio()) across random
     * integer bags — bit-for-bit, so the refactor is value-preserving and the
     * Ranker/edge weights built from $cont cannot drift.
     */
    public function testCombinedMatchesTwoPassOnRandomBags(): void
    {
        $sim   = new ContainmentSimilarity();
        $state = 20261122;
        for ($round = 0; $round < 300; $round++) {
            $a = self::randomBag($state, 8);
            $b = self::randomBag($state, 8);
            $combined = $sim->containmentAndSizeRatio($a, $b);
            $this->assertSame(
                $sim->similarity($a, $b),
                $combined['containment'],
                "containment drift on round {$round}: " . json_encode([$a, $b])
            );
            $this->assertSame(
                $sim->sizeRatio($a, $b),
                $combined['sizeRatio'],
                "sizeRatio drift on round {$round}: " . json_encode([$a, $b])
            );
        }
    }

    public function testCombinedEmptyBagEdgeCases(): void
    {
        $sim = new ContainmentSimilarity();
        $this->assertSame(
            ['containment' => 0.0, 'sizeRatio' => 0.0],
            $sim->containmentAndSizeRatio([], [])
        );
        $this->assertSame(
            ['containment' => 0.0, 'sizeRatio' => 0.0],
            $sim->containmentAndSizeRatio([], ['a' => 3])
        );
        $this->assertSame(
            ['containment' => 0.0, 'sizeRatio' => 0.0],
            $sim->containmentAndSizeRatio(['a' => 3], [])
        );
        // Non-empty bags whose counts all sum to zero still gate to zero.
        $this->assertSame(
            ['containment' => 0.0, 'sizeRatio' => 0.0],
            $sim->containmentAndSizeRatio(['a' => 0, 'b' => 0], ['a' => 0, 'c' => 0])
        );
    }

    /**
     * Hand-computed tuple pin: distinct denominators per metric (containment
     * divides by the SMALLER sum, size ratio by the LARGER) — swapping either
     * denominator changes a value and fails here.
     */
    public function testCombinedHandComputedTuple(): void
    {
        $sim = new ContainmentSimilarity();
        // sumA = 8, sumB = 2, intersection-min = 1.
        $a = ['a' => 7, 'x' => 1];
        $b = ['a' => 1, 'c' => 1];
        $this->assertSame(
            ['containment' => 0.5, 'sizeRatio' => 0.25],
            $sim->containmentAndSizeRatio($a, $b)
        );
        // Subset shape from testSubsetScoresOne: C = 1.0 while the ratio shows
        // the size gap (3/5).
        $this->assertSame(
            ['containment' => 1.0, 'sizeRatio' => 0.6],
            $sim->containmentAndSizeRatio(
                ['a' => 1, 'b' => 1, 'c' => 1, 'd' => 1, 'e' => 1],
                ['a' => 1, 'b' => 1, 'c' => 1]
            )
        );
        // Symmetric bags: both metrics saturate at 1.0.
        $this->assertSame(
            ['containment' => 1.0, 'sizeRatio' => 1.0],
            $sim->containmentAndSizeRatio(['a' => 2, 'b' => 1], ['a' => 2, 'b' => 1])
        );
    }

    /**
     * The merged pass must be order-symmetric in the ratio like the old pair:
     * sizeRatio(a, b) === sizeRatio(b, a); containment shares the smaller-side
     * denominator, so similarity() is itself symmetric — pin both to catch a
     * combined implementation that only walks one direction correctly.
     */
    public function testCombinedIsOrderSymmetricLikeTwoPass(): void
    {
        $sim = new ContainmentSimilarity();
        $a   = ['a' => 4, 'b' => 2, 'q' => 1]; // sum 7
        $b   = ['a' => 1, 'z' => 3];           // sum 4, inter-min 1
        $ab  = $sim->containmentAndSizeRatio($a, $b);
        $ba  = $sim->containmentAndSizeRatio($b, $a);
        $this->assertEqualsWithDelta(0.25, $ab['containment'], 0.0001, 'non-vacuous containment');
        $this->assertEqualsWithDelta(4 / 7, $ab['sizeRatio'], 0.0001, 'non-vacuous size ratio');
        $this->assertSame($ab['containment'], $ba['containment']);
        $this->assertSame($ab['sizeRatio'], $ba['sizeRatio']);
        $this->assertSame($ab['containment'], $sim->similarity($b, $a));
        $this->assertSame($ab['sizeRatio'], $sim->sizeRatio($b, $a));
    }
}
