<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Parallel;

use PHPUnit\Framework\TestCase;
use Phpdup\Parallel\WorkerPool;

final class WorkerPoolTest extends TestCase
{
    public function testRunsClosureSeriallyWhenWorkersIsOne(): void
    {
        $pool = new WorkerPool(workers: 1);
        $result = $pool->run([1, 2, 3, 4], static fn(array $batch) => array_map(static fn($x) => $x * 2, $batch));
        sort($result);
        $this->assertSame([2, 4, 6, 8], $result);
    }

    public function testRunsAcrossWorkersWithFlattenedResults(): void
    {
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl unavailable');
        }
        $pool = new WorkerPool(workers: 3);
        $result = $pool->run(range(1, 30), static fn(array $batch) => array_map(static fn($x) => $x * 10, $batch));
        sort($result);
        $expected = array_map(static fn($x) => $x * 10, range(1, 30));
        $this->assertSame($expected, $result);
    }

    public function testEmptyInputReturnsEmpty(): void
    {
        $pool = new WorkerPool(workers: 4);
        $this->assertSame([], $pool->run([], static fn(array $b) => $b));
    }

    public function testDetectsCpuCount(): void
    {
        $n = WorkerPool::detectCpuCount();
        $this->assertGreaterThanOrEqual(1, $n);
    }
}
