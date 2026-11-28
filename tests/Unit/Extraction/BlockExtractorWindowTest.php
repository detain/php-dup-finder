<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Extraction;

use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;

/**
 * Item 1.2 (E2) — scan windows replace silent drops, and item 0.6's
 * counter states what stayed invisible anyway.
 *
 * Posture under test (Config::$windowOversized default = true, an
 * owner-ratified behavior change — exec order 2026-11-28, see the
 * Config.php property docblock): a block above max_block_size is cut into
 * maximal contiguous statement windows (each ≤ maxSize nodes, ≥ minSize,
 * kind 'window'); a parent from which NOTHING survives counts exactly once
 * in oversizedDrops(), while statements a SALVAGED parent's packing gave up
 * on count individually (E2 fix-1, r1 MINOR-1); and a corpus with no
 * oversized declaration changes not one byte of output.
 *
 * The windows ride the same fingerprint/cluster stream as real blocks —
 * that clustering leg is pinned in WindowClusteringTest; this file owns
 * the extraction contract.
 */
final class BlockExtractorWindowTest extends TestCase
{
    private const MAX = 800;
    private const MIN = 8;

    /** @param list<string> $allowedKinds @return list<Block> */
    private function extract(string $code, array $allowedKinds = [], bool $windowing = true, int $min = self::MIN, int $max = self::MAX): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast    = $parser->parse($code) ?? [];
        return (new BlockExtractor($min, $max, $allowedKinds, $windowing))->extract('virtual.php', $ast);
    }

    /** @return list<Block> */
    private function windowsOf(array $blocks): array
    {
        return array_values(array_filter($blocks, fn(Block $b) => $b->kind === BlockExtractor::WINDOW_KIND));
    }

    /** A body of $n plain assignments: each `$x = N;` is 3 AST nodes. */
    private function giantFunction(int $n, string $name = 'giant'): string
    {
        $lines = [];
        for ($i = 0; $i < $n; ++$i) {
            $lines[] = "    \$x = {$i};";
        }
        return "<?php\nfunction {$name}() {\n" . implode("\n", $lines) . "\n}\n";
    }

    // ── 1.2: the oversized body survives as windows ─────────────────────

    public function testOversizedFunctionIsCutIntoWindowsNotDropped(): void
    {
        // 400 assigns ≈ 1201 nodes > 800: historically this vanished.
        $blocks = $this->extract($this->giantFunction(400));

        $windows = $this->windowsOf($blocks);
        $this->assertNotEmpty($windows, 'an oversized function must reach the stream as windows');
        $this->assertCount(3, $windows, '1201 nodes pack into maximal runs of 266+266+68 assignments under the 800 cap');

        $this->assertSame([], array_values(array_filter(
            $blocks, fn(Block $b) => $b->kind === 'function' && $b->name === 'giant',
        )), 'the oversized whole never rides as a function block');

        $prevEnd = 0;
        foreach ($windows as $w) {
            $this->assertGreaterThanOrEqual(self::MIN, $w->size, 'windows clear the minSize floor');
            $this->assertLessThanOrEqual(self::MAX, $w->size, 'no window may exceed the ceiling — that is the whole point');
            $this->assertSame('giant', $w->name, 'provenance: the parent name rides on every window');
            $this->assertSame('virtual.php', $w->file);
            $this->assertGreaterThan($prevEnd, (int) $w->range->start, 'windows are ordered and disjoint');
            $prevEnd = (int) $w->range->end;
        }
    }

    public function testWindowsAreDeterministicAcrossRuns(): void
    {
        $code = $this->giantFunction(300);
        $a = $this->extract($code);
        $b = $this->extract($code);
        $this->assertSame(
            array_map(fn(Block $w) => [$w->kind, $w->size, (string) $w->range, $w->name], $this->windowsOf($a)),
            array_map(fn(Block $w) => [$w->kind, $w->size, (string) $w->range, $w->name], $this->windowsOf($b)),
            'cut boundaries must be content-stable — cache and cluster ids depend on it',
        );
    }

    public function testOversizedMethodKeepsClassProvenance(): void
    {
        $lines = [];
        for ($i = 0; $i < 400; ++$i) {
            $lines[] = "        \$x = {$i};";
        }
        $code = "<?php\nnamespace App\\Repo;\nclass Repo {\n    public function find() {\n" . implode("\n", $lines) . "\n    }\n}\n";

        $windows = $this->windowsOf($this->extract($code));
        $this->assertNotEmpty($windows);
        foreach ($windows as $w) {
            $this->assertSame('Repo', $w->class);
            $this->assertSame('App\\Repo', $w->namespace);
            $this->assertSame('find', $w->name);
        }
    }

    public function testNestedControlBlocksInsideOversizedBodyStillExtract(): void
    {
        // Today only the WHOLE oversized construct vanished — nested
        // small kind-nodes already survived. Windows must not lose that.
        $code = "<?php\nfunction mixed() {\n    if (\$a) { echo 1; echo 2; echo 3; echo 4; }\n" . str_repeat("    \$x = 1;\n", 400) . "    for (\$i = 0; \$i < 9; \$i++) { echo \$i; }\n}\n";
        $blocks = $this->extract($code);
        $kinds  = array_map(fn(Block $b) => $b->kind, $blocks);
        $this->assertContains('if', $kinds);
        $this->assertContains('for', $kinds);
        $this->assertContains(BlockExtractor::WINDOW_KIND, $kinds);
    }

    public function testCleanCorpusChangesNotOneByte(): void
    {
        $code = <<<'PHP'
        <?php
        function alpha() { for ($i = 0; $i < 10; $i++) { echo $i; } }
        class Repo {
            public function find($id) { if ($id) { return $id; } else { return null; } }
        }
        PHP;
        $on  = $this->extract($code, windowing: true);
        $off = $this->extract($code, windowing: false);
        $this->assertSame(
            array_map(fn(Block $b) => [$b->kind, $b->name, $b->size], $on),
            array_map(fn(Block $b) => [$b->kind, $b->name, $b->size], $off),
        );
        $this->assertSame([], $this->windowsOf($on), 'no oversized node — no window species');
        $this->assertNotContains('window', array_map(fn(Block $b) => $b->kind, $on));
    }

    // ── 0.6: what stays invisible is COUNTED ────────────────────────────

    public function testPostureOffDropsAndCountsEveryOversizedBlock(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($this->giantFunction(400)) ?? [];
        $blocks = $this->extract($this->giantFunction(400), windowing: false);
        $this->assertSame([], $this->windowsOf($blocks));
        // The count lives on the extractor; re-run the same extraction
        // through an instance we can interrogate.
        $ex = new BlockExtractor(self::MIN, self::MAX, [], false);
        $ex->extract('virtual.php', $ast);
        $this->assertSame(1, $ex->oversizedDrops());
        $ex->extract('tiny.php', $parser->parse("<?php function t() { echo 1; }\n") ?? []);
        $this->assertSame(0, $ex->oversizedDrops(), 'the counter states the MOST RECENT extract() only');
    }

    public function testSalvagedParentIsNotCountedAsDropped(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ex = new BlockExtractor(self::MIN, self::MAX, [], true);
        $blocks = $ex->extract('virtual.php', $parser->parse($this->giantFunction(400)) ?? []);
        $this->assertNotEmpty($this->windowsOf($blocks));
        $this->assertSame(0, $ex->oversizedDrops(), 'windows survived — nothing was dropped');
    }

    // ── E2 fix-1 (r1 MINOR-1): statement-granularity accounting ────────

    public function testSalvagedParentCountsStatementGranularDrops(): void
    {
        // Each `$x = N;` weighs 4 AST nodes, so runs pack 199 deep (796 ≤
        // the 799 budget; wrapper 797 ≤ 800). 399 assignments split into
        // 199 + 199 + 1: the tail statement is a 5-node wrapper, a
        // sub-floor fragment the packer gives up on. Pre-fix the parent
        // salvaged windows and that statement vanished with NO ledger
        // entry; the honesty law says what stays invisible gets counted.
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ex = new BlockExtractor(self::MIN, self::MAX, [], true);
        $blocks = $ex->extract('virtual.php', $parser->parse($this->giantFunction(399)) ?? []);
        $this->assertCount(2, $this->windowsOf($blocks), 'the two full runs still salvage');
        $this->assertSame(1, $ex->oversizedDrops(), 'the sub-floor remainder counts at statement granularity');
    }

    public function testGeometryInvalidStatementIsCountedAndRunContinues(): void
    {
        // First body statement stripped to invalid line geometry: pre-fix
        // the endpoint guard broke the WHOLE run (266 statements lost,
        // silently); now the bad head is skipped and counted, and the rest
        // of its run windows normally.
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($this->giantFunction(270)) ?? [];
        /** @var Stmt\Function_ $fn */
        $fn = $ast[0];
        $fn->stmts[0]->setAttributes(['startLine' => 0, 'endLine' => 0]);

        $ex = new BlockExtractor(self::MIN, self::MAX, [], true);
        $blocks = $ex->extract('virtual.php', $ast);
        $windows = $this->windowsOf($blocks);
        $this->assertCount(2, $windows, 'the run sheds the bad head and keeps windowing');
        $this->assertSame(
            4, (int) $windows[0]->range->start,
            'the emitted window starts at the FIRST VALID statement, not at the poisoned head',
        );
        $this->assertSame(1, $ex->oversizedDrops(), 'the skipped statement is counted, not evaporated');
    }

    public function testSalvagedScriptFoldCountsStatementGranularDrops(): void
    {
        // The fold twin: 399 bare top-level assignments window symmetrically
        // and carry the same 1-statement sub-floor remainder.
        $code = "<?php\n" . str_repeat("\$x = 1;\n", 399);
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($code) ?? [];

        $ex = new BlockExtractor(self::MIN, self::MAX, ['script'], true);
        $blocks = $ex->extract('virtual.php', $ast);
        $this->assertCount(2, $this->windowsOf($blocks));
        $this->assertSame(1, $ex->oversizedDrops(), 'fold leg and visitor leg share the ledger law');
    }

    public function testUnwindowableOversizedKindIsCounted(): void
    {
        // A top-level switch over the ceiling: no statement-list body to
        // cut, so the historical drop posture stands — but now LOUD.
        $cases = [];
        for ($i = 0; $i < 300; ++$i) {
            $cases[] = "    case {$i}: \$x = {$i}; break;";
        }
        $code = "<?php\nswitch (\$v) {\n" . implode("\n", $cases) . "\n}\n";
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ex = new BlockExtractor(self::MIN, self::MAX, [], true);
        $blocks = $ex->extract('virtual.php', $parser->parse($code) ?? []);
        $this->assertSame([], $this->windowsOf($blocks));
        $this->assertSame(1, $ex->oversizedDrops());
        $this->assertSame([], array_values(array_filter($blocks, fn(Block $b) => $b->kind === 'switch')));
    }

    public function testZeroWindowSurvivalCountsOnce(): void
    {
        // Oversized parent whose every cut fails the minSize floor:
        // a lone 3-node statement padded past 800 nodes? That is not
        // constructible — pad instead with an over-capacity single
        // statement (lone-giant) so NO run survives.
        $arr = '[' . implode(',', array_map(fn($i) => (string) $i, range(0, 900))) . ']';
        $code = "<?php\nfunction lone() {\n    \$x = {$arr};\n    \$y = 1;\n}\n";
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ex = new BlockExtractor(self::MIN, self::MAX, [], true);
        $blocks = $ex->extract('virtual.php', $parser->parse($code) ?? []);
        // The lone giant statement (>800 nodes) is skipped; the 3-node
        // tail fails minSize; zero windows survive => counted once.
        $this->assertSame([], $this->windowsOf($blocks), 'a 3-node tail is a fragment, not a block');
        $this->assertSame(1, $ex->oversizedDrops(), 'full invisibility is exactly one drop');
    }

    public function testOversizedClosuresWindowToo(): void
    {
        $lines = [];
        for ($i = 0; $i < 400; ++$i) {
            $lines[] = "        \$x = {$i};";
        }
        $code = "<?php\n\$f = function () {\n" . implode("\n", $lines) . "\n};\n";
        $windows = $this->windowsOf($this->extract($code));
        $this->assertNotEmpty($windows, 'closures ride the same window path');
        foreach ($windows as $w) {
            $this->assertLessThanOrEqual(self::MAX, $w->size);
            $this->assertGreaterThanOrEqual(self::MIN, $w->size);
        }
    }

    // ── kind contract ────────────────────────────────────────────────────

    public function testWindowKindIsNeverSelectableNorImplicit(): void
    {
        $this->assertSame('window', BlockExtractor::WINDOW_KIND);
        $this->assertNotContains(BlockExtractor::WINDOW_KIND, BlockExtractor::ALL_KINDS, 'a synthetic byproduct is not a user-facing kind');
    }

    public function testParentKindGateDominatesWindows(): void
    {
        // --kinds gates the PARENT construct: a window is only ever a cut
        // of something the filter already admitted.
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast    = $parser->parse($this->giantFunction(400)) ?? [];
        $ex = new BlockExtractor(self::MIN, self::MAX, ['method'], true);
        $blocks = $ex->extract('virtual.php', $ast);
        $this->assertSame([], $this->windowsOf($blocks), 'a filtered-out function yields no window byproduct');
        $this->assertSame(0, $ex->oversizedDrops(), 'the kind gate drops BEFORE the size gate — nothing to count');

        $ex2 = new BlockExtractor(self::MIN, self::MAX, ['function'], true);
        $this->assertNotEmpty($this->windowsOf($ex2->extract('virtual.php', $ast)), 'an admitted parent windows as expected');
    }

    // ── the script-fold twin (:136) ─────────────────────────────────────

    public function testOversizedScriptFoldWindowsAndCountsSymmetrically(): void
    {
        // 400 bare top-level assignments: with 'script' opted in these
        // fold into ONE oversized group — the twin drop site.
        $code = "<?php\n" . str_repeat("\$x = 1;\n", 400);
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($code) ?? [];

        $exOn = new BlockExtractor(self::MIN, self::MAX, ['script'], true);
        $blocks = $exOn->extract('virtual.php', $ast);
        $windows = $this->windowsOf($blocks);
        $this->assertNotEmpty($windows, 'the fold twin must window, not just the visitor');
        foreach ($windows as $w) {
            $this->assertLessThanOrEqual(self::MAX, $w->size);
        }
        $this->assertSame([], array_values(array_filter($blocks, fn(Block $b) => $b->kind === 'script')));
        $this->assertSame(0, $exOn->oversizedDrops());

        $exOff = new BlockExtractor(self::MIN, self::MAX, ['script'], false);
        $exOff->extract('virtual.php', $ast);
        $this->assertSame(1, $exOff->oversizedDrops(), 'posture-off parity with the visitor twin');
    }
}
