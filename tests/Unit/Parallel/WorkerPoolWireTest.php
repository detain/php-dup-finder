<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Parallel;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Parallel\WorkerPool;
use Phpdup\Persistence\SerializedClassAllowList;
use Phpdup\Parsing\AstParser;

/**
 * Wire-decode regression for BUG-B (improve_logic.md item 0.1).
 *
 * WorkerPool decoded every child record with 'allowed_classes' => false,
 * so each Block-bearing row arrived as __PHP_Incomplete_Class and the run
 * died during id assignment (bin/phpdup analyze src => exit 255 on any
 * corpus with >= 8 files under pcntl). The fix decodes with the same
 * explicit allow-list the disk caches use and refuses to yield records
 * carrying classes that are missing from it.
 */
final class WorkerPoolWireTest extends TestCase
{
    public function testForkedBlockRowsDecodeAsRealObjects(): void
    {
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl unavailable');
        }

        $block = $this->makeRealBlock();
        $pool  = new WorkerPool(workers: 2);

        $rows = iterator_to_array(
            $pool->runStreaming(range(1, 9), static function (array $batch) use ($block): \Generator {
                foreach ($batch as $item) {
                    yield ['type' => 'block', 'file' => 'wire-test.php', 'item' => $item, 'block' => $block];
                }
            }),
            false,
        );

