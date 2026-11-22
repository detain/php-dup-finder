<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Ir;

use PHPUnit\Framework\TestCase;
use Phpdup\Ir\IrSimilarity;
use Phpdup\Ir\Nodes\BlockIr;
use Phpdup\Ir\Nodes\DbReadIr;
use Phpdup\Ir\Nodes\ReturnIr;
use Phpdup\Util\Delimiters;
use Phpdup\Util\Hash;

/**
 * Pins for the engine-S31b disposition of src/Ir/IrSimilarity.php
 * (engine-028 catalog row 12 KEEP + engine-046 digest-family
 * transition). The class itself is unwired (the live `--scorer=ir`
 * tier uses JaccardSimilarity over Block::$irBag directly), so
 * nothing outside tests can observe these bytes.
 */
final class IrSimilarityTest extends TestCase
{
    /**
     * Golden id sample for the sha1+NGRAM_SEP → xxh128+TOKEN_JOIN
     * transition (engine-046's "pin a golden IR id sample at the
     * transition"). Derived independently below from IrPrinter's
     * documented token stream for the fixture; recomputed by
     * hashFamilyPin below, and the literal freezes the whole
     * algorithm+separator+join contract at once.
     */
    private const PINNED_IR_ID = '584fe130a314d8f43ced71d96de75f7f';

    private function fixture(): BlockIr
    {
        return new BlockIr([new ReturnIr(new DbReadIr('user', 'id'))]);
    }

    private function variant(): BlockIr
    {
        return new BlockIr([new ReturnIr(new DbReadIr('user', 'name'))]);
    }

    public function testHashIsPinnedToTheXxh128TokenJoinDigestFamily(): void
    {
        $id = (new IrSimilarity())->hash($this->fixture());
        $this->assertSame(32, strlen($id), 'xxh128 digests are 32 hex chars');
        $this->assertSame(
            self::PINNED_IR_ID,
            $id,
            'IR id must stay byte-stable; changing this literal re-mints every IR id',
        );
        // Same-family proof: structurally identical to SubtreeHasher's
        // id recipe (Hash::of over a TOKEN_JOIN-joined token stream).
        $this->assertSame(
            Hash::of(implode(Delimiters::TOKEN_JOIN, ['block(', 'return(', 'db.read:user:id', ')', ')'])),
            $id,
        );
    }

    public function testHashLeftTheLegacySha1NgramSepFamily(): void
    {
        // The two axes engine-046 cares about, pinned as NOT-equal:
        // algorithm (sha1 → xxh128) and separator policy
        // (NGRAM_SEP is the n-gram bag's own delimiter — IR ids
        // must not ride on it).
        $id      = (new IrSimilarity())->hash($this->fixture());
        $legacy  = sha1(implode(Delimiters::NGRAM_SEP, ['block(', 'return(', 'db.read:user:id', ')', ')']));
        $sepRide = Hash::of(implode(Delimiters::NGRAM_SEP, ['block(', 'return(', 'db.read:user:id', ')', ')']));
        $this->assertNotSame($legacy, $id, 'sha1 legacy digest must not return');
        $this->assertNotSame($sepRide, $id, 'IR ids must not depend on the NGRAM_SEP policy');
    }

    public function testHashIsStableForEquivalentTreesAndDistinctForDifferentStreams(): void
    {
        $sim = new IrSimilarity();
        $this->assertSame($sim->hash($this->fixture()), $sim->hash($this->fixture()));
        $this->assertNotSame($sim->hash($this->fixture()), $sim->hash($this->variant()));
    }

    public function testSimilarityModeOneIsExactUnityForByteIdenticalStreams(): void
    {
        $this->assertSame(1.0, (new IrSimilarity())->similarity($this->fixture(), $this->fixture()));
    }

    public function testSimilarityModeTwoIsMultisetJaccardOverTokenLists(): void
    {
        // Streams ['block(','return(','db.read:user:id',')',')'] vs
        // ['block(','return(','db.read:user:name',')',')']: intersect
        // = block( + return( + two ")" = 4; union = 4 + two unique
        // db.read tokens = 6.
        $this->assertSame(
            4 / 6,
            (new IrSimilarity())->similarity($this->fixture(), $this->variant()),
        );
    }

    public function testFullyDisjointStreamsScoreSharedScaffoldingOnly(): void
    {
        $a = new BlockIr([new ReturnIr(new DbReadIr('alpha', 'id'))]);
        $b = new BlockIr([new ReturnIr(new DbReadIr('beta', 'id'))]);
        // engine-S31b fix-round-1 (reviewer F-2): the old name claimed
        // "zero" while the body asserted only "below 1.0". Disjoint
        // payloads still share the structural multiset — block(,
        // return( and the two ")" — so the exact mode-2 value is the
        // hand-computed 4-of-6 fraction, pinned rather than bounded.
        $this->assertSame(4 / 6, (new IrSimilarity())->similarity($a, $b));
    }
}
