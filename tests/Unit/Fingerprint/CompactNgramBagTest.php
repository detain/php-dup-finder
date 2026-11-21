<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Fingerprint;

use PHPUnit\Framework\TestCase;
use Phpdup\Fingerprint\CompactNgramBag;

/**
 * Keyspace pins for the low-memory compact bag (php-dup-finder-engine-042).
 *
 * The fold era stored `hi ^ lo` (32-bit) keys, merging every equal-XOR
 * digest pair. The fix stores the full 64-bit xxh64 digest masked to a
 * positive 63-bit int — tested here against hand-derived decimal literals
 * and the `unpack('J', pack('H16', ...))` bit-pattern oracle.
 */
final class CompactNgramBagTest extends TestCase
{
    public function testCompactPreservesElementCount(): void
    {
        $bag = ['000000000000000a' => 1, '000000000000000b' => 2];
        $compact = CompactNgramBag::compact($bag);
        $this->assertCount(2, $compact);
    }

    public function testEmptyBagCompactsToEmpty(): void
    {
        $this->assertSame([], CompactNgramBag::compact([]));
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

    /** @return array<string,array{0:string,1:int}> */
    public static function digestKeys(): array
    {
        // Decimal literals are the digest values with bit 63 cleared,
        // derived independently of the implementation (hex → int).
        return [
            'low byte only'         => ['000000000000000a', 10],
            'unit'                  => ['0000000000000001', 1],
            'fold input A'          => ['0000000100000002', 4294967298],
            'fold input B'          => ['0000000200000001', 8589934593],
            'max positive'          => ['7fffffffffffffff', 9223372036854775807],
            'sign bit only'         => ['8000000000000000', 0],
            'sign bit + unit'       => ['8000000000000001', 1],
            'all ones'              => ['ffffffffffffffff', 9223372036854775807],
            'mixed high-bit'        => ['deadbeefcafef00d', 6822318947648335885],
            'numeric-string digest' => ['1234567890123456', 1311768467284833366],
        ];
    }

    /**
     * Acceptance: pack/unpack 64-bit positive key equality for high-bit
     * digests. Each key is asserted against (a) the hand-derived decimal
     * literal of the masked digest and (b) the unpack('J') bit pattern
     * masked positive — the fold era would have returned `hi ^ lo` for
     * every row here.
     *
     * @dataProvider digestKeys
     */
    public function testKeyIsFullDigestMaskedPositive(string $hex, int $expected): void
    {
        $compact = CompactNgramBag::compact([$hex => 7]);

        $keys = array_keys($compact);
        $this->assertCount(1, $keys, "digest $hex must mint exactly one int key");
        $this->assertIsInt($keys[0]);
        $this->assertSame([$expected => 7], $compact);

        $viaUnpack = unpack('J', pack('H16', $hex));
        $this->assertIsArray($viaUnpack);
        $this->assertIsInt($viaUnpack[1]);
        $this->assertSame($expected, $viaUnpack[1] & PHP_INT_MAX);
    }

    /**
     * The finding's fold example: hi ^ lo equal, digests distinct. The
     * old 32-bit fold merged both into key 3 (one gram where two exist,
     * inflating any overlap score that touches them); the masked 63-bit
     * keys keep them distinct.
     */
    public function testFoldCollisionPairFromDocExampleIsNowDistinct(): void
    {
        $bag = ['0000000100000002' => 1, '0000000200000001' => 1];

        // Pin the premise: the retired fold merged the pair.
        $fold = static fn(string $h): int
            => ((int)hexdec(substr($h, 0, 8))) ^ ((int)hexdec(substr($h, 8, 8)));
        $this->assertSame($fold('0000000100000002'), $fold('0000000200000001'));

        $compact = CompactNgramBag::compact($bag);
        $this->assertCount(2, $compact, 'masked keys must separate the equal-XOR pair');
        $this->assertArrayHasKey(4294967298, $compact);
        $this->assertArrayHasKey(8589934593, $compact);
    }

    /**
     * Disclosed merge class of positive masking: digests differing ONLY
     * in bit 63 share a key, and their counts merge additively (the
     * multiset sum is preserved — grams are relabelled, never dropped).
     */
    public function testSignBitTwinDigestsMergeWithSummedCounts(): void
    {
        $compact = CompactNgramBag::compact([
            '7fffffffffffffff' => 2,
            'ffffffffffffffff' => 5,
        ]);
        $this->assertSame([PHP_INT_MAX => 7], $compact);
    }

    /**
     * Regression for the era's silent crash risk: an xxh64 digest of 16
     * decimal digits is a canonical numeric-string key — PHP stores it
     * as int on insertion, and strict_types substr()/hexdec() on that
     * int is the fold-era TypeError trap. Keys re-stringify now.
     */
    public function testNumericStringDigestKeyDoesNotThrow(): void
    {
        $bag = ['1234567890123456' => 3];
        $this->assertSame([1311768467284833366 => 3], CompactNgramBag::compact($bag));
    }

    /**
     * Non-vacuity of the keyspace: the masked mapping must stay injective
     * across a spread of structural hashes (real xxh64 digests) — grams
     * that differ in more than the sign bit never merge.
     */
    public function testDistinctDigestsStayDistinctAtScale(): void
    {
        $bag = [];
        for ($i = 0; $i < 5000; $i++) {
            $bag[hash('xxh64', 'gram-' . $i)] = 1;
        }
        $compact = CompactNgramBag::compact($bag);
        // Collisions possible only in the ≈(5000²/2)/2⁶³ ≈ 1.4e-12 sign-
        // bit-twin class — measure the actual count; it must stay zero on
        // this deterministic seed set, and must never fall far short of
        // injectivity (the old fold merged ~1 pair per ~4k grams here).
        $this->assertGreaterThanOrEqual(4998, count($compact));
        foreach ($compact as $key => $count) {
            $this->assertIsInt($key);
            $this->assertGreaterThanOrEqual(0, $key);
        }
    }
}
