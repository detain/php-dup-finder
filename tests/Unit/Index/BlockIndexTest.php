<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Index;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\Block;
use Phpdup\Index\BlockIndex;
use Phpdup\Util\LineRange;

/**
 * Tests for the duplicate-id invariant of {@see BlockIndex::add()}.
 *
 * The index exposes three views (by-id lookup, flat insertion-order
 * list, hash buckets) and get(id) promises exactly one block per id.
 * Before engine-S36 an add() with a colliding id silently overwrote
 * byId while all()/byHash kept both entries — the loser stayed
 * clusterable through the flat view but became unresolvable by id.
 * These tests lock the loud behaviour:
 *   1. colliding id + different object → LogicException naming the id;
 *   2. re-adding the same object is tolerated/idempotent;
 *   3. size(), all(), get(id) and hashBuckets() stay mutually consistent
 *      for a valid index.
 */
final class BlockIndexTest extends TestCase
{
    private function makeBlock(string $id, string $file, string $structuralHash, int $startLine = 1): Block
    {
        $block = new Block(
            file: $file,
            range: new LineRange($startLine, $startLine + 2),
            kind: 'function',
            namespace: null,
            class: null,
            name: $id,
            ast: new Node\Stmt\Return_(new Node\Scalar\String_($id)),
        );
        $block->id = $id;
        $block->structuralHash = $structuralHash;

        return $block;
    }

    public function testCollidingIdFromDifferentObjectThrowsNamingTheId(): void
    {
        $index = new BlockIndex();
        $first = $this->makeBlock('dup_0', 'a.php', 'hashA');
        $second = $this->makeBlock('dup_0', 'b.php', 'hashB', startLine: 10);

        $index->add($first);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('dup_0');
        $index->add($second);
    }

    public function testThrowingAddLeavesTheIndexUntouched(): void
    {
        $index = new BlockIndex();
        $first = $this->makeBlock('dup_0', 'a.php', 'hashA');
        $second = $this->makeBlock('dup_0', 'b.php', 'hashA');

        $index->add($first);
        try {
            $index->add($second);
            $this->fail('expected LogicException for colliding id');
        } catch (\LogicException) {
            // the rejected object must appear in no view...
        }

        self::assertSame(1, $index->size());
        self::assertCount(1, $index->all());
        self::assertSame($first, $index->get('dup_0'));
        self::assertSame([$first], $index->hashBuckets()['hashA']);
    }

    public function testReAddingSameObjectIsIdempotent(): void
    {
        $index = new BlockIndex();
        $block = $this->makeBlock('same_0', 'a.php', 'hashA');

        $index->add($block);
        $index->add($block);

        self::assertSame(1, $index->size());
        self::assertCount(1, $index->all());
        self::assertSame($block, $index->all()[0]);
        self::assertSame($block, $index->get('same_0'));
        self::assertSame([$block], $index->hashBuckets()['hashA']);
    }

    public function testDistinctIdsSharingAHashAreAcceptedAsExactClones(): void
    {
        $index = new BlockIndex();
        $twinA = $this->makeBlock('twin_a', 'a.php', 'hashX');
        $twinB = $this->makeBlock('twin_b', 'b.php', 'hashX');

        $index->add($twinA);
        $index->add($twinB);

        self::assertSame([$twinA, $twinB], $index->hashBuckets()['hashX']);
    }

    public function testThreeViewsStayConsistent(): void
    {
        $index = new BlockIndex();
        $blocks = [
            $alpha = $this->makeBlock('alpha', 'a.php', 'hashShared'),
            $beta  = $this->makeBlock('beta', 'b.php', 'hashShared'),
            $gamma = $this->makeBlock('gamma', 'c.php', 'hashSolo'),
        ];
        foreach ($blocks as $b) {
            $index->add($b);
        }

        $all = $index->all();
        $buckets = $index->hashBuckets();

        // flat list: insertion order, same objects identity-wise.
        self::assertSame([$alpha, $beta, $gamma], $all);

        // size agrees with every view.
        self::assertSame(3, $index->size());
        self::assertCount($index->size(), $all);
        $bucketTotal = 0;
        foreach ($buckets as $bucket) {
            $bucketTotal += count($bucket);
        }
        self::assertSame($index->size(), $bucketTotal);

        // by-id lookup returns the very objects the flat list holds.
        foreach ($blocks as $b) {
            self::assertSame($b, $index->get($b->id));
        }
        self::assertNull($index->get('no_such_id'));

        // bucket membership is exactly the flat membership.
        self::assertSame([$alpha, $beta], $buckets['hashShared']);
        self::assertSame([$gamma], $buckets['hashSolo']);
        $inBuckets = [];
        foreach ($buckets as $bucket) {
            foreach ($bucket as $b) {
                $inBuckets[] = $b;
            }
        }
        usort($inBuckets, static fn (Block $x, Block $y): int => $x->id <=> $y->id);
        $sorted = $blocks;
        usort($sorted, static fn (Block $x, Block $y): int => $x->id <=> $y->id);
        self::assertSame($sorted, $inBuckets);
    }
}
