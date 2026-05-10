<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Fingerprint;

use PHPUnit\Framework\TestCase;
use Phpdup\Fingerprint\CompactNgramBag;

final class CompactNgramBagTest extends TestCase
{
    public function testCompactPreservesElementCount(): void
    {
        $bag = ['000000000000000a' => 1, '000000000000000b' => 2];
        $compact = CompactNgramBag::compact($bag);
        $this->assertCount(2, $compact);
    }

    public function testIdenticalBagsHaveJaccardOne(): void
    {
        $a = CompactNgramBag::compact(['000000000000000a' => 1, '000000000000000b' => 2]);
        $b = CompactNgramBag::compact(['000000000000000a' => 1, '000000000000000b' => 2]);
        $this->assertSame(1.0, CompactNgramBag::jaccard($a, $b));
    }

    public function testDisjointBagsHaveJaccardZero(): void
    {
        $a = CompactNgramBag::compact(['000000000000000a' => 1]);
        $b = CompactNgramBag::compact(['000000000000000b' => 1]);
        $this->assertSame(0.0, CompactNgramBag::jaccard($a, $b));
    }
}
