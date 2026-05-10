<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Index;

use PHPUnit\Framework\TestCase;
use Phpdup\Index\ExternalSort;

final class ExternalSortTest extends TestCase
{
    public function testEmptyInputProducesEmptyOutput(): void
    {
        $sorter = new ExternalSort(sys_get_temp_dir(), runSize: 4);
        $out = iterator_to_array($sorter->sortStream([]), false);
        $this->assertSame([], $out);
    }

    public function testSortsAcrossRuns(): void
    {
        $sorter = new ExternalSort(sys_get_temp_dir(), runSize: 3);
        $records = [
            ['c', '3'],
            ['a', '1'],
            ['e', '5'],
            ['b', '2'],
            ['d', '4'],
        ];
        $sorted = iterator_to_array($sorter->sortStream($records), false);
        $keys = array_column($sorted, 0);
        $this->assertSame(['a', 'b', 'c', 'd', 'e'], $keys);
    }

    public function testPreservesPayloads(): void
    {
        $sorter = new ExternalSort(sys_get_temp_dir(), runSize: 2);
        $records = [['z', 'last'], ['a', 'first']];
        $sorted = iterator_to_array($sorter->sortStream($records), false);
        $this->assertSame([['a', 'first'], ['z', 'last']], $sorted);
    }
}
