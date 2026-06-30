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

    public function testDetectCpuCountMemoizes(): void
    {
        // Force a known env value to avoid static-cache pollution from previous tests.
        $original = getenv('PHPDUP_WORKERS');
        putenv('PHPDUP_WORKERS=7');

        try {
            $first  = WorkerPool::detectCpuCount();
            $second = WorkerPool::detectCpuCount();

            $this->assertSame($first, $second);
            $this->assertSame(7, $first);
        } finally {
            // Restore original env state so subsequent tests are unaffected.
            if ($original === false) {
                putenv('PHPDUP_WORKERS');
            } else {
                putenv("PHPDUP_WORKERS=$original");
            }
        }
    }

    public function testDetectCpuCountNeverCallsNprocWhenEnvSet(): void
    {
        // When PHPDUP_WORKERS is set to a value > 0, nproc must never be invoked.
        // Use a value (99) that cannot match any real machine CPU count.
        $original = getenv('PHPDUP_WORKERS');
        putenv('PHPDUP_WORKERS=99');

        try {
            $result = WorkerPool::detectCpuCount();

            // The result must be the env value (99), proving nproc was bypassed.
            // If nproc were called, the result would be the actual CPU count (≥1, most likely ≠99).
            $this->assertSame(99, $result);
        } finally {
            if ($original === false) {
                putenv('PHPDUP_WORKERS');
            } else {
                putenv("PHPDUP_WORKERS=$original");
            }
        }
    }

    public function testRunStreamingSerialFallbackForSmallInputs(): void
    {
        $pool = new WorkerPool(workers: 4);
        $gen = $pool->runStreaming([1, 2, 3], static fn(array $batch) => array_map(static fn($x) => $x * 2, $batch));
        $this->assertSame([2, 4, 6], iterator_to_array($gen, false));
    }

    public function testRunStreamingYieldsAcrossWorkers(): void
    {
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl unavailable');
        }
        $pool = new WorkerPool(workers: 3);
        $gen = $pool->runStreaming(range(1, 30), static function (array $batch) {
            // Generator-returning task — streams each result as it's produced.
            foreach ($batch as $x) {
                yield $x * 10;
            }
        });
        $out = iterator_to_array($gen, false);
        sort($out);
        $expected = array_map(static fn($x) => $x * 10, range(1, 30));
        $this->assertSame($expected, $out);
    }

    public function testRunStreamingPropagatesChildException(): void
    {
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl unavailable');
        }
        $pool = new WorkerPool(workers: 2);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Worker .* failed: boom/');
        $gen = $pool->runStreaming(range(1, 20), static function (array $batch) {
            throw new \RuntimeException('boom');
        });
        iterator_to_array($gen);
    }

    public function testRunStreamingHandlesArrayReturningTask(): void
    {
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl unavailable');
        }
        $pool = new WorkerPool(workers: 2);
        // Array-returning task is also acceptable — runStreaming treats it as iterable.
        $gen = $pool->runStreaming(range(1, 16), static fn(array $batch) => array_map(static fn($x) => "item-$x", $batch));
        $out = iterator_to_array($gen, false);
        sort($out);
        $expected = array_map(static fn($x) => "item-$x", range(1, 16));
        sort($expected);
        $this->assertSame($expected, $out);
    }
}
