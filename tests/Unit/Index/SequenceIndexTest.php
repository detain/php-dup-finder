<?php

declare(strict_types=1);

namespace Phpdup\Tests\Unit\Index;

use Phpdup\Index\SequenceIndex;
use PHPUnit\Framework\TestCase;

/**
 * Item 2.1: the shared §5c sequence store. ids are content-derived
 * (stable across runs/engines), occurrence positions serve the 3.x
 * census consumers.
 */
final class SequenceIndexTest extends TestCase
{
    public function testIdsAreContentDerivedAndStable(): void
    {
        $a = new SequenceIndex();
        $b = new SequenceIndex();
        $idA = $a->recordList('x.php', ['h1', 'h2', 'h3'], 5, 9, 'function');
        $idB = $b->recordList('x.php', ['h1', 'h2', 'h3'], 5, 9, 'function');
        self::assertSame($idA, $idB, 'same content => same list id across instances');

        $c = new SequenceIndex();
        $idC = $c->recordList('x.php', ['h1', 'hZ', 'h3'], 5, 9, 'function');
        self::assertNotSame($idA, $idC, 'different content => different id');

        $d = new SequenceIndex();
        $idD = $d->recordList('y.php', ['h1', 'h2', 'h3'], 5, 9, 'function');
        self::assertNotSame($idA, $idD, 'different file => different id');
    }

    public function testListsForAndCounters(): void
    {
        $ix = new SequenceIndex();
        $ix->recordList('a.php', ['p', 'q'], 1, 2, 'method');
        $ix->recordList('a.php', ['r', 's', 't'], 10, 14, 'function');
        $ix->recordList('b.php', ['p', 'q'], 3, 4, '');

        $lists = $ix->listsFor('a.php');
        self::assertCount(2, $lists);
        self::assertSame(['p', 'q'], $lists[0]['hashes']);
        self::assertSame('method', $lists[0]['ownerKind']);
        self::assertSame(10, $lists[1]['firstLine']);
        self::assertSame([], $ix->listsFor('missing.php'));

        self::assertSame(2, $ix->fileCount());
        self::assertSame(3, $ix->listCount());
        self::assertSame(7, $ix->statementCount());
    }

    public function testPositionsOfSpansFilesAndInvalidatesOnRecord(): void
    {
        $ix = new SequenceIndex();
        $idA = $ix->recordList('a.php', ['shared', 'other'], 1, 2, '');
        self::assertSame([['file' => 'a.php', 'listId' => $idA, 'pos' => 0]], $ix->positionsOf('shared'));

        $idB = $ix->recordList('b.php', ['x', 'shared'], 5, 6, '');
        $positions = $ix->positionsOf('shared');
        self::assertCount(2, $positions, 'occurrence map rebuilds after new records');
        self::assertSame([['file' => 'a.php', 'listId' => $idA, 'pos' => 0], ['file' => 'b.php', 'listId' => $idB, 'pos' => 1]], $positions);

        self::assertSame([], $ix->positionsOf('never-recorded'));
    }

    public function testEmptyListFailsLoud(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SequenceIndex())->recordList('x.php', [], 1, 1, '');
    }
}
