<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Extraction;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\WindowPacker;

/**
 * E2 fix-1 (r1 MINOR-1) — the packer law at statement granularity.
 *
 * The three paths that used to silently discard mass on a SALVAGED parent:
 *  (i)  the shared-node wrapper re-check used to pop tails and lose them
 *       when the trimmed window emitted — now the popped tail seeds the
 *       NEXT window (emitted again) or, if it cannot clear the floor, is
 *       counted in droppedStatements;
 *  (ii) invalid line geometry used to break the whole run — now the
 *       offending endpoint is skipped, counted, and the run continues;
 *  (iii) a sub-floor remainder stays dropped by the documented boilerplate
 *       law — but it no longer ESCAPES the 0.6 ledger (the caller folds
 *       droppedStatements into oversizedDrops; BlockExtractorWindowTest
 *       pins that wiring end-to-end).
 *
 * The wrapper-inflation trigger (real nodeCount above the Σ the run
 * accumulated) is a shared-node artifact with honestly low natural
 * reachability, so these pins drive the law through the injectable $count
 * seam: one closure per statement run, counting a Stmt\Block wrapper as
 * though pooled sharing had inflated it beyond its parts — exactly the
 * re-check condition the pop path guards.
 */
final class WindowPackerTest extends TestCase
{
    /** @return list<Stmt> 13 plain assignments, real lines, real geometry. */
    private function statements(): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $code = "<?php\n";
        for ($i = 0; $i < 13; ++$i) {
            $code .= "\$x = {$i};\n";
        }
        return $parser->parse($code) ?? [];
    }

    /**
     * Counter simulating shared-node inflation: plain statements weigh 3,
     * a Stmt\Block wrapper recounts at 4 per member (Σ < wrapper).
     * @return \Closure(Node):int
     */
    private function inflatingCount(): \Closure
    {
        return static function (Node $n): int {
            return $n instanceof Stmt\Block ? 4 * count($n->stmts) : 3;
        };
    }

    /** @return \Closure(Node):int */
    private function plainCount(): \Closure
    {
        return static function (Node $n): int {
            return $n instanceof Stmt\Block ? 3 * count($n->stmts) + 1 : 3;
        };
    }

    /**
     * (i) law: a peeled tail is CARRIED, never discarded — with the floor
     * low enough the tail itself becomes the next window; four windows come
     * out of three accumulated runs and nothing is dropped.
     */
    public function testCarriedTailBecomesTheNextWindow(): void
    {
        $windows = [];
        $result = WindowPacker::emitWindowRuns(
            file: 'carry.php',
            namespace: null,
            class: null,
            name: 'carry',
            stmts: $this->statements(),
            minSize: 2,
            maxSize: 16,
            count: $this->inflatingCount(),
            sink: function (Block $b) use (&$windows): void { $windows[] = $b; },
        );

        $this->assertSame(0, $result['droppedStatements'], 'every statement found a window once the tail carries over');
        // Runs accumulate [5][5][3]; each 5-run wraps to 20 > 16 and peels
        // one tail; four members wrap to 16 and emit, and the carried tail
        // itself clears the floor (4 >= 2) — three trims + the clean tail
        // run make five windows in all: sizes 16,4,16,4,12.
        $this->assertSame(
            [['kind' => 'window', 'size' => 16], ['kind' => 'window', 'size' => 4], ['kind' => 'window', 'size' => 16],
             ['kind' => 'window', 'size' => 4], ['kind' => 'window', 'size' => 12]],
            array_map(fn(Block $w): array => ['kind' => $w->kind, 'size' => $w->size], $windows),
            'carry must re-enter the while-loop as the next run, in source order',
        );
        $this->assertSame(5, $result['emitted']);
        $this->assertSame(
            [[2, 5], [6, 6], [7, 10], [11, 11], [12, 14]],
            array_map(fn(Block $w): array => [(int) $w->range->start, (int) $w->range->end], $windows),
        );
    }

    /**
     * (i)+(iii) law: when a carried tail cannot clear the floor, it joins
     * the counted discards instead of evaporating under a break.
     */
    public function testCarriedTailBelowFloorIsCounted(): void
    {
        $windows = [];
        $result = WindowPacker::emitWindowRuns(
            file: 'trim.php',
            namespace: null,
            class: null,
            name: 'trim',
            stmts: $this->statements(),
            minSize: 8,
            maxSize: 16,
            count: $this->inflatingCount(),
            sink: function (Block $b) use (&$windows): void { $windows[] = $b; },
        );

        // Runs [5][5][3] again; each 5-run trims to a 16-node window and a
        // 4-node tail under the 8 floor. Three windows, two counted tails.
        $this->assertSame(3, $result['emitted']);
        $this->assertSame(2, $result['droppedStatements'], 'the pre-fix code discarded exactly this mass silently');
        $this->assertSame(
            [[2, 5], [7, 10], [12, 14]],
            array_map(fn(Block $w): array => [(int) $w->range->start, (int) $w->range->end], $windows),
            'window extents stay source-ordered and disjoint across carries',
        );
    }

    /**
     * (ii) law: an invalid-geometry HEAD is skipped and counted, and the
     * rest of the run still windows (pre-fix: the whole run died at the
     * first statement whose start line was 0).
     */
    public function testInvalidGeometryHeadIsSkippedAndRunContinues(): void
    {
        $stmts = $this->statements();
        $stmts[0]->setAttributes(['startLine' => 0, 'endLine' => 0]);

        $result = WindowPacker::emitWindowRuns(
            file: 'geo.php',
            namespace: null,
            class: null,
            name: 'geo',
            stmts: $stmts,
            minSize: 8,
            maxSize: 20,
            count: $this->plainCount(),
            sink: static function (Block $b): void {},
        );

        // Accumulation is geometry-blind: runs [6][6][1]. Run 1 sheds the
        // bad head (counted) and windows the remaining 5 (16 nodes); run 2
        // windows whole (19); run 3 is a single-statement 4-node fragment,
        // counted under the floor rule. Emitted 2 (old code: 1), drops 2
        // (old: 0 — both the head and the fragment vanished silently).
        $this->assertSame(2, $result['emitted']);
        $this->assertSame(2, $result['droppedStatements'], 'bad head + 1-statement sub-floor remainder, all visible');
    }

    /**
     * (ii) tail twin: an invalid END on the last member is skipped and
     * counted; the run keeps its valid body. A statement whose own line
     * geometry is invalid can never anchor a window, so this one drops
     * (it is not carried).
     */
    public function testInvalidGeometryTailIsSkippedAndCounted(): void
    {
        $stmts = $this->statements();
        $stmts[5]->setAttributes(['startLine' => 7, 'endLine' => 0]);

        $result = WindowPacker::emitWindowRuns(
            file: 'geo2.php',
            namespace: null,
            class: null,
            name: 'geo2',
            stmts: $stmts,
            minSize: 8,
            maxSize: 20,
            count: $this->plainCount(),
            sink: static function (Block $b): void {},
        );

        // Runs [6][6][1]: run 1 sheds the bad tail stmt (index 5, count 1),
        // windows [0..4] (16); run 2 whole (19); run 3 (index 12) is a
        // 4-node fragment under the floor, counted. Drops: bad tail + that
        // remainder = 2; emitted 2.
        $this->assertSame(2, $result['emitted']);
        $this->assertSame(2, $result['droppedStatements']);
    }

    /**
     * (iii) standalone: a wholly sub-floor parent still reports its mass at
     * statement granularity to the packer caller — BlockExtractor decides
     * the parent-granularity law (count once) for zero-window salvage.
     */
    public function testWhollySubFloorRunReportsStatementDrops(): void
    {
        $result = WindowPacker::emitWindowRuns(
            file: 'frag.php',
            namespace: null,
            class: null,
            name: 'frag',
            stmts: $this->statements(),
            minSize: 40,
            maxSize: 20,
            count: $this->plainCount(),
            sink: static function (Block $b): void {},
        );

        $this->assertSame(0, $result['emitted']);
        $this->assertSame(13, $result['droppedStatements'], 'nothing is checked against a floor without saying what stayed invisible');
    }
}
