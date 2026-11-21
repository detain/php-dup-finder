<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Parallel;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Parallel\WorkerPool;
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

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/allow-list/');
        $this->expectExceptionMessageMatches('/WireProbeForeignObject/');

        iterator_to_array(
            $pool->runStreaming(range(1, 9), static function (array $batch) use ($poison): array {
                return [['type' => 'poison', 'file' => 'wire-test.php', 'object' => $poison]];
            }),
            false,
        );
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

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/WireProbeForeignObject/');

        iterator_to_array(
            $pool->runStreaming(range(1, 9), static function (array $batch) use ($block): array {
                return [['type' => 'block', 'file' => 'wire-test.php', 'block' => $block]];
            }),
            false,
        );
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
