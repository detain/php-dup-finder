<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Index;

use PHPUnit\Framework\TestCase;
use Phpdup\Census\SubtreeCensus;
use Phpdup\Index\SequenceIndex;

/**
 * Item 3.1 attach point: the idiom flavor rides SequenceIndex WITHOUT
 * disturbing a single byte of B1's statement-sequence paths — default
 * OFF (unarmed = census never exists, ingest refuses loudly), and the
 * fragment/statement legs keep answering identically whether or not
 * idioms are armed and recorded.
 */
final class SequenceIndexIdiomTest extends TestCase
{
    private static function payload(int $count = 2): string
    {
        return json_encode([
            'sites' => [['kk', 'ab', 8, 'Expr_BinaryOp_Plus', $count, [3, 9]]],
            'edges' => [],
        ], JSON_THROW_ON_ERROR);
    }

    public function testDefaultIsUnarmedAndCensusInvisible(): void
    {
        $index = new SequenceIndex();
        self::assertFalse($index->idiomsArmed());
        self::assertNull($index->idiomCensus());
    }

    public function testRecordingWhileUnarmedRefusesLoudly(): void
    {
        $index = new SequenceIndex();
        $this->expectException(\LogicException::class);
        $index->recordIdiomFile('a.php', self::payload());
    }

    public function testArmIsIdempotentAndCarriesIngestedRows(): void
    {
        $index = new SequenceIndex();
        $index->armIdioms();
        $index->armIdioms(sampleCap: 99); // second arm must not reset or resize
        self::assertTrue($index->idiomsArmed());

        $index->recordIdiomFile('a.php', self::payload(2));
        $census = $index->idiomCensus();
        self::assertInstanceOf(SubtreeCensus::class, $census);
        self::assertSame(1, $census->hashCount());
        self::assertSame(2, $census->sitesOf('kk'));
        self::assertSame(10, $census->sampleCap, 'first arm wins — idempotent');
    }

    public function testB1StatementPathsByteStableUnderArmedIdioms(): void
    {
        // Same recording script, armed vs plain: list ids, occurrence
        // positions, counters — the fragment consumer contract must not
        // feel the census at all.
        $plain = new SequenceIndex();
        $armed = new SequenceIndex();
        $armed->armIdioms();

        $idA = $plain->recordList('x.php', ['h1', 'h2', 'h3'], 5, 9, 'function');
        $idB = $armed->recordList('x.php', ['h1', 'h2', 'h3'], 5, 9, 'function');
        self::assertSame($idA, $idB);

        $armed->recordIdiomFile('x.php', self::payload(3));

        self::assertSame($plain->listsFor('x.php'), $armed->listsFor('x.php'));
        self::assertSame($plain->positionsOf('h2'), $armed->positionsOf('h2'));
        self::assertSame($plain->fileCount(), $armed->fileCount());
        self::assertSame($plain->listCount(), $armed->listCount());
        self::assertSame($plain->statementCount(), $armed->statementCount());
        self::assertNotSame($plain->positionsOf('h2'), [], 'the B1 leg itself still works while armed');
    }

    public function testRecordIdiomFileDecodesThroughTheBoundary(): void
    {
        $index = new SequenceIndex();
        $index->armIdioms();
        $this->expectException(\InvalidArgumentException::class);
        $index->recordIdiomFile('a.php', '{"sites":[["only-two-fields"]]}');
    }
}
