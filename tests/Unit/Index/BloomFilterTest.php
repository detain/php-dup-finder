<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Index;

use PHPUnit\Framework\TestCase;
use Phpdup\Index\BloomFilter;

final class BloomFilterTest extends TestCase
{
    public function testAddedElementMayContain(): void
    {
        $f = new BloomFilter();
        $f->add('hello');
        $this->assertTrue($f->mayContain('hello'));
    }

    public function testFalsePositiveRateBelowFivePercent(): void
    {
        $f = new BloomFilter(bits: 2048, hashes: 3);
        for ($i = 0; $i < 100; $i++) {
            $f->add("ngram-$i");
        }
        $fp = 0;
        for ($i = 1000; $i < 2000; $i++) {
            if ($f->mayContain("ngram-$i")) $fp++;
        }
        $this->assertLessThan(50, $fp, "FPR should be < 5% on 2048-bit filter with 100 elements");
    }

    public function testIdenticalFiltersOverlapToOne(): void
    {
        $a = new BloomFilter();
        $b = new BloomFilter();
        foreach (['x', 'y', 'z'] as $g) { $a->add($g); $b->add($g); }
        $this->assertEqualsWithDelta(1.0, BloomFilter::overlap($a, $b), 1e-9);
    }

    public function testDisjointFiltersOverlapToZero(): void
    {
        $a = new BloomFilter();
        $b = new BloomFilter();
        foreach (['a', 'b', 'c'] as $g) $a->add($g);
        foreach (['x', 'y', 'z'] as $g) $b->add($g);
        $this->assertLessThan(0.2, BloomFilter::overlap($a, $b));
    }

    public function testRejectsNonPowerOfTwoBits(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BloomFilter(bits: 1000);
    }

    public function testOverlapRejectsWidthMismatch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BloomFilter::overlap(new BloomFilter(1024), new BloomFilter(2048));
    }
}