        // 9 items across 2 workers crosses the >= 8-file fork threshold,
        // so every row below went through serialize → socketpair → unserialize.
        $this->assertCount(9, $rows);
        foreach ($rows as $row) {
            $this->assertInstanceOf(Block::class, $row['block']);
            $this->assertNotInstanceOf(\__PHP_Incomplete_Class::class, $row['block']);
            $this->assertInstanceOf(Node::class, $row['block']->canonical);
            $this->assertSame('abc12345def67890', $row['block']->structuralHash);
            $this->assertIsArray($row['block']->ngramBag);
            $this->assertIsString($row['type']);
            $this->assertSame('wire-test.php', $row['file']);
        }
    }

    public function testBlockedClassAtTopLevelFailsLoud(): void
    {
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl unavailable');
        }

        $poison = new WireProbeForeignObject();
        $pool   = new WorkerPool(workers: 2);

        $caught = null;
        try {
            iterator_to_array(
                $pool->runStreaming(range(1, 9), static function (array $batch) use ($poison): array {
                    return [['type' => 'poison', 'file' => 'wire-test.php', 'object' => $poison]];
                }),
                false,
            );
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'a mid-stream blocked-class frame must throw');
        $this->assertMatchesRegularExpression('/allow-list/', $caught->getMessage());
        $this->assertMatchesRegularExpression('/WireProbeForeignObject/', $caught->getMessage());
        $this->assertNoWaitableChildrenRemain();
    }

    public function testBlockedClassNestedInsideAllowedObjectFailsLoud(): void
    {
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl unavailable');
        }

        $block  = $this->makeRealBlock();
        // Simulate the realistic drift: a future wire value hides a new,
        // not-yet-allowed class deep inside an otherwise-allowed graph.
        $block->ngramBag = ['probe-token-a' => new WireProbeForeignObject()];
        $pool = new WorkerPool(workers: 2);

        $caught = null;
        try {
            iterator_to_array(
                $pool->runStreaming(range(1, 9), static function (array $batch) use ($block): array {
                    return [['type' => 'block', 'file' => 'wire-test.php', 'block' => $block]];
                }),
                false,
            );
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'a blocked class nested in an allowed graph must throw');
        $this->assertMatchesRegularExpression('/WireProbeForeignObject/', $caught->getMessage());
        $this->assertNoWaitableChildrenRemain();
    }

    /**
     * Zero-orphan pin (reviewer m2): when the frame loop throws mid-stream,
     * the pool generator's `finally` must have SIGTERMed AND blocking-waited
     * every forked child before the exception surfaces to us. Drain
     * pcntl_waitpid(-1, WNOHANG) under a hard iteration cap: reaping any
     * child here (the finally missed it) or finding the process still
     * waitable-at-cap fails loud with the live pid list from /proc — never
     * hangs the suite. The reflection-driven corrupt-frame test throws from
     * the same loop body and unwinds through this identical finally, so its
     * reap is covered by pinning the path here.
     */
    private function assertNoWaitableChildrenRemain(): void
    {
        $reapedLate = [];
        $stillLive  = false;
        for ($i = 0; $i < 64; $i++) {
            $status = 0;
            $pid    = pcntl_waitpid(-1, $status, WNOHANG);
            if ($pid === -1) {
                break; // no waitable children: the pool reaped everything itself
            }
            if ($pid === 0) {
                $stillLive = true; // a child exists and has not exited; keep draining under the cap
                usleep(5000);
                continue;
            }
            $reapedLate[] = $pid; // still waitable AFTER the throw unwound the generator: orphan
        }

        if ($reapedLate !== [] || $stillLive) {
            $children = @file_get_contents('/proc/' . getmypid() . '/task/' . getmypid() . '/children');
            $this->fail(sprintf(
                'mid-stream throw left unreaped WorkerPool children (late-reaped pids: %s; /proc children: %s)',
                implode(',', $reapedLate) ?: '-',
                is_string($children) && $children !== '' ? trim($children) : '-',
            ));
        }
    }

    public function testScalarOnlyRowsRoundTripUnchanged(): void
    {
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl unavailable');
        }

        $pool = new WorkerPool(workers: 2);

        $rows = iterator_to_array(
            $pool->runStreaming(range(1, 9), static function (array $batch): \Generator {
                foreach ($batch as $item) {
                    // PairScoreWorker channel shape: pure scalars, never an object.
                    yield ['a-00' . $item, 'b-00' . $item, 0.75, 'jaccard'];
                }
            }),
            false,
        );

        $this->assertCount(9, $rows);
        $byKey = [];
        foreach ($rows as $row) {
            $this->assertSame([0, 1, 2, 3], array_keys($row));
            $this->assertIsString($row[0]);
            $this->assertIsFloat($row[2]);
            $byKey[$row[0]] = $row;
        }
        ksort($byKey);
        $i = 0;
        foreach ($byKey as $row) {
            $i++;
            $this->assertSame(['a-00' . $i, 'b-00' . $i, 0.75, 'jaccard'], $row);
        }
    }

    /**
     * Reviewer M1 regression pins: a frame whose bytes cannot deserialize
     * (truncated or mangled from a OTHERWISE-VALID serialized row) must
     * throw at the decode seam the stream loop uses — never yield `false`.
     * Driven via reflection because writeRecord owns the encode side, so a
     * task closure cannot legally emit corrupt bytes; the fork round-trip
     * tests above keep the seam wired into real streaming, and the zero-
     * orphan pin (assertNoWaitableChildrenRemain, applied at the blocked-
     * class fork throws) unwinds through this path's identical generator
     * finally.
     */
    public function testCorruptMidStreamFrameFailsLoudInsteadOfYieldingFalse(): void
    {
        $decode    = new \ReflectionMethod(WorkerPool::class, 'decodeFrame');
        $decode->setAccessible(true);
        $allowList = SerializedClassAllowList::blockCacheClasses();

        $valid = serialize(['type' => 'block', 'file' => 'x.php', 'block' => $this->makeRealBlock()]);

        foreach ([
            'truncated row' => substr($valid, 0, -8),
            'mangled row'   => str_replace('s:5:"x.php"', 's:5:"x', $valid),
            'foreign bytes' => 'NOT-A-SERIALIZED-FRAME',
            'literal false' => 'b:0;',
        ] as $label => $payload) {
            try {
                $decode->invoke(null, 3, $payload, $allowList);
                $this->fail("a $label payload must not decode silently");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('failed to decode', $e->getMessage(), $label);
                $this->assertStringContainsString('Worker 3', $e->getMessage(), $label);
                $this->assertStringContainsString('Refusing to continue with corrupt data.', $e->getMessage(), $label);
            }
        }
    }

    public function testDecodeFrameReturnsSentinelsAndValidRecordsUntouched(): void
    {
        $decode    = new \ReflectionMethod(WorkerPool::class, 'decodeFrame');
        $decode->setAccessible(true);
        $allowList = SerializedClassAllowList::blockCacheClasses();

        // Sentinel precedence: well-formed control frames pass through for
        // the caller's own checks, exactly as before the extraction.
        $this->assertSame(['__done' => true], $decode->invoke(null, 0, serialize(['__done' => true]), $allowList));
        $this->assertSame(['__error' => 'boom'], $decode->invoke(null, 0, serialize(['__error' => 'boom']), $allowList));

        $block = $this->makeRealBlock();
        $row   = ['type' => 'block', 'file' => 'x.php', 'block' => $block];
        $out   = $decode->invoke(null, 0, serialize($row), $allowList);
        $this->assertSame('block', $out['type']);
        $this->assertInstanceOf(Block::class, $out['block']);
        $this->assertSame($row['file'], $out['file']);
    }

    /** A real parsed + extracted Block, mirroring PreprocessWorker rows. */
    private function makeRealBlock(): Block
    {
        $parser = new AstParser();
        $stmts  = $parser->parseCode('<?php function wire_probe($a, $b) { if ($a) { return $b + 1; } return $b; }');
        $this->assertIsArray($stmts);

        $blocks = (new BlockExtractor(minSize: 1))->extract('wire-test.php', $stmts);
        $this->assertNotEmpty($blocks);

        $block                  = $blocks[0];
        $block->canonical       = $stmts[0];
        $block->structuralHash  = 'abc12345def67890';
        $block->size            = 3;
        $block->ngramBag        = ['probe-token-a' => 2, 'probe-token-b' => 1];
        return $block;
    }
}

/**
 * Deliberately absent from SerializedClassAllowList — stands in for any
 * future wire class whose author forgot to extend the allow-list.
 * Defined here (not in src/) so it is never autoloadable as a real wire type.
 */
final class WireProbeForeignObject
{
    public int $marker = 1;
}
