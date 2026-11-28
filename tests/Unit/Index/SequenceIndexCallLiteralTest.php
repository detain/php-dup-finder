<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Index;

use PHPUnit\Framework\TestCase;
use Phpdup\Census\CallSequenceCensus;
use Phpdup\Census\LiteralCensus;
use Phpdup\Index\SequenceIndex;

/**
 * Items 3.5/3.3 attach points: the 'calls' and 'literal' flavors ride
 * SequenceIndex WITHOUT disturbing a byte of the statement-sequence,
 * idiom or helper legs — default OFF (censuses never exist, ingest
 * refuses loudly), arms idempotent, getters null until armed.
 */
final class SequenceIndexCallLiteralTest extends TestCase
{
    public function testDefaultIsUnarmedAndBothCensusesInvisible(): void
    {
        $index = new SequenceIndex();
        self::assertFalse($index->callsArmed());
        self::assertFalse($index->literalsArmed());
        self::assertNull($index->callCensus());
        self::assertNull($index->literalCensus());
    }

    public function testRecordingCallsWhileUnarmedRefusesLoudly(): void
    {
        $index = new SequenceIndex();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('armCalls()');
        $index->recordCallFile('a.php', '[]');
    }

    public function testRecordingLiteralsWhileUnarmedRefusesLoudly(): void
    {
        $index = new SequenceIndex();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('armLiterals()');
        $index->recordLiteralFile('a.php', '[]');
    }

    public function testArmsAreIdempotentAndDoNotCross(): void
    {
        $index = new SequenceIndex();
        $index->armCalls();
        $index->armCalls(sampleCap: 99);
        self::assertTrue($index->callsArmed());
        self::assertFalse($index->literalsArmed(), 'the call arm cannot mint the literal census');
        self::assertSame(CallSequenceCensus::DEFAULT_SAMPLE_CAP, $index->callCensus()?->sampleCap,
            'second arm neither resets nor resizes');

        $index->armLiterals();
        self::assertTrue($index->literalsArmed());
        self::assertSame(LiteralCensus::DEFAULT_SAMPLE_CAP, $index->literalCensus()?->sampleCap);
    }

    public function testCarriedPayloadsLandInTheirCensuses(): void
    {
        $index = new SequenceIndex();
        $index->armCalls();
        $index->armLiterals();
        $index->recordCallFile('a.php', json_encode([[
            'names' => ['open', 'write'], 'lines' => [2, 3], 'args' => [[], ['MODE_A']],
            'firstLine' => 2, 'lastLine' => 3,
        ]]));
        $index->recordLiteralFile('a.php', json_encode([
            ['string', sha1('str'), 'a_repeated_string', '', 2, [4, 8]],
        ]));

        $calls = $index->callCensus();
        self::assertInstanceOf(CallSequenceCensus::class, $calls);
        self::assertSame(1, $calls->sitesOf("open\0write"));

        $literals = $index->literalCensus();
        self::assertInstanceOf(LiteralCensus::class, $literals);
        self::assertSame(2, $literals->sitesOf('string', sha1('str')));
    }
}
