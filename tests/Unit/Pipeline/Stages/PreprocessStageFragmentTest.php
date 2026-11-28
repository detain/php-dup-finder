<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Pipeline\Stages;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Util\LineRange;

/**
 * Item 2.1 — the suppression rules at the stage seam: S-floor (the doc's
 * fragment_min_sites=3), S-eq (a run flagged whole duplicates the block
 * already minted for it / carries no identity) and S-max (a run whose
 * same-file one-step extension — left or right, read off the persisted
 * (pos,size) slots — carries at least as many sites never stands). The
 * law binds block FIELDS only — never the SequenceIndex — which is what
 * makes cold and warm replays land on the same survivors (warm files
 * carry cached Blocks, not mined rows).
 *
 * In these fixtures the structuralHash stands in for the run content
 * identity (equal hashes = equal content), as the worker's real
 * run-local normalization would produce.
 */
final class PreprocessStageFragmentTest extends TestCase
{
    /** @param list<Block> $blocks @return list<Block> */
    private function suppress(array $blocks): array
    {
        $m = new \ReflectionMethod(PreprocessStage::class, 'suppressNonMaximalFragments');
        return $m->invoke(null, $blocks);
    }

    private function frag(string $file, int $pos, int $size, string $sh, bool $whole = false): Block
    {
        $b = new Block($file, new LineRange($pos + 1, $pos + $size), BlockExtractor::FRAGMENT_KIND, null, null, null, new Node\Stmt\Nop());
        $b->structuralHash = $sh;
        $b->fragmentKey = $sh;
        $b->fragmentPos = $pos;
        $b->fragmentSize = $size;
        $b->fragmentWhole = $whole;
        return $b;
    }

    private function other(string $file): Block
    {
        return new Block($file, new LineRange(1, 9), 'method', null, null, 'm', new Node\Stmt\Nop());
    }

    public function testSupportFloorDropsUnderThreeSites(): void
    {
        $k = str_repeat('a', 40);
        $blocks = [
            $this->frag('x.php', 1, 3, $k),
            $this->frag('y.php', 1, 3, $k),
        ];
        $this->assertSame([], $this->suppress($blocks), 'two sites is an accident, not a finding');
    }

    public function testThreeExactSitesSurvive(): void
    {
        $k = str_repeat('a', 40);
        $blocks = [
            $this->frag('x.php', 1, 3, $k),
            $this->frag('y.php', 1, 3, $k),
            $this->frag('z.php', 1, 3, $k),
        ];
        $this->assertCount(3, $this->suppress($blocks), 'the doc example: three sites of one run is what 2.1 must surface');
    }

    public function testWholeRunEqualsMintedBlockAndDies(): void
    {
        $k = str_repeat('a', 40);
        $blocks = [
            $this->frag('x.php', 0, 4, $k, true),
            $this->frag('y.php', 0, 4, $k, true),
            $this->frag('z.php', 0, 4, $k, true),
        ];
        $this->assertSame([], $this->suppress($blocks), 'the run duplicates its whole declaration body');
    }

    public function testEmptyHashIsMalformedAndDropped(): void
    {
        $this->assertSame([], $this->suppress([$this->frag('x.php', 1, 3, '')]));
    }

    public function testNonFragmentsPassThrough(): void
    {
        $a = $this->other('x.php');
        $b = $this->other('y.php');
        $this->assertSame([$a, $b], $this->suppress([$a, $b]));
    }

    public function testRightExtensionWithAtLeastEqualSupportSuppresses(): void
    {
        $base = str_repeat('a', 40);
        $ext  = str_repeat('b', 40);
        $blocks = [];
        foreach (['x', 'y', 'z'] as $f) {
            $blocks[] = $this->frag($f . '.php', 1, 3, $base);
            $blocks[] = $this->frag($f . '.php', 1, 4, $ext); // right extension of each base
        }
        $kept = $this->suppress($blocks);
        $this->assertCount(3, $kept, 'the maximal run owns the sequence; every base dies on its own extension');
        foreach ($kept as $b) {
            $this->assertSame($ext, $b->structuralHash);
        }
    }

    public function testFallingSupportChainStandsAtEveryRung(): void
    {
        $base = str_repeat('a', 40);
        $ext  = str_repeat('b', 40);
        $blocks = [];
        foreach (['p', 'q', 'r', 's', 't'] as $f) {
            $blocks[] = $this->frag($f . '.php', 1, 3, $base);
        }
        foreach (['p', 'q', 'r'] as $f) {
            $blocks[] = $this->frag($f . '.php', 1, 4, $ext); // witness support 3 < max(3, 5)
        }
        $this->assertCount(8, $this->suppress($blocks), 'the doc keeps maximal-PER-SUPPORT rows: a 5-site rung and its 3-site extension both stand');
    }

    public function testLeftExtensionSuppressesEqually(): void
    {
        $base = str_repeat('a', 40);
        $ext  = str_repeat('c', 40);
        $blocks = [];
        foreach (['x', 'y', 'z'] as $f) {
            $blocks[] = $this->frag($f . '.php', 2, 3, $base);
            $blocks[] = $this->frag($f . '.php', 1, 4, $ext); // (pos-1, size+1) of each base
        }
        $kept = $this->suppress($blocks);
        $this->assertCount(3, $kept);
        foreach ($kept as $b) {
            $this->assertSame($ext, $b->structuralHash);
        }
    }

    public function testOnlyOneStepSlotWitnessesSuppress(): void
    {
        // v1 witnesses are the two one-step slots of the block's OWN file
        // ((pos-1,size+1) and (pos,size+1)). A longer run that merely
        // OVERLAPS the base elsewhere in the list is not a slot witness;
        // cross-file containment is not a witness at all — that law is
        // 3.x's, over the SequenceIndex.
        $base = str_repeat('a', 40);
        $ext  = str_repeat('b', 40);
        $blocks = [];
        foreach (['x', 'y', 'z'] as $f) {
            $blocks[] = $this->frag($f . '.php', 1, 3, $base);
        }
        foreach (['x', 'y', 'z', 'w'] as $f) {
            $blocks[] = $this->frag($f . '.php', 2, 4, $ext); // overlaps the base's span but sits at neither one-step slot
        }
        $kept = $this->suppress($blocks);
        $this->assertCount(7, $kept, 'no one-step slot witness exists for any rung — all stand');
    }

    public function testSuppressionIsFieldOnlyAndRepeatable(): void
    {
        $k = str_repeat('a', 40);
        $blocks = [];
        foreach (['x', 'y', 'z', 'w'] as $f) {
            $blocks[] = $this->frag($f . '.php', 1, 3, $k);
        }
        $first  = $this->suppress($blocks);
        $second = $this->suppress($blocks);
        $this->assertSame($first, $second, 'a pure function of the block fields (cold==warm by construction)');
    }
}
