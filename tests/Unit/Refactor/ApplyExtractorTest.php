<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Refactor;

use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Util\LineRange;
use Phpdup\Parsing\AstParser;
use Phpdup\Normalization\Normalizer;
use Phpdup\Refactor\AntiUnifier;
use Phpdup\Refactor\ApplyExtractor;
use Phpdup\Refactor\Hole;
use Phpdup\Refactor\PlacementMiss;
use Phpdup\Refactor\SignatureBuilder;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;

final class ApplyExtractorTest extends TestCase
{
    public function testApplyWithNullGeneralizedAstReturnsEarly(): void
    {
        $cluster = new Cluster('c1', [new Block('f.php', new LineRange(1, 1), 'function', null, null, 'fn', new Node\Stmt\Nop())], 1.0, false);
        $cluster->generalizedAst = null;
        $cluster->signature = 'function test(): mixed';

        $extractor = new ApplyExtractor();
        $tmpDir = sys_get_temp_dir() . '/phpdup_apply_test_' . uniqid();
        @mkdir($tmpDir, 0o775, true);

        $extractor->apply($cluster, $tmpDir);

        $this->assertFileDoesNotExist($tmpDir . '/Refactored/c1.php');
    }

    public function testApplyWithSingleMemberReturnsEarly(): void
    {
        $parser = new AstParser();
        $stmts = $parser->parseCode('<?php function test($x) { return $x; }');
        $extracted = (new BlockExtractor(minSize: 1))->extract('test.php', $stmts);

        $cluster = new Cluster('c1', [$extracted[0]], 1.0, false);
        $cluster->generalizedAst = $extracted[0]->ast;
        $cluster->signature = 'function test($x): mixed';
        $cluster->holePaths = ['__P0' => ['stmts', 0, 'expr', 'name']];

        $extractor = new ApplyExtractor();
        $tmpDir = sys_get_temp_dir() . '/phpdup_apply_test_' . uniqid();
        @mkdir($tmpDir, 0o775, true);

        $extractor->apply($cluster, $tmpDir);

        $this->assertFileDoesNotExist($tmpDir . '/Refactored/c1.php');
    }

    public function testApplyWithEmptyHolePathsReturnsEarly(): void
    {
        $parser = new AstParser();
        $stmts = $parser->parseCode('<?php function test($x) { return $x; }');
        $extracted = (new BlockExtractor(minSize: 1))->extract('test.php', $stmts);

        $block1 = $extracted[0];
        $block2 = new Block('test2.php', new LineRange(1, 1), 'function', null, null, 'test', clone $block1->ast);

        $cluster = new Cluster('c1', [$block1, $block2], 0.9, false);
        $cluster->generalizedAst = $block1->ast;
        $cluster->signature = 'function test($x): mixed';
        $cluster->holePaths = [];

        $extractor = new ApplyExtractor();
        $tmpDir = sys_get_temp_dir() . '/phpdup_apply_test_' . uniqid();
        @mkdir($tmpDir, 0o775, true);

        $extractor->apply($cluster, $tmpDir);

        $this->assertFileDoesNotExist($tmpDir . '/Refactored/c1.php');
    }

    public function testExtractFunctionNameFromSignature(): void
    {
        $parser = new AstParser();
        $extractor = new BlockExtractor(minSize: 1);
        $blocks = [];

        foreach (['function notifyHigh($u, $s) { }', 'function notifyMid($u, $s) { }'] as $i => $code) {
            $stmts = $parser->parseCode('<?php ' . $code);
            $extracted = $extractor->extract("test{$i}.php", $stmts);
            $blocks[] = $extracted[0];
        }

        $cluster = new Cluster('TEST', $blocks, 0.8, false);
        $cluster->generalizedAst = $blocks[0]->ast;
        $cluster->signature = "function notifyByThreshold(\n    mixed \$threshold,\n): mixed";
        $cluster->holePaths = ['__P0' => ['stmts', 0, 'expr', 'name']];

        $antiUnifier = new AntiUnifier();
        $antiUnifier->unify($cluster);

        $ext = new ApplyExtractor();

        $refl = new \ReflectionMethod($ext, 'extractFunctionName');
        $name = $refl->invoke($ext, $cluster->signature);

        $this->assertSame('notifyByThreshold', $name);
    }

    public function testExtractParametersFromSignature(): void
    {
        $ext = new ApplyExtractor();
        $refl = new \ReflectionMethod($ext, 'extractParameters');

        $params = $refl->invoke($ext, "function notify(\n    int \$threshold,\n    string \$value,\n): mixed");
        $this->assertSame('int $threshold, string $value,', $params);

        $params2 = $refl->invoke($ext, "function simple(\$x): mixed");
        $this->assertSame('$x', $params2);
    }

    public function testBuildFunctionFileGeneratesValidPhp(): void
    {
        $parser = new AstParser();
        $extractor = new BlockExtractor(minSize: 1);
        $blocks = [];

        foreach (['function notifyHigh($u, $s) { if ($s > 10) { send("admin", $u); } }', 'function notifyMid($u, $s) { if ($s > 20) { send("moderator", $u); } }'] as $i => $code) {
            $stmts = $parser->parseCode('<?php ' . $code);
            $extracted = $extractor->extract("test{$i}.php", $stmts);
            $blocks[] = $extracted[0];
        }

        $cluster = new Cluster('TEST', $blocks, 0.8, false);
        (new AntiUnifier())->unify($cluster);
        $cluster->signature = "function notifyByThreshold(\n    int \$threshold,\n): mixed";

        $ext = new ApplyExtractor();
        $refl = new \ReflectionMethod($ext, 'buildFunctionFile');

        $modifiedAst = $cluster->generalizedAst;
        $content = $refl->invoke($ext, 'notifyByThreshold', $modifiedAst, $cluster);

        $this->assertStringContainsString('<?php', $content);
        $this->assertStringContainsString('declare(strict_types=1);', $content);
        $this->assertStringContainsString('namespace Refactored;', $content);
        $this->assertStringContainsString('function notifyByThreshold(', $content);
        $this->assertStringContainsString('Auto-generated abstraction', $content);
        $this->assertStringContainsString('REVIEW BEFORE MERGE', $content);

        $tmpFile = sys_get_temp_dir() . '/phpdup_test_fn_' . uniqid() . '.php';
        file_put_contents($tmpFile, $content);
        $output = shell_exec("php -l " . escapeshellarg($tmpFile) . " 2>&1");
        $this->assertStringContainsString('No syntax errors', $output);
        unlink($tmpFile);
    }

    public function testBodyVariableProjectionIsTheDeclaredParameterNotAVariableVariable(): void
    {
        // r2 N-1 family: suggestedName carries the display sigil
        // ('$threshold'). Fed raw into Variable() it printed `$$threshold`
        // — legal syntax that silently addressed a variable-variable instead
        // of the extracted function's own parameter.
        $parser = new AstParser();
        $stmts  = $parser->parseCode('<?php function test($threshold) { if ($threshold > 0) { return 1; } }');
        $func   = $stmts[0];
        $this->assertInstanceOf(Node\Stmt\Function_::class, $func);

        $hole = new \Phpdup\Refactor\Hole('__P0', 'identifier', ['$a', '$b']);
        $hole->suggestedName = '$threshold';

        $ext  = new ApplyExtractor();
        $refl = new \ReflectionMethod($ext, 'setNodeAtPath');
        $refl->invoke(
            $ext,
            $func,
            ['stmts', 0, 'cond', 'left'],
            new Variable(\Phpdup\Refactor\CodeParam::bareName($hole->suggestedName, $hole->placeholder))
        );

        $printer = new \PhpParser\PrettyPrinter\Standard();
        $code    = $printer->prettyPrint([$func]);
        $this->assertStringNotContainsString('$$', $code, 'no variable-variables in generated bodies');
        $this->assertStringContainsString('function test($threshold)', $code);
        $this->assertStringContainsString('$threshold > 0', $code, 'body addresses the declared parameter');
    }

    public function testSetNodeAtPathReplacesDeepNestedNode(): void
    {
        $parser = new AstParser();
        $stmts = $parser->parseCode('<?php function test($x) { if ($x > 0) { return 1; } }');
        $func = $stmts[0];
        $this->assertInstanceOf(Node\Stmt\Function_::class, $func);

        $ext = new ApplyExtractor();
        $refl = new \ReflectionMethod($ext, 'setNodeAtPath');

        $replaceWith = new Variable('myParam');
        // Path: stmts[0]=If_, If_.cond=Greater, Greater.left=Variable($x)
        // setNodeAtPath navigates to parent of target so it can assign:
        // loop stops at parent (Greater), then sets Greater.left
        $path = ['stmts', 0, 'cond', 'left'];
        $refl->invoke($ext, $func, $path, $replaceWith);

        // Verify the Greater node's left operand was replaced
        $ifNode = $func->stmts[0];
        $this->assertInstanceOf(Node\Stmt\If_::class, $ifNode);
        $greater = $ifNode->cond;
        $this->assertInstanceOf(Node\Expr\BinaryOp\Greater::class, $greater);
        $this->assertInstanceOf(Node\Expr\Variable::class, $greater->left);
        $this->assertSame('myParam', $greater->left->name);

        // Also verify pretty-printed output
        $printer = new \PhpParser\PrettyPrinter\Standard();
        $code = $printer->prettyPrint([$func]);
        $this->assertStringContainsString('$myParam', $code);
    }

    // ---------------------------------------------------------------
    // output-S44a (php-dup-finder-output-004, half A): single-pass
    // per-file rewrite, fail-loud, no @-swallow, named diagnostics.
    // ---------------------------------------------------------------

    /** @var list<string> scratch dirs removed in tearDown */
    private array $s44aScratch = [];

    protected function tearDown(): void
    {
        foreach ($this->s44aScratch as $dir) {
            self::rrmdir($dir);
        }
        $this->s44aScratch = [];
    }

    private function makeScratchDir(): string
    {
        $dir = sys_get_temp_dir() . '/phpdup_s44a_' . uniqid();
        @mkdir($dir, 0o775, true);
        $this->s44aScratch[] = $dir;
        return $dir;
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                self::rrmdir($path);
            } else {
                @chmod($path, 0o644);
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * One file, two same-shape clone functions, extracted from the REAL
     * disk path so every Block carries the span the rewrite must locate.
     *
     * @return array{0: string, 1: list<Block>} [file path, blocks]
     */
    private function twoMemberFile(string $dir): array
    {
        $src = "<?php\n"
            . "function notifyA(\$u, \$s) { if (\$s > 10) { send(\"admin\", \$u); } }\n"
            . "function notifyB(\$u, \$s) { if (\$s > 20) { send(\"moderator\", \$u); } }\n";
        $file = $dir . '/two.php';
        file_put_contents($file, $src);

        $stmts = (new AstParser())->parseCode($src);
        $this->assertNotNull($stmts);
        $blocks = [];
        foreach ((new BlockExtractor(minSize: 1))->extract($file, $stmts) as $block) {
            if ($block->kind === 'function') {
                $blocks[] = $block;
            }
        }
        $this->assertCount(2, $blocks);
        return [$file, $blocks];
    }

    /**
     * Cluster primed to pass every apply() guard (generalizedAst, signature,
     * >=2 members, non-empty holePaths) so execution reaches the rewrite
     * phase — the hole PATHS are belt content; holes stay empty, which is a
     * legal shape (zero-arg call replacement).
     *
     * @param list<Block> $members
     */
    private function applyReadyCluster(string $id, array $members): Cluster
    {
        $cluster = new Cluster($id, $members, 0.9, false);
        $cluster->generalizedAst = $members[0]->ast;
        $cluster->signature = 'function notifyByThreshold($u, $s): mixed';
        $cluster->holePaths = ['__P0' => ['stmts', 0]];
        return $cluster;
    }

    public function testTwoMembersInOneFileAreBothRewrittenFromASingleParse(): void
    {
        // The finding scenario: two members share one file. The old loop
        // re-read + re-parsed the file for EACH member AFTER writing the
        // previous member's rewrite — the second member searched its
        // ORIGINAL spans inside reshaped text, missed, and returned
        // silently: half a cluster applied, no one the wiser. One parse +
        // node-identity collection now rewrites BOTH in a single pass.
        $dir = $this->makeScratchDir();
        [$file, $blocks] = $this->twoMemberFile($dir);

        $extractor = new ApplyExtractor();
        $extractor->apply($this->applyReadyCluster('S44A', $blocks), $dir);

        $rewritten = (string) file_get_contents($file);
        // S46-era update (output-002 fix): the two name assertions here
        // pinned the DEFECT — whole declarations deleted and replaced by
        // bare top-level returns. Declarations now stay; the single-pass
        // law this test exists for is carried by the body-gone and
        // call-count assertions, unchanged.
        $this->assertStringContainsString('function notifyA', $rewritten);
        $this->assertStringContainsString('function notifyB', $rewritten);
        $this->assertStringNotContainsString('send("admin"', $rewritten);
        $this->assertStringNotContainsString('send("moderator"', $rewritten,
            'the stale-range miss class: member 2 must not survive unrewritten');
        $this->assertSame(2, substr_count($rewritten, 'Refactored\\notifyByThreshold('),
            'both member bodies now delegate to the extracted function');

        $recheck = (new AstParser())->parseCode($rewritten);
        $this->assertNotNull($recheck, 'the rewritten file is still parseable PHP');

        $this->assertFileExists($dir . '/Refactored/S44A.php');
    }

    public function testMissingMemberFileRefusesTheClusterWithADiagnostic(): void
    {
        // Silent return, old :332-334 → named refusal, zero writes.
        $dir = $this->makeScratchDir();
        [$file, $blocks] = $this->twoMemberFile($dir);
        $ghost = $dir . '/gone.php';

        $members = [
            $blocks[0],
            new Block($ghost, new LineRange(1, 1), 'function', null, null, 'notifyGone', clone $blocks[0]->ast),
        ];

        try {
            (new ApplyExtractor())->apply($this->applyReadyCluster('S44M', $members), $dir . '/out');
            $this->fail('expected a loud refusal for the missing member file');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refusing cluster S44M', $e->getMessage());
            $this->assertStringContainsString('does not exist', $e->getMessage());
            $this->assertStringContainsString($ghost, $e->getMessage());
            $this->assertStringContainsString('no files were written', $e->getMessage());
        }

        $this->assertStringContainsString('function notifyA', (string) file_get_contents($file),
            'the surviving member file stays untouched');
        $this->assertFileDoesNotExist($dir . '/out/Refactored/S44M.php');
    }

    public function testUnreadableMemberFileRefusesTheClusterWithADiagnostic(): void
    {
        // Silent return, old :337-339 (@file_get_contents) → the suppressed
        // diagnostic rides the exception; the @-swallow is gone.
        $dir = $this->makeScratchDir();
        [$file, $blocks] = $this->twoMemberFile($dir);
        chmod($file, 0o000);

        try {
            (new ApplyExtractor())->apply($this->applyReadyCluster('S44R', $blocks), $dir . '/out');
            $this->fail('expected a loud refusal for the unreadable member file');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refusing cluster S44R', $e->getMessage());
            $this->assertStringContainsString('could not be read', $e->getMessage());
            $this->assertStringContainsString($file, $e->getMessage());
        } finally {
            chmod($file, 0o644);
        }

        $this->assertStringContainsString('function notifyA', (string) file_get_contents($file));
        $this->assertFileDoesNotExist($dir . '/out/Refactored/S44R.php');
    }

    public function testUnparseableMemberFileRefusesTheClusterWithADiagnostic(): void
    {
        // Silent return, old :343-345 → the php-parser reason rides the
        // refusal instead of vanishing.
        $dir = $this->makeScratchDir();
        $file = $dir . '/broken.php';
        file_put_contents($file, "<?php function ( { ]\n");

        $members = [
            new Block($file, new LineRange(1, 1), 'function', null, null, 'g1', new Node\Stmt\Nop()),
            new Block($file, new LineRange(1, 1), 'function', null, null, 'g2', new Node\Stmt\Nop()),
        ];

        try {
            (new ApplyExtractor())->apply($this->applyReadyCluster('S44P', $members), $dir . '/out');
            $this->fail('expected a loud refusal for the unparseable member file');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refusing cluster S44P', $e->getMessage());
            $this->assertStringContainsString('could not be parsed', $e->getMessage());
            $this->assertStringContainsString($file, $e->getMessage());
            $this->assertStringContainsString('Syntax error', $e->getMessage(),
                'the php-parser diagnostic is carried through');
        }

        $this->assertStringContainsString('function (', (string) file_get_contents($file),
            'the unparseable file stays byte-identical');
        $this->assertFileDoesNotExist($dir . '/out/Refactored/S44P.php');
    }

    public function testStaleSpanMemberRefusesTheClusterWithADiagnostic(): void
    {
        // Silent return, old :349-351 (node not found) → named refusal
        // spelling out kind, span, file and member.
        $dir = $this->makeScratchDir();
        [$file, $blocks] = $this->twoMemberFile($dir);

        $members = [
            $blocks[0],
            new Block($file, new LineRange(40, 42), 'function', null, null, 'notifyMoved', clone $blocks[1]->ast),
        ];

        try {
            (new ApplyExtractor())->apply($this->applyReadyCluster('S44S', $members), $dir . '/out');
            $this->fail('expected a loud refusal for the stale-span member');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refusing cluster S44S', $e->getMessage());
            $this->assertStringContainsString('no function node at lines 40-42', $e->getMessage());
            $this->assertStringContainsString($file, $e->getMessage());
            $this->assertStringContainsString('notifyMoved', $e->getMessage());
            $this->assertStringContainsString('stale', $e->getMessage());
        }

        $this->assertStringContainsString('function notifyB', (string) file_get_contents($file),
            'the first member is NOT applied while a later one is missed');
        $this->assertFileDoesNotExist($dir . '/out/Refactored/S44S.php');
    }

    public function testTwoMembersOnTheSameNodeRefuseTheCluster(): void
    {
        // Count-verify law: one node cannot carry two members — refusing
        // beats double-claiming one replacement.
        $dir = $this->makeScratchDir();
        [$file, $blocks] = $this->twoMemberFile($dir);

        $first = $blocks[0];
        $twin = new Block($file, new LineRange($first->range->start, $first->range->end), 'function', null, null, 'notifyA', clone $first->ast);

        try {
            (new ApplyExtractor())->apply($this->applyReadyCluster('S44T', [$first, $twin]), $dir . '/out');
            $this->fail('expected a loud refusal for the two-members-one-node collision');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refusing cluster S44T', $e->getMessage());
            $this->assertStringContainsString('same node', $e->getMessage());
            $this->assertStringContainsString($file, $e->getMessage());
        }

        $this->assertStringContainsString('function notifyA', (string) file_get_contents($file));
        $this->assertFileDoesNotExist($dir . '/out/Refactored/S44T.php');
    }

    public function testWriteFailureThrowsInsteadOfBeingSwallowed(): void
    {
        // Old @file_put_contents on the member write — now loud. The plan
        // completes (read-only file is still readable and parseable), the
        // write refuses. Rollback of what landed before is half B territory
        // (output-S44b); this pin only proves the swallow is gone.
        $dir = $this->makeScratchDir();
        [$file, $blocks] = $this->twoMemberFile($dir);
        chmod($file, 0o444);

        try {
            (new ApplyExtractor())->apply($this->applyReadyCluster('S44W', $blocks), $dir . '/out');
            $this->fail('expected a loud failure when the member write is refused by the OS');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cluster S44W', $e->getMessage());
            $this->assertStringContainsString('writing ' . $file . ' failed', $e->getMessage());
            $this->assertStringContainsString('Permission denied', $e->getMessage());
        } finally {
            chmod($file, 0o644);
        }

        $this->assertStringContainsString('function notifyA', (string) file_get_contents($file),
            'the member file keeps its original bytes');
    }

    /**
     * One file, an outer function whose BODY DECLARES a second member
     * function — the overlap class the count-verify exists for. Both spans
     * are located in the single plan parse (distinct nodes, distinct
     * claimants), but the replacement pass never re-enters the outer
     * subtree it just replaced, so the inner target is unreachable: the
     * only honest outcome is a loud refusal.
     *
     * @return array{0: string, 1: list<Block>} [file path, blocks] outer-first
     */
    private function nestedMemberFile(string $dir): array
    {
        $src = "<?php\n"
            . "function outerFn(\$u, \$s) {\n"
            . "    if (\$s > 10) {\n"
            . "        send(\"admin\", \$u);\n"
            . "    }\n"
            . "    function innerFn(\$u, \$s) {\n"
            . "        return \$u;\n"
            . "    }\n"
            . "}\n";
        $file = $dir . '/nested.php';
        file_put_contents($file, $src);

        $stmts = (new AstParser())->parseCode($src);
        $this->assertNotNull($stmts);
        $blocks = [];
        foreach ((new BlockExtractor(minSize: 1))->extract($file, $stmts) as $block) {
            if ($block->kind === 'function') {
                $blocks[] = $block;
            }
        }
        $this->assertCount(2, $blocks);
        [$outer, $inner] = $blocks;
        $this->assertLessThan($inner->range->start, $outer->range->start);
        $this->assertGreaterThan($inner->range->end, $outer->range->end);
        return [$file, [$outer, $inner]];
    }

    public function testNestedMemberUnderAnotherRefusesTheCluster(): void
    {
        // LOW-2 pin (r1): the count-verify nested arm. r1 proved by mutant
        // that without it the composed file ships with the inner clone
        // silently ERASED — the exact partial-application class half A
        // kills. The r1 manual probe now rides an automated pin.
        $dir = $this->makeScratchDir();
        [$file, $members] = $this->nestedMemberFile($dir);

        try {
            (new ApplyExtractor())->apply($this->applyReadyCluster('S44N', $members), $dir . '/out');
            $this->fail('expected a loud refusal for the member nested under another member');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refusing cluster S44N', $e->getMessage());
            $this->assertStringContainsString('only 1 of 2 collected member nodes', $e->getMessage());
            $this->assertStringContainsString('nested under another member', $e->getMessage());
            $this->assertStringContainsString($file, $e->getMessage());
            $this->assertStringContainsString('no files were written', $e->getMessage());
        }

        $contents = (string) file_get_contents($file);
        $this->assertStringContainsString('function outerFn', $contents);
        $this->assertStringContainsString('function innerFn', $contents,
            'the inner clone survives intact — no silent erasure');
        $this->assertStringNotContainsString('Refactored\\notifyByThreshold(', $contents);
        $this->assertFileDoesNotExist($dir . '/out/Refactored/S44N.php');
    }

    public function testMkdirRefusalThrowsWithAFreshDiagnostic(): void
    {
        // LOW-2 pin (r1): the mkdir-refusal throw (the r1 audit's one
        // unpinned replacement arm). The plan itself succeeds — member
        // files are readable and parseable — but the output tree cannot be
        // created under a read-only parent, and the loud arm must carry a
        // FRESH diagnostic (LOW-1 clears the slot before the attempt).
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root bypasses DAC: a chmod-0555 directory stays writable');
        }
        $dir = $this->makeScratchDir();
        [$file, $blocks] = $this->twoMemberFile($dir);

        $locked = $this->makeScratchDir();
        chmod($locked, 0o555);
        $refactoredDir = $locked . '/out/Refactored';

        try {
            (new ApplyExtractor())->apply($this->applyReadyCluster('S44D', $blocks), $locked . '/out');
            $this->fail('expected a loud failure when the Refactored dir cannot be created');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refusing cluster S44D', $e->getMessage());
            $this->assertStringContainsString('could not create ' . $refactoredDir, $e->getMessage());
            $this->assertStringContainsString('Permission denied', $e->getMessage(),
                'the suppressed mkdir diagnostic rides the refusal, fresh');
            $this->assertStringContainsString('no files were written', $e->getMessage());
        } finally {
            chmod($locked, 0o775);
        }

        $this->assertStringContainsString('function notifyA', (string) file_get_contents($file),
            'the member file keeps its original bytes');
        $this->assertDirectoryDoesNotExist($refactoredDir);
    }

    // ---------------------------------------------------------------
    // output-S44b (php-dup-finder-output-004, half B): the write window
    // is a transaction — every touched path is snapshotted before the
    // first byte, every landing is read back byte-for-byte, and any
    // window failure restores the snapshots before it lets the loud
    // refusal leave. The returned member list is the reporter's
    // verified Applied tally.
    // ---------------------------------------------------------------

    /**
     * Two files, one clone member each — the cross-file shape the write
     * window lands in plan order (function artifact, member 1's file,
     * member 2's file), so a failure on member 2 has already dirtied the
     * artifact and member 1's file: exactly the torn state a rollback
     * must erase.
     *
     * @return array{0: string, 1: string, 2: list<Block>, 3: string, 4: string}
     *         [fileA, fileB, members, srcA, srcB]
     */
    private function twoFileMembers(string $dir): array
    {
        $srcA = "<?php\n"
            . "function notifyAlpha(\$u, \$s) { if (\$s > 10) { send(\"admin\", \$u); } }\n";
        $srcB = "<?php\n"
            . "function notifyBeta(\$u, \$s) { if (\$s > 20) { send(\"moderator\", \$u); } }\n";
        $fileA = $dir . '/alpha.php';
        $fileB = $dir . '/beta.php';
        file_put_contents($fileA, $srcA);
        file_put_contents($fileB, $srcB);
        return [
            $fileA,
            $fileB,
            [
                $this->cloneFunctionBlock($fileA, $srcA, 'notifyAlpha'),
                $this->cloneFunctionBlock($fileB, $srcB, 'notifyBeta'),
            ],
            $srcA,
            $srcB,
        ];
    }

    /** Extract one named function block from source bound to $file. */
    private function cloneFunctionBlock(string $file, string $src, string $name): Block
    {
        $stmts = (new AstParser())->parseCode($src);
        $this->assertNotNull($stmts);
        foreach ((new BlockExtractor(minSize: 1))->extract($file, $stmts) as $block) {
            if ($block->kind === 'function' && $block->name === $name) {
                return $block;
            }
        }
        $this->fail("fixture: no function {$name} extracted from {$file}");
    }

    public function testApplyReturnsTheVerifiedMemberFilesOnSuccess(): void
    {
        // The success contract the RefactorPatchReporter tally rides:
        // exactly the member files the window rewrote AND read back
        // byte-for-byte, in plan order — one entry per FILE even when a
        // file carries two members (the half-A grouping law).
        $dir = $this->makeScratchDir();
        [$fileA, $fileB, $members] = $this->twoFileMembers($dir);

        $returned = (new ApplyExtractor())->apply($this->applyReadyCluster('S44L', $members), $dir . '/out');

        $this->assertSame([$fileA, $fileB], $returned);
        $this->assertStringContainsString('Refactored\\notifyByThreshold(', (string) file_get_contents($fileA));
        $this->assertStringContainsString('Refactored\\notifyByThreshold(', (string) file_get_contents($fileB));
        $this->assertFileExists($dir . '/out/Refactored/S44L.php');

        $dir2 = $this->makeScratchDir();
        [$file, $blocks] = $this->twoMemberFile($dir2);
        $this->assertSame([$file], (new ApplyExtractor())->apply($this->applyReadyCluster('S44L2', $blocks), $dir2 . '/out'),
            'one file, two members: one verified path out');
    }

    public function testMidWindowWriteFailureRollsBackEveryEarlierLandingByteForByte(): void
    {
        // The torn-state class: the artifact and member 1's file landed,
        // member 2's write refuses (chmod-0444 DAC). Without the
        // transaction the tree stays half-rewritten; with it, member 1 is
        // restored to its EXACT pre-write bytes, the refused file is
        // untouched, the artifact is removed, and the directories the
        // window itself created vanish again.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root bypasses DAC: a chmod-0444 file stays writable');
        }
        $dir = $this->makeScratchDir();
        [$fileA, $fileB, $members, $srcA, $srcB] = $this->twoFileMembers($dir);
        chmod($fileB, 0o444);

        try {
            (new ApplyExtractor())->apply($this->applyReadyCluster('S44X', $members), $dir . '/out');
            $this->fail('expected the refused second write to abort the window loudly');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refusing cluster S44X', $e->getMessage());
            $this->assertStringContainsString('writing ' . $fileB . ' failed', $e->getMessage());
            $this->assertStringContainsString('Permission denied', $e->getMessage());
            $this->assertStringContainsString('write window rolled back', $e->getMessage());
        } finally {
            chmod($fileB, 0o644);
        }

        $this->assertSame($srcA, (string) file_get_contents($fileA),
            'the member file that HAD landed is restored to its exact pre-write bytes');
        $this->assertSame($srcB, (string) file_get_contents($fileB),
            'the refused file was never changed');
        $this->assertFileDoesNotExist($dir . '/out/Refactored/S44X.php',
            'the function artifact the window wrote is removed');
        $this->assertDirectoryDoesNotExist($dir . '/out',
            'directories the window created are removed while empty');
    }

    public function testLandingThatDoesNotByteMatchThePlanRefusesAndRollsBack(): void
    {
        // The injected-failure half of the acceptance: a disk that accepts
        // the write but never serves the new bytes (S44bLyingDisk). The
        // read-back verify is what makes the Applied tally VERIFIED —
        // without it this silent-swallow hardware lie would ship a
        // half-rewritten tree counted as applied.
        if (!in_array('s44b-lying-disk', stream_get_wrappers(), true)) {
            $this->assertTrue(@stream_wrapper_register('s44b-lying-disk', S44bLyingDisk::class));
        }
        S44bLyingDisk::$served = [];
        S44bLyingDisk::$recorded = [];

        $dir = $this->makeScratchDir();
        $srcA = "<?php\n"
            . "function notifyAlpha(\$u, \$s) { if (\$s > 10) { send(\"admin\", \$u); } }\n";
        $srcL = "<?php\n"
            . "function notifyBeta(\$u, \$s) { if (\$s > 20) { send(\"moderator\", \$u); } }\n";
        $fileA = $dir . '/alpha.php';
        file_put_contents($fileA, $srcA);
        $ghost = 's44b-lying-disk://beta.php';
        S44bLyingDisk::$served[$ghost] = $srcL;

        $members = [
            $this->cloneFunctionBlock($fileA, $srcA, 'notifyAlpha'),
            $this->cloneFunctionBlock($ghost, $srcL, 'notifyBeta'),
        ];

        try {
            (new ApplyExtractor())->apply($this->applyReadyCluster('S44V', $members), $dir . '/out');
            $this->fail('expected the byte-for-byte landing verify to refuse a disk that swallowed the rewrite');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refusing cluster S44V', $e->getMessage());
            $this->assertStringContainsString($ghost, $e->getMessage());
            $this->assertStringContainsString('did not land byte-for-byte', $e->getMessage());
            $this->assertStringContainsString('write window rolled back', $e->getMessage());
        }

        $this->assertStringContainsString('Refactored\\notifyByThreshold(', S44bLyingDisk::$recorded[$ghost] ?? '',
            'the window DID send the rewrite — the read-back verify is what caught the lie');
        $this->assertSame($srcA, (string) file_get_contents($fileA),
            'the landed sibling member is restored byte-for-byte');
        $this->assertFileDoesNotExist($dir . '/out/Refactored/S44V.php');
        $this->assertDirectoryDoesNotExist($dir . '/out');

        S44bLyingDisk::$served = [];
        S44bLyingDisk::$recorded = [];
    }

    public function testExistingFunctionArtifactThatCannotBeReadRefusesBeforeAnyWrite(): void
    {
        // The snapshot read is IO too: a stale artifact the run cannot
        // read back into the snapshot refuses the cluster BEFORE the
        // window opens — never an un-restorable overwrite.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root bypasses DAC: a chmod-000 file stays readable');
        }
        $dir = $this->makeScratchDir();
        [$fileA, $fileB, $members, $srcA, $srcB] = $this->twoFileMembers($dir);

        $artifactDir = $dir . '/out/Refactored';
        mkdir($artifactDir, 0o775, true);
        $artifact = $artifactDir . '/S44Q.php';
        file_put_contents($artifact, "<?php\n// previous run\n");
        chmod($artifact, 0o000);

        try {
            (new ApplyExtractor())->apply($this->applyReadyCluster('S44Q', $members), $dir . '/out');
            $this->fail('expected a loud refusal when the prior artifact cannot be snapshotted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refusing cluster S44Q', $e->getMessage());
            $this->assertStringContainsString('could not be read', $e->getMessage());
            $this->assertStringContainsString($artifact, $e->getMessage());
            $this->assertStringContainsString('no files were written', $e->getMessage());
        } finally {
            chmod($artifact, 0o644);
        }

        $this->assertSame($srcA, (string) file_get_contents($fileA));
        $this->assertSame($srcB, (string) file_get_contents($fileB));
        $this->assertSame("<?php\n// previous run\n", (string) file_get_contents($artifact),
            'the prior artifact keeps its bytes — nothing overwrote it');
    }

    public function testFunctionArtifactPathCollisionRefusesTheCluster(): void
    {
        // Belt: the snapshot map keys one path once — a member file that
        // doubles as the artifact destination would silently overwrite
        // one snapshot with the other. Refused before the window, with a
        // named diagnostic.
        $dir = $this->makeScratchDir();
        mkdir($dir . '/Refactored', 0o775, true);
        $collide = $dir . '/Refactored/COLLIDE.php';
        $src = "<?php\n"
            . "function notifyA(\$u, \$s) { if (\$s > 10) { send(\"admin\", \$u); } }\n"
            . "function notifyB(\$u, \$s) { if (\$s > 20) { send(\"moderator\", \$u); } }\n";
        file_put_contents($collide, $src);

        $members = [
            $this->cloneFunctionBlock($collide, $src, 'notifyA'),
            $this->cloneFunctionBlock($collide, $src, 'notifyB'),
        ];

        try {
            (new ApplyExtractor())->apply($this->applyReadyCluster('COLLIDE', $members), $dir);
            $this->fail('expected the artifact/member path collision to be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refusing cluster COLLIDE', $e->getMessage());
            $this->assertStringContainsString('also a member file path', $e->getMessage());
            $this->assertStringContainsString($collide, $e->getMessage());
        }

        $this->assertSame($src, (string) file_get_contents($collide),
            'the colliding file keeps its original bytes');
    }

    public function testRollbackReportsAnIncompleteRestoreHonestly(): void
    {
        // The belt inside the belt: when a RESTORE write itself fails
        // (disk holds bytes the snapshot says must go back, but the path
        // refuses writes), the refusal must say ROLLBACK INCOMPLETE and
        // name the path — never claim a clean tree it does not have.
        // Injected straight at rollbackWriteWindow (a real restore-write
        // failure cannot be staged without a production seam): the window
        // state is "this path diverged from its snapshot and will not
        // take the restore write back".
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root bypasses DAC: a chmod-0444 file stays writable');
        }
        $dir = $this->makeScratchDir();
        $stuck = $dir . '/stuck.php';
        file_put_contents($stuck, "ORIGINAL-ON-DISK-BUT-NOT-IN-THE-SNAPSHOT\n");
        chmod($stuck, 0o444);

        $cluster = new Cluster('RB', $this->twoMemberFile($dir)[1], 1.0, false);
        $rollback = new \ReflectionMethod(ApplyExtractor::class, 'rollbackWriteWindow');

        /** @var \RuntimeException $exception */
        $exception = $rollback->invoke(
            new ApplyExtractor(),
            $cluster,
            [$stuck => 'WHAT-THE-SNAPSHOT-CLAIMS-WAS-THERE-BEFORE'],
            $dir . '/out',
            $dir . '/out/Refactored',
            false,
            false,
            'writing ' . $stuck . ' failed (simulated window failure)',
        );
        $message = $exception->getMessage();

        $this->assertStringContainsString('refusing cluster RB', $message);
        $this->assertStringContainsString('ROLLBACK INCOMPLETE', $message);
        $this->assertStringContainsString($stuck . ' could not be restored', $message);
        $this->assertStringContainsString('Permission denied', $message, 'the restore failure carries its diagnostic');
        $this->assertStringContainsString('PARTIALLY rewritten', $message);
        chmod($stuck, 0o644);
    }

    // ---------------------------------------------------------------
    // output-S48 (php-dup-finder-output-005): hole placement lands ON the
    // slot its path names (no greedy one-level-too-deep descent), every
    // miss class refuses the cluster loudly instead of returning silently,
    // body-extraction failures refuse instead of printing comment stubs
    // into production files, and no planned buffer reaches the write
    // window until php-parser itself accepts it.
    // ---------------------------------------------------------------

    /** Parse code, demand a single top-level Function_, return it. */
    private function s48Function(string $code): Node\Stmt\Function_
    {
        $stmts = (new AstParser())->parseCode($code);
        $this->assertNotNull($stmts);
        $this->assertInstanceOf(Node\Stmt\Function_::class, $stmts[0]);
        return $stmts[0];
    }

    public function testTerminalPairPlacementReplacesTheSlotNotTheStatementsInnards(): void
    {
        // The probe's headline bug: for the standard 2-segment hole path
        // ['stmts', 1] the old walker descended INTO statement 1 and wrote
        // the hole inside that statement (or nowhere — its name-blind
        // first-array-subnode pick had nothing to find in a bare call).
        // The observed artifact body was `if ($s > 10) { $includeExtra }`:
        // the hole had eaten the statement, and a bare Variable printed
        // into a statement list has no terminator, so the generated file
        // failed php -l. A terminal (property, index) pair now replaces
        // the SLOT itself, siblings intact, wrapped into a real statement.
        $func = $this->s48Function('<?php function test($x) { first(); $knob = 1; third(); }');

        $ext = new ApplyExtractor();
        (new \ReflectionMethod($ext, 'setNodeAtPath'))->invoke($ext, $func, ['stmts', 1], new Variable('includeExtra'));

        $this->assertCount(3, $func->stmts, 'the statement list keeps its shape — only the named slot changed');
        $slot = $func->stmts[1];
        $this->assertInstanceOf(
            Node\Stmt\Expression::class,
            $slot,
            'a bare expression landing in a statement slot becomes a full statement',
        );
        $this->assertInstanceOf(Variable::class, $slot->expr);
        $this->assertSame('includeExtra', $slot->expr->name);

        $code = (new \PhpParser\PrettyPrinter\Standard())->prettyPrint([$func]);
        $this->assertStringContainsString('first();', $code);
        $this->assertStringContainsString('third();', $code, 'the neighbouring statements survive the placement');
        $this->assertStringNotContainsString('$knob', $code, 'the holed-out statement is gone, not gutted');
        $this->assertNotNull(
            (new AstParser())->parseCode("<?php\n" . $code),
            'the placed body re-parses — the old placement printed bodies php -l refuses',
        );
    }

    public function testExprSlotPlacementIsNotWrapped(): void
    {
        // The mirror of the wrap rule: statement slots wrap, EXPRESSION
        // slots never do — the reflection pin family that has always
        // replaced `cond.left` in place must keep landing a bare Variable
        // (wrapping here would rewrite `$x > 0` into an expression of the
        // wrong class).
        $func = $this->s48Function('<?php function test($x) { if ($x > 0) { return 1; } }');

        $ext = new ApplyExtractor();
        (new \ReflectionMethod($ext, 'setNodeAtPath'))->invoke($ext, $func, ['stmts', 0, 'cond', 'left'], new Variable('threshold'));

        $if = $func->stmts[0];
        $this->assertInstanceOf(Node\Stmt\If_::class, $if);
        $greater = $if->cond;
        $this->assertInstanceOf(Node\Expr\BinaryOp\Greater::class, $greater);
        $this->assertInstanceOf(Variable::class, $greater->left);
        $this->assertNotInstanceOf(Node\Stmt\Expression::class, $greater->left);
        $this->assertSame('threshold', $greater->left->name);
    }

    public function testBareIndexHolePathRefusesPlacement(): void
    {
        // A path element must be a property NAME; a bare int addresses
        // nothing. The old getArrayChild walked subnodes blindly instead.
        $func = $this->s48Function('<?php function t($x) { one(); two(); }');

        $ext = new ApplyExtractor();
        $this->expectException(PlacementMiss::class);
        $this->expectExceptionMessage('position 0 is array index 1');
        (new \ReflectionMethod($ext, 'setNodeAtPath'))->invoke($ext, $func, [1], new Variable('v'));
    }

    public function testUnknownPropertyHolePathRefusesPlacement(): void
    {
        $func = $this->s48Function('<?php function t($x) { one(); two(); }');

        $ext = new ApplyExtractor();
        $this->expectException(PlacementMiss::class);
        $this->expectExceptionMessage('has no subnode "nope"');
        (new \ReflectionMethod($ext, 'setNodeAtPath'))->invoke($ext, $func, ['stmts', 0, 'nope'], new Variable('v'));
    }

    public function testWholeArrayTerminalRefusesPlacement(): void
    {
        // ['stmts'] with no index names the ARRAY, not a slot — assigning
        // through it would replace the whole statement list (and a
        // Variable into a typed array property throws a raw TypeError).
        $func = $this->s48Function('<?php function t($x) { one(); two(); }');

        $ext = new ApplyExtractor();
        $this->expectException(PlacementMiss::class);
        $this->expectExceptionMessage('addresses the whole array property');
        (new \ReflectionMethod($ext, 'setNodeAtPath'))->invoke($ext, $func, ['stmts'], new Variable('v'));
    }

    public function testDescentThroughNullRefusesPlacement(): void
    {
        // If_->else is null here; the old walker returned silently, so a
        // hole the template could not address simply vanished.
        $func = $this->s48Function('<?php function t($x) { if ($x) { one(); } }');

        $ext = new ApplyExtractor();
        $this->expectException(PlacementMiss::class);
        $this->expectExceptionMessage('cannot descend through Stmt_If_->else');
        (new \ReflectionMethod($ext, 'setNodeAtPath'))->invoke($ext, $func, ['stmts', 0, 'else', 'stmts'], new Variable('v'));
    }

    public function testStaleHoleIndexPathRefusesTheClusterWithZeroWrites(): void
    {
        // output-005 end to end: the generalized AST carries a hole whose
        // path points at body position 5, but the template has one
        // statement. The old walker returned silently and apply() wrote
        // every file anyway — the un-hole body shipped. Now the refusal
        // names the path and NO file exists anywhere: placement misses
        // land before the plan, so the out tree is never even created.
        $dir = $this->makeScratchDir();
        [$file, $blocks] = $this->twoMemberFile($dir);

        $cluster = new Cluster('S48STALE', $blocks, 0.9, false);
        $cluster->generalizedAst = $blocks[0]->ast;
        $cluster->signature = 'function notifyByThreshold($u, $s): mixed';
        $cluster->holes = [new Hole('__P0', 'identifier', ['send("admin", $u)', ''])];
        $cluster->holePaths = ['__P0' => ['stmts', 5]];

        try {
            (new ApplyExtractor())->apply($cluster, $dir . '/out');
            $this->fail('expected the stale hole path to refuse the cluster');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refusing cluster S48STALE', $e->getMessage());
            $this->assertStringContainsString('hole placement failed at stmts/5', $e->getMessage());
            $this->assertStringContainsString('has no index 5', $e->getMessage());
            $this->assertStringContainsString('disagree', $e->getMessage());
            $this->assertStringContainsString('no files were written', $e->getMessage());
        }

        $this->assertStringContainsString('function notifyA', (string) file_get_contents($file),
            'the member file keeps its original bytes');
        $this->assertDirectoryDoesNotExist($dir . '/out',
            'the refusal fires before the plan, not inside the window');
    }

    public function testMemberRewriteThatDoesNotReParseRefusesBeforeTheWindowOpens(): void
    {
        // S46-era update (output-002 fix): this pin's ORIGINAL corruption
        // witness WAS the defect — a ClassMethod replaced by a bare
        // `return ...;` statement inside a class body (text php-parser
        // refuses). The declaration-keeping fix makes that composition
        // legal, so the witness retired by design. The guard arm and its
        // named diagnostic keep the pin name and get a direct reflection
        // injection of the same illegal text into the landing map — the
        // seam-injection precedent the S44b ROLLBACK-INCOMPLETE pin uses.
        // The end-to-end "refuses before the window opens" ORDER stays
        // pinned byte-untouched by S48G on the artifact side.
        $dir = $this->makeScratchDir();
        $srcA = "<?php\nclass A {\n    public function m(\$u, \$s) { if (\$s > 10) { send(\"admin\", \$u); } }\n}\n";
        $fileA = $dir . '/a.php';
        file_put_contents($fileA, $srcA);

        $artifactPath = $dir . '/out/Refactored/S48M.php';
        $bootstrapPath = $dir . '/out/Refactored/_autoload.php';
        $landings = [
            // artifact and stub land FIRST in the window order — they
            // parse here, so any refusal can only be the member buffer;
            $artifactPath => "<?php\ndeclare(strict_types=1);\nnamespace Refactored;\nfunction ok(): mixed { return 1; }\n",
            $bootstrapPath => "<?php\ndeclare(strict_types=1);\n",
            // the exact byte shape the pre-S46 defect produced:
            $fileA => "<?php\nclass A {\n    return \\Refactored\\ok();\n}\n",
        ];

        $cluster = new Cluster('S48M', [$this->s48MethodBlock($fileA, $srcA)], 0.9, false);

        try {
            (new \ReflectionMethod(ApplyExtractor::class, 'assertBuffersReparse'))
                ->invoke(new ApplyExtractor(), $cluster, $landings, $bootstrapPath);
            $this->fail('expected the crafted un-re-parseable member buffer to be refused by the guard');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refusing cluster S48M', $e->getMessage());
            $this->assertStringContainsString('the rewrite of ' . $fileA . ' does not re-parse', $e->getMessage());
            $this->assertStringContainsString('no files were written', $e->getMessage());
            $this->assertStringContainsString('Syntax error', $e->getMessage(),
                'the php-parser reason rides the refusal (the witness text is the T_RETURN the grammar refuses)');
        }

        $this->assertSame($srcA, (string) file_get_contents($fileA),
            'the member file keeps its original bytes');
        $this->assertDirectoryDoesNotExist($dir . '/out');
    }

    /** Extract the (single) method block from source bound to $file. */
    private function s48MethodBlock(string $file, string $src): Block
    {
        $stmts = (new AstParser())->parseCode($src);
        $this->assertNotNull($stmts);
        foreach ((new BlockExtractor(minSize: 1))->extract($file, $stmts) as $block) {
            if ($block->kind === 'method') {
                return $block;
            }
        }
        $this->fail("fixture: no method block extracted from {$file}");
    }

    public function testGeneratedFunctionFileThatDoesNotReParseRefusesBeforeTheWindowOpens(): void
    {
        // The artifact side of the same belt: an illegal call name (two
        // bare words) injected into the generalized template prints
        // `bad name(...)` into the function file. The generator cannot
        // emit text php-parser refuses to read — refusal names the
        // artifact and the member files stay byte-identical.
        $dir = $this->makeScratchDir();
        [$file, $blocks] = $this->twoMemberFile($dir);

        $template = Normalizer::deepClone($blocks[0]->ast);
        $this->assertInstanceOf(Node\Stmt\Function_::class, $template);
        $if = $template->stmts[0];
        $this->assertInstanceOf(Node\Stmt\If_::class, $if);
        $call = $if->stmts[0];
        $this->assertInstanceOf(Node\Stmt\Expression::class, $call);
        $this->assertInstanceOf(Node\Expr\FuncCall::class, $call->expr);
        $call->expr->name = new Node\Name('bad name');

        $cluster = new Cluster('S48G', $blocks, 0.9, false);
        $cluster->generalizedAst = $template;
        $cluster->signature = 'function notifyByThreshold($u, $s): mixed';
        $cluster->holePaths = ['__P0' => ['stmts', 0]];

        try {
            (new ApplyExtractor())->apply($cluster, $dir . '/out');
            $this->fail('expected the unparseable artifact buffer to be refused before the window');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refusing cluster S48G', $e->getMessage());
            $this->assertStringContainsString('the generated function file does not re-parse', $e->getMessage());
            $this->assertStringContainsString('no files were written', $e->getMessage());
        }

        $this->assertStringContainsString('function notifyA', (string) file_get_contents($file),
            'member files keep their bytes — the refusal came before the window');
        $this->assertDirectoryDoesNotExist($dir . '/out');
    }

    public function testBodyExtractionFailureRefusesTheClusterInsteadOfWritingAStub(): void
    {
        // The old extractBodyLines() swallowed every Throwable into
        // `// (body extraction failed)` — a comment stub emitted AS THE
        // BODY of a production file claiming to be an abstraction, plus a
        // `// (unsupported node type ...)` stub for the same quiet-lie
        // treatment. A shape with no function-body reading now refuses the
        // cluster loudly, and neither stub string exists in the extractor
        // source any more — not just bypassed, deleted.
        $dir = $this->makeScratchDir();
        [$file, $blocks] = $this->twoMemberFile($dir);

        $cluster = new Cluster('S48B', $blocks, 0.9, false);
        $cluster->generalizedAst = new Node\Identifier('cleanup');
        $cluster->signature = 'function notifyByThreshold($u, $s): mixed';
        $cluster->holePaths = ['__P0' => ['stmts', 0]];

        try {
            (new ApplyExtractor())->apply($cluster, $dir . '/out');
            $this->fail('expected a loud refusal where body extraction cannot produce a body');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refusing cluster S48B', $e->getMessage());
            $this->assertStringContainsString('body extraction failed', $e->getMessage());
            $this->assertStringContainsString('unsupported node type', $e->getMessage());
            $this->assertStringContainsString('no files were written', $e->getMessage());
        }

        $this->assertStringContainsString('function notifyA', (string) file_get_contents($file));
        $this->assertDirectoryDoesNotExist($dir . '/out');

        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Refactor/ApplyExtractor.php');
        $this->assertStringNotContainsString('(body extraction failed)', $source);
        $this->assertStringNotContainsString('(unsupported node type', $source);
    }

    public function testOptionalBlockHoleLandsAsAValidStatementAndTheArtifactReParses(): void
    {
        // The finding end to end, on the REAL generator chain: two files
        // carrying the same-named function whose bodies differ by one
        // trailing statement. AntiUnifier surfaces that difference as an
        // optional_block hole at exactly the 2-segment path ['stmts', i]
        // the greedy old descent broke (the probe's `if (...) { $knob }`
        // body failed php -l; the same statement-eaten shape appears here
        // as a bare un-terminated `$knob` printed into the function
        // body). SignatureBuilder names the parameters. The artifact now
        // keeps `send($s);` intact, lands the knob as its own terminated
        // statement, and passes `php -l`.
        $dir = $this->makeScratchDir();
        $srcA = "<?php\nfunction logIt(\$s) { send(\$s); cleanup(); }\n";
        $srcB = "<?php\nfunction logIt(\$s) { send(\$s); }\n";
        $fileA = $dir . '/a.php';
        $fileB = $dir . '/b.php';
        file_put_contents($fileA, $srcA);
        file_put_contents($fileB, $srcB);

        $blockA = $this->cloneFunctionBlock($fileA, $srcA, 'logIt');
        $blockB = $this->cloneFunctionBlock($fileB, $srcB, 'logIt');

        $cluster = new Cluster('S48O', [$blockA, $blockB], 0.9, false);
        (new AntiUnifier())->unify($cluster);
        (new SignatureBuilder())->buildSignature($cluster);

        $optionals = array_values(array_filter(
            $cluster->holes,
            static fn (Hole $hole): bool => $hole->kind === 'optional_block',
        ));
        $this->assertNotEmpty($optionals,
            'fixture: unify must surface the trailing-statement difference as an optional_block hole');

        $this->assertNotNull($cluster->signature);
        $returned = (new ApplyExtractor())->apply($cluster, $dir . '/out');
        $this->assertSame([$fileA, $fileB], $returned);

        $artifact = $dir . '/out/Refactored/S48O.php';
        $this->assertFileExists($artifact);
        $body = (string) file_get_contents($artifact);
        $this->assertStringNotContainsString('cleanup', $body,
            'the optional statement was holed out of the body, not eaten along with its neighbour');
        $this->assertMatchesRegularExpression('/send\(\$s\);/', $body, 'the aligned body survives');
        $this->assertMatchesRegularExpression('/^\s*\$[A-Za-z_][A-Za-z0-9_]*;\s*$/m', $body,
            'the knob landed as its own terminated statement (the old placement printed a bare, unparseable line)');

        $lint = shell_exec('php -l ' . escapeshellarg($artifact) . ' 2>&1');
        $this->assertStringContainsString('No syntax errors', (string) $lint,
            'the generated function file re-parses — the acceptance bar output-005 failed');

        // S45-era update (output-001 fix): the call-arg pin that lived here
        // asserted the OLD literal-0 argument ('the optional hole still
        // rides the literal arg', struck with S48's own note that 'S45 owns
        // argument work'). Arguments are now this member's own: the optional
        // knob passes true for the member that CARRIED the segment and false
        // for the one that did not — a literal 0 binding into a `bool`
        // parameter is a TypeError class under strict_types. The S46-era
        // half (declaration stays and delegates; body-gone pinned directly)
        // is kept exactly as struck-in-place then.
        // S49b-era update (output-006, disclosed era-flip #4): both logIt
        // members declare the same formal $s, so the promoted shared formal
        // now LEADS the delegating call — the signature's first parameter
        // line is $s (the artifact re-parse legs above vouch for it) and
        // the call passes the member's own local. The knob semantics are
        // byte-untouched: true for the carrier, false for the non-carrier.
        $knobbed = [$fileA => 'true', $fileB => 'false'];
        foreach ($knobbed as $memberFile => $expectedKnob) {
            $rewritten = (string) file_get_contents($memberFile);
            $this->assertStringContainsString('function logIt', $rewritten);
            $this->assertStringNotContainsString('send(', $rewritten,
                'the member body was replaced by the delegating call');
            $this->assertMatchesRegularExpression(
                '/Refactored\\\\[A-Za-z_][A-Za-z0-9_]*\(\$s, ' . $expectedKnob . '\);/',
                $rewritten,
                'the member rewrite passes the promoted local $s, then its own knob value (' . $expectedKnob . ')',
            );
            $this->assertNotNull((new AstParser())->parseCode($rewritten), 'the rewritten member still parses');
        }
    }

    // ---------------------------------------------------------------
    // output-S46 (php-dup-finder-output-002): the member rewrite KEEPS the
    // declaration and delegates from inside its body. Function_/ClassMethod
    // members get [Stmt\Return_(call)] as the new statement list, a `: void`
    // member a bare Stmt\Expression (a void contract may not return the
    // abstraction's value). A Closure/arrow member — which has no
    // declaration to keep — and a bodyless method (abstract/interface) are
    // refused loudly with zero files touched.
    // ---------------------------------------------------------------

    /** Extract the first block of $kind (optionally named $name) from $src. */
    private function s46Block(string $file, string $src, string $kind, ?string $name = null): Block
    {
        $stmts = (new AstParser())->parseCode($src);
        $this->assertNotNull($stmts);
        foreach ((new BlockExtractor(minSize: 1))->extract($file, $stmts) as $block) {
            if ($block->kind === $kind && ($name === null || $block->name === $name)) {
                return $block;
            }
        }
        $this->fail(sprintf(
            'fixture: no %s block%s extracted from %s',
            $kind,
            $name !== null ? " named {$name}" : '',
            $file,
        ));
    }

    /** Re-parse a rewritten file and return the named class's named method. */
    private function s46MethodOf(string $path, string $class, string $method): Node\Stmt\ClassMethod
    {
        $stmts = (new AstParser())->parseCode((string) file_get_contents($path));
        $this->assertNotNull($stmts, "fixture: {$path} re-parses");
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Class_ && $stmt->name?->toString() === $class) {
                $found = $stmt->getMethod($method);
                $this->assertNotNull($found, "fixture: class {$class} still declares {$method}()");
                return $found;
            }
        }
        $this->fail("fixture: no class {$class} in {$path}");
    }

    /** Assert `php -l` accepts a file (child process, same bar the finding names). */
    private function assertPhpLints(string $path): void
    {
        $lint = shell_exec('php -l ' . escapeshellarg($path) . ' 2>&1');
        $this->assertStringContainsString('No syntax errors', (string) $lint, "php -l on {$path}");
    }

    public function testClassMethodMemberKeepsItsDeclarationAndReturnsTheCall(): void
    {
        // The finding's headline shape: the acceptance said the old output
        // died with php's own `unexpected token "return"` — the class body
        // got a top-level return where the method used to be. Now the
        // method stays, signature intact, and delegates from INSIDE its
        // body (the shape the dry-run hint always promised).
        $dir = $this->makeScratchDir();
        $srcA = "<?php\nclass LedgerA {\n    public function post(\$v, \$w) { logEntry(\$v); sealOne(\$w); }\n}\n";
        $srcB = "<?php\nclass LedgerB {\n    public function post(\$v, \$w) { logEntry(\$v); }\n}\n";
        $fileA = $dir . '/la.php';
        $fileB = $dir . '/lb.php';
        file_put_contents($fileA, $srcA);
        file_put_contents($fileB, $srcB);

        $members = [
            $this->s46Block($fileA, $srcA, 'method', 'post'),
            $this->s46Block($fileB, $srcB, 'method', 'post'),
        ];
        $returned = (new ApplyExtractor())->apply($this->applyReadyCluster('S46RET', $members), $dir . '/out');

        $this->assertSame([$fileA, $fileB], $returned);
        $this->assertPhpLints($fileA);

        // AST check, acceptance's exact wording: no top-level Stmt\Return_
        // anywhere in the class body; the method is still a ClassMethod
        // with its name, both params, public visibility — and one
        // delegating statement.
        $stmts = (new AstParser())->parseCode((string) file_get_contents($fileA));
        $this->assertNotNull($stmts);
        $class = null;
        foreach ($stmts as $stmt) {
            $this->assertNotInstanceOf(
                Node\Stmt\Return_::class,
                $stmt,
                'a file-top-level Return_ is the deleted-declaration shape',
            );
            if ($stmt instanceof Node\Stmt\Class_) {
                $class = $stmt;
            }
        }
        $this->assertInstanceOf(Node\Stmt\Class_::class, $class);
        foreach ($class->stmts as $bodyStmt) {
            $this->assertNotInstanceOf(
                Node\Stmt\Return_::class,
                $bodyStmt,
                'no top-level Return_ in the class body (the output-002 corruption)',
            );
        }

        $post = $this->s46MethodOf($fileA, 'LedgerA', 'post');
        $this->assertCount(2, $post->params, 'the signature survives the rewrite');
        $this->assertTrue($post->isPublic());
        $this->assertCount(1, $post->stmts, 'the body is exactly the delegation');
        $this->assertInstanceOf(Node\Stmt\Return_::class, $post->stmts[0]);
        $call = $post->stmts[0]->expr;
        $this->assertInstanceOf(Node\Expr\FuncCall::class, $call);
        $this->assertInstanceOf(Node\Name\FullyQualified::class, $call->name,
            'the delegation calls through the same fully qualified identity as the hint (S47 law)');
        $this->assertSame('Refactored\\notifyByThreshold', $call->name->toString());
        $this->assertStringNotContainsString('sealOne(', (string) file_get_contents($fileB),
            'member 2 rewrote too (the single-pass law, kept)');
        $this->assertPhpLints($fileB);
    }

    public function testVoidMethodMemberDelegatesWithoutReturning(): void
    {
        // A `: void` declaration may not return the abstraction's value —
        // the plan's "or Stmt\Expression for void" arm. php -l cannot tell
        // the difference (both parse), so the pin is the AST shape itself.
        $dir = $this->makeScratchDir();
        $srcA = "<?php\nclass SinkA {\n    public function flushAll(): void { logEntry(); sealOne(); }\n}\n";
        $srcB = "<?php\nclass SinkB {\n    public function flushAll(): void { logEntry(); }\n}\n";
        $fileA = $dir . '/sa.php';
        $fileB = $dir . '/sb.php';
        file_put_contents($fileA, $srcA);
        file_put_contents($fileB, $srcB);

        $members = [
            $this->s46Block($fileA, $srcA, 'method', 'flushAll'),
            $this->s46Block($fileB, $srcB, 'method', 'flushAll'),
        ];
        (new ApplyExtractor())->apply($this->applyReadyCluster('S46VOID', $members), $dir . '/out');

        $flush = $this->s46MethodOf($fileA, 'SinkA', 'flushAll');
        $this->assertInstanceOf(Node\Identifier::class, $flush->returnType, 'the : void return type survives');
        $this->assertSame('void', (string) $flush->returnType->name);
        $this->assertCount(1, $flush->stmts);
        $this->assertInstanceOf(Node\Stmt\Expression::class, $flush->stmts[0],
            'a void member delegates through a bare statement — no Return_');
        $this->assertInstanceOf(Node\Expr\FuncCall::class, $flush->stmts[0]->expr);
        $this->assertPhpLints($fileA);

        $other = $this->s46MethodOf($fileB, 'SinkB', 'flushAll');
        $this->assertInstanceOf(Node\Stmt\Expression::class, $other->stmts[0]);
    }

    public function testFileScopeFunctionMemberKeepsItsDeclarationAndDelegates(): void
    {
        // The function half of the defect: the old rewrite left the file
        // with a top-level `return \Refactored\…;` in place of the
        // function — the declaration vanished, and at include time the
        // return ran before every later statement. Structural proof here;
        // the executable external-caller smoke lives in the new
        // tests/Integration/ApplyExtractionTest.php harness.
        $dir = $this->makeScratchDir();
        $src = "<?php\n"
            . "function performAudit() { logEntry(); sealOne(); }\n"
            . "function laterOne() { echo 'L1'; }\n";
        $file = $dir . '/audit.php';
        $fileOther = $dir . '/other.php';
        file_put_contents($file, $src);
        file_put_contents($fileOther, $src);

        $auditA = $this->s46Block($file, $src, 'function', 'performAudit');
        $auditB = $this->s46Block($fileOther, $src, 'function', 'performAudit');

        (new ApplyExtractor())->apply($this->applyReadyCluster('S46FN', [$auditA, $auditB]), $dir . '/out');

        $this->assertPhpLints($file);
        $stmts = (new AstParser())->parseCode((string) file_get_contents($file));
        $this->assertNotNull($stmts);
        $names = [];
        foreach ($stmts as $stmt) {
            $this->assertNotInstanceOf(Node\Stmt\Return_::class, $stmt,
                'no include-time top-level return survives in the rewritten file');
            if ($stmt instanceof Node\Stmt\Function_) {
                $names[] = $stmt->name->toString();
                if ($stmt->name->toString() === 'performAudit') {
                    $this->assertCount(1, $stmt->stmts, 'the body is exactly the delegation');
                    $this->assertInstanceOf(Node\Stmt\Return_::class, $stmt->stmts[0]);
                    $this->assertInstanceOf(Node\Expr\FuncCall::class, $stmt->stmts[0]->expr);
                }
            }
        }
        $this->assertSame(['performAudit', 'laterOne'], $names,
            'the rewritten function AND the later declaration are both still in the file');
    }

    public function testClosureMemberRefusesTheClusterWithItsFilesUntouched(): void
    {
        // "Closure/arrow members: REFUSE (typed reason)": a closure has no
        // declaration to keep — the only node-local rewrite replaces the
        // callable VALUE with the result of an immediate call at the
        // definition site. The cluster is refused before anything else
        // happens (script posture); both files keep their bytes.
        $dir = $this->makeScratchDir();
        $srcA = "<?php\n\$cbA = function () { logEntry(); sealOne(); };\n";
        $srcB = "<?php\n\$cbB = function () { logEntry(); };\n";
        $fileA = $dir . '/ca.php';
        $fileB = $dir . '/cb.php';
        file_put_contents($fileA, $srcA);
        file_put_contents($fileB, $srcB);

        $members = [
            $this->s46Block($fileA, $srcA, 'closure'),
            $this->s46Block($fileB, $srcB, 'closure'),
        ];
        $this->assertSame('closure', $members[0]->kind, 'fixture: BlockExtractor really mints closure members');

        try {
            (new ApplyExtractor())->apply($this->applyReadyCluster('S46CLO', $members), $dir . '/out');
            $this->fail('expected the closure member to be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refusing cluster S46CLO', $e->getMessage());
            $this->assertStringContainsString('closure member', $e->getMessage());
            $this->assertStringContainsString('has no declaration to delegate through', $e->getMessage());
            $this->assertStringContainsString('no files were written', $e->getMessage());
        }

        $this->assertSame($srcA, (string) file_get_contents($fileA));
        $this->assertSame($srcB, (string) file_get_contents($fileB));
        $this->assertDirectoryDoesNotExist($dir . '/out');
    }

    public function testArrowMemberRefusesTheClusterToo(): void
    {
        // The 'arrow' twin of the refusal (ALL_KINDS mints it; engine-057
        // exempts it from minSize — the taxonomy must cover it by name,
        // not by accident of size).
        $dir = $this->makeScratchDir();
        $srcA = "<?php\n\$quickA = fn () => logEntry();\n";
        $srcB = "<?php\n\$quickB = fn () => sealOne();\n";
        $fileA = $dir . '/aa.php';
        $fileB = $dir . '/ab.php';
        file_put_contents($fileA, $srcA);
        file_put_contents($fileB, $srcB);

        $members = [
            $this->s46Block($fileA, $srcA, 'arrow'),
            $this->s46Block($fileB, $srcB, 'arrow'),
        ];
        $this->assertSame('arrow', $members[0]->kind, 'fixture: BlockExtractor really mints arrow members');

        try {
            (new ApplyExtractor())->apply($this->applyReadyCluster('S46ARR', $members), $dir . '/out');
            $this->fail('expected the arrow member to be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refusing cluster S46ARR', $e->getMessage());
            $this->assertStringContainsString('arrow member', $e->getMessage());
            $this->assertStringContainsString('no files were written', $e->getMessage());
        }

        $this->assertSame($srcA, (string) file_get_contents($fileA));
        $this->assertSame($srcB, (string) file_get_contents($fileB));
        $this->assertDirectoryDoesNotExist($dir . '/out');
    }

    public function testAbstractMethodMemberRefusesTheCluster(): void
    {
        // Extending the refusal taxonomy to the bodyless ClassMethod:
        // there is no body to replace with a delegating one, and the
        // composition this would print (`abstract public function x() {
        // return ...; }`) is text php-parser ACCEPTS while php -l refuses
        // — the S48 re-parse guard is blind to it (verified on the vendor
        // grammar), so the only honest posture is a typed refusal at
        // collection, before any replacement is built.
        $dir = $this->makeScratchDir();
        $srcA = "<?php\nclass ReadyA {\n    public function warmUp() { logEntry(); }\n}\n";
        $srcB = "<?php\nclass ReadyB {\n    abstract public function drain();\n}\n";
        $fileA = $dir . '/ra.php';
        $fileB = $dir . '/rb.php';
        file_put_contents($fileA, $srcA);
        file_put_contents($fileB, $srcB);

        $members = [
            $this->s46Block($fileA, $srcA, 'method', 'warmUp'),
            $this->s46Block($fileB, $srcB, 'method', 'drain'),
        ];

        try {
            (new ApplyExtractor())->apply($this->applyReadyCluster('S46ABS', $members), $dir . '/out');
            $this->fail('expected the abstract member to be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refusing cluster S46ABS', $e->getMessage());
            $this->assertStringContainsString('abstract or interface-typed', $e->getMessage());
            $this->assertStringContainsString('drain', $e->getMessage());
            $this->assertStringContainsString($fileB, $e->getMessage());
            $this->assertStringContainsString('no files were written', $e->getMessage());
        }

        $this->assertSame($srcA, (string) file_get_contents($fileA),
            'the concrete member the plan already handled is NOT applied while the abstract one is refused');
        $this->assertSame($srcB, (string) file_get_contents($fileB));
        $this->assertDirectoryDoesNotExist($dir . '/out');
    }

    public function testInterfaceMethodMemberRefusesTheCluster(): void
    {
        // The interface twin of the same arm (stmts === null is the
        // predicate; the entry point differs enough to name it).
        $dir = $this->makeScratchDir();
        $srcA = "<?php\nclass ReadyA {\n    public function warmUp() { logEntry(); }\n}\n";
        $srcB = "<?php\ninterface DrainI {\n    public function drain();\n}\n";
        $fileA = $dir . '/ia.php';
        $fileB = $dir . '/ib.php';
        file_put_contents($fileA, $srcA);
        file_put_contents($fileB, $srcB);

        $members = [
            $this->s46Block($fileA, $srcA, 'method', 'warmUp'),
            $this->s46Block($fileB, $srcB, 'method', 'drain'),
        ];

        try {
            (new ApplyExtractor())->apply($this->applyReadyCluster('S46IFACE', $members), $dir . '/out');
            $this->fail('expected the interface member to be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refusing cluster S46IFACE', $e->getMessage());
            $this->assertStringContainsString('abstract or interface-typed', $e->getMessage());
            $this->assertStringContainsString($fileB, $e->getMessage());
        }

        $this->assertSame($srcA, (string) file_get_contents($fileA));
        $this->assertSame($srcB, (string) file_get_contents($fileB));
    }

    // ---------------------------------------------------------------
    // output-S45 (php-dup-finder-output-001): the delegating call carries
    // THIS member's own observed expressions, in the parameter order the
    // emitted signature declares — not hole-parameter names (undefined in
    // member scope), not hole-creation order (SignatureBuilder appends the
    // optional_block knobs last), not one identical argument list shared
    // by every member, and never an invented value for a row this member
    // does not have.
    // ---------------------------------------------------------------

    /** The delegating FuncCall inside the named rewritten function. */
    private function s45CallOf(string $path, string $fn): Node\Expr\FuncCall
    {
        $stmts = (new AstParser())->parseCode((string) file_get_contents($path));
        $this->assertNotNull($stmts, "fixture: {$path} re-parses");
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Function_ && $stmt->name->toString() === $fn) {
                $this->assertNotEmpty($stmt->stmts, "fixture: function {$fn} kept its body");
                $inner = $stmt->stmts[0];
                $this->assertTrue($inner instanceof Node\Stmt\Return_ || $inner instanceof Node\Stmt\Expression,
                    "fixture: {$fn}'s body starts with a return or expression statement");
                $expr = $inner->expr;
                $this->assertInstanceOf(Node\Expr\FuncCall::class, $expr,
                    "fixture: {$fn}'s body delegates through a call");
                return $expr;
            }
        }
        $this->fail("fixture: no function {$fn} in {$path}");
    }

    public function testMembersPassTheirOwnObservedLiteralsAtTheCallSites(): void
    {
        // The finding, end to end on the REAL generator chain: guardA/guardB
        // differ in ONE call argument; AntiUnifier holes the argument itself
        // (row = its printed literal). The first life of the builder passed
        // the hole PARAMETER name at every site — live undefined-variable
        // code — and its second passed one identical argument list to both.
        $dir = $this->makeScratchDir();
        $srcA = "<?php\nfunction guardA(\$x) { audit('alpha', \$x); }\n";
        $srcB = "<?php\nfunction guardB(\$x) { audit('beta', \$x); }\n";
        $fileA = $dir . '/a.php';
        $fileB = $dir . '/b.php';
        file_put_contents($fileA, $srcA);
        file_put_contents($fileB, $srcB);

        $cluster = new Cluster('S45LIT', [
            $this->cloneFunctionBlock($fileA, $srcA, 'guardA'),
            $this->cloneFunctionBlock($fileB, $srcB, 'guardB'),
        ], 0.9, false);
        (new AntiUnifier())->unify($cluster);
        (new SignatureBuilder())->buildSignature($cluster);
        $this->assertSame(
            [['stmts', 0, 'expr', 'args', 0, 'value']],
            array_values($cluster->holePaths),
            'fixture: the chain holes the differing argument itself, so the rows are expressions',
        );

        (new ApplyExtractor())->apply($cluster, $dir . '/out');

        // S49b-era update (output-006, disclosed era-flip #1): guardA/guardB
        // both declare the formal $x, so the promoted shared formal leads
        // the call and the observed literal follows — two arguments, arity
        // matching the two-line signature. The defect this test pins (the
        // hole PARAMETER name riding the call, and one shared argument list)
        // stays dead exactly as before.
        $expected = [$fileA => ['guardA', 'alpha'], $fileB => ['guardB', 'beta']];
        foreach ($expected as $file => [$fn, $value]) {
            $call = $this->s45CallOf($file, $fn);
            $this->assertCount(2, $call->args, 'promoted shared formal + one hole, one argument each');
            $this->assertInstanceOf(Node\Expr\Variable::class, $call->args[0]->value,
                'the promoted position passes the member\'s own local $x');
            $this->assertSame('x', $call->args[0]->value->name);
            $this->assertInstanceOf(Node\Scalar\String_::class, $call->args[1]->value);
            $this->assertSame($value, $call->args[1]->value->value);
            $this->assertPhpLints($file);
        }
        $this->assertPhpLints($dir . '/out/Refactored/S45LIT.php');

        // The two defects that lived here are gone for good: the call no
        // longer passes the hole parameter name (undefined in the member),
        // and no literal-0 placeholder rides an argument slot.
        foreach ([$fileA, $fileB] as $file) {
            $rewritten = (string) file_get_contents($file);
            $this->assertStringNotContainsString('$__P0', $rewritten);
            $this->assertStringNotContainsString('(0)', $rewritten);
        }
    }

    public function testIntegerArgumentsAreCanonicalIntNodesAndTheWholeRewriteRuns(): void
    {
        // Three acceptance legs in one honest run: an int row lands as a
        // Node\Scalar\Int_ (the canonical v5 class — the old arm rode
        // Node\Scalar\LNumber, a deprecated alias the v5 parser never
        // mints), every rewritten buffer php -l's, and under
        // declare(strict_types=1) the generated+rewritten code EXECUTES
        // with no TypeError: wrapA delegates wrap(3), wrapB wrap(7).
        $dir = $this->makeScratchDir();
        $srcA = "<?php\ndeclare(strict_types=1);\nfunction wrapA(\$v) { \$s = 1; return mult(3); }\n";
        $srcB = "<?php\ndeclare(strict_types=1);\nfunction wrapB(\$v) { \$s = 1; return mult(7); }\n";
        $fileA = $dir . '/a.php';
        $fileB = $dir . '/b.php';
        file_put_contents($fileA, $srcA);
        file_put_contents($fileB, $srcB);

        $cluster = new Cluster('S45RUN', [
            $this->cloneFunctionBlock($fileA, $srcA, 'wrapA'),
            $this->cloneFunctionBlock($fileB, $srcB, 'wrapB'),
        ], 0.9, false);
        (new AntiUnifier())->unify($cluster);
        (new SignatureBuilder())->buildSignature($cluster);
        $this->assertCount(1, $cluster->holes, 'fixture: the identical statements stay out of the holes');
        $this->assertSame('literal', $cluster->holes[0]->kind);
        $this->assertSame(['3', '7'], $cluster->holes[0]->observedValues,
            'fixture: number rows are printed literals, not quoted strings');

        (new ApplyExtractor())->apply($cluster, $dir . '/out');
        $this->assertPhpLints($fileA);
        $this->assertPhpLints($fileB);
        $this->assertPhpLints($dir . '/out/Refactored/S45RUN.php');

        // S49b-era update (output-006, disclosed era-flip #2): wrapA/wrapB
        // both declare the formal $v, so the promoted local leads the call
        // and the canonical Int_ rides position 1. The three legs this test
        // owns — Int_ (never the deprecated LNumber alias), php -l, and the
        // executed strict_types smoke — all survive the promotion verbatim.
        $callA = $this->s45CallOf($fileA, 'wrapA');
        $callB = $this->s45CallOf($fileB, 'wrapB');
        $this->assertCount(2, $callA->args, 'promoted $v + the int hole');
        $this->assertInstanceOf(Node\Expr\Variable::class, $callA->args[0]->value);
        $this->assertSame('v', $callA->args[0]->value->name);
        $argA = $callA->args[1]->value;
        $argB = $callB->args[1]->value;
        $this->assertSame(Node\Scalar\Int_::class, get_class($argA),
            'the deprecated LNumber alias must never ride new output');
        $this->assertSame(3, $argA->value);
        $this->assertSame(7, $argB->value);

        // Runtime smoke: a real child process, exact stdout — any warning
        // or TypeError fails the match. mult() is global and unqualified,
        // so it resolves through the Refactored namespace fallback.
        $run = $dir . '/run.php';
        file_put_contents($run, "<?php\nfunction mult(int \$p): int { return \$p * 2; }\n"
            . 'require ' . var_export($dir . '/out/Refactored/_autoload.php', true) . ";\n"
            . 'require ' . var_export($fileA, true) . ";\n"
            . 'require ' . var_export($fileB, true) . ";\n"
            . "echo wrapA(0) . ',' . wrapB(0) . PHP_EOL;\n");
        $out = (string) shell_exec('php ' . escapeshellarg($run) . ' 2>&1');
        $this->assertSame("6,14\n", $out);
    }

    public function testArgumentsFollowTheEmittedSignatureEvenWhenHolesWereCreatedOutOfOrder(): void
    {
        // SignatureBuilder declares required parameters first and appends
        // the optional_block knobs LAST. Building the call in hole-CREATION
        // order would bind each value to the wrong parameter whenever the
        // holes were not created required-first — the finding's silent data
        // corruption. This cluster is primed in exactly that worst order
        // (knob hole first), and its required rows are NAMES: a bare name
        // row reads back as a constant fetch, which would fatal at the call
        // site, so it must bind as the string it printed to.
        $dir = $this->makeScratchDir();
        [$file, $blocks] = $this->twoMemberFile($dir);

        $cluster = new Cluster('S45ORD', $blocks, 0.9, false);
        $cluster->generalizedAst = $blocks[0]->ast;
        $cluster->holes = [
            new Hole('__O0', 'optional_block', ['send("admin", $u);', '']),
            new Hole('__P0', 'name', ['Alpha\Thing', 'Beta\Thing']),
        ];
        (new SignatureBuilder())->buildSignature($cluster);
        $cluster->holePaths = ['__P0' => ['stmts', 0, 'stmts', 0, 'expr', 'args', 0, 'value']];

        (new ApplyExtractor())->apply($cluster, $dir . '/out');

        // S49b-era update (output-006, disclosed era-flip #3): the
        // twoMemberFile members both declare ($u, $s), so the promoted
        // shared formals lead both the signature and every call — arity is
        // 4 everywhere (2 promoted + required hole + appended knob). The
        // ordering defect this test owns stays pinned verbatim: the knob
        // hole was CREATED first but must ride LAST, after the required
        // hole that was created second.
        $perMember = [
            'notifyA' => ['Alpha\Thing', 'true'],
            'notifyB' => ['Beta\Thing', 'false'],
        ];
        foreach ($perMember as $fn => [$row, $knob]) {
            $call = $this->s45CallOf($file, $fn);
            $this->assertCount(4, $call->args, '2 promoted + required hole + knob — arity matches the signature');
            $this->assertSame(['u', 's'], [$call->args[0]->value->name, $call->args[1]->value->name],
                'the promoted shared formals ride the member\'s own locals, in declaration order');
            $this->assertInstanceOf(Node\Scalar\String_::class, $call->args[2]->value,
                'a name row binds as a string, never as a constant fetch');
            $this->assertSame($row, $call->args[2]->value->value);
            $this->assertInstanceOf(Node\Expr\ConstFetch::class, $call->args[3]->value);
            $this->assertSame($knob, $call->args[3]->value->name->toLowerString(),
                'the knob is switched by THIS member (first row present, second missing)');
        }
        $this->assertPhpLints($file);

        // Structural equality with the emitted signature, read from the
        // artifact itself (nikic printer, not regex): required parameter
        // first, bool knob second with its false default. The artifact
        // declares inside namespace Refactored — descend into it.
        $stmts = (new AstParser())->parseCode((string) file_get_contents($dir . '/out/Refactored/S45ORD.php'));
        $this->assertNotNull($stmts);
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Namespace_) {
                $stmts = $stmt->stmts;
                break;
            }
        }
        $function = null;
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Function_) {
                $function = $stmt;
            }
        }
        $this->assertInstanceOf(Node\Stmt\Function_::class, $function);
        $this->assertCount(4, $function->params, 'call arity == signature arity');
        $this->assertSame(['u', 's', '__P0', '__O0'], array_map(
            static fn (Node\Param $param): string => $param->var->name,
            $function->params,
        ), 'promoted shared formals lead, required hole next, knob last (S49b order law)');
        $this->assertInstanceOf(Node\Identifier::class, $function->params[3]->type);
        $this->assertSame('bool', $function->params[3]->type->name);
        $this->assertInstanceOf(Node\Expr\ConstFetch::class, $function->params[3]->default);
        $this->assertSame('false', $function->params[3]->default->name->toLowerString());
    }

    public function testRequiredHoleWithoutAnObservedRowForAMemberRefusesTheCluster(): void
    {
        // A MISSING row (canonical '', S27) for a REQUIRED hole means this
        // member had nothing at that position. The old builder passed the
        // parameter name regardless — an invented argument; the loud
        // zero-write taxonomy refuses instead.
        $dir = $this->makeScratchDir();
        [$file, $blocks] = $this->twoMemberFile($dir);

        $cluster = new Cluster('S45MIS', $blocks, 0.9, false);
        $cluster->generalizedAst = $blocks[0]->ast;
        $cluster->holes = [new Hole('__P0', 'literal', ["'admin'", ''])];
        (new SignatureBuilder())->buildSignature($cluster);
        $cluster->holePaths = ['__P0' => ['stmts', 0, 'stmts', 0, 'expr', 'args', 0, 'value']];
        $untouched = (string) file_get_contents($file);

        try {
            (new ApplyExtractor())->apply($cluster, $dir . '/out');
            $this->fail('expected a loud refusal for the member with no observed value');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refusing cluster S45MIS', $e->getMessage());
            $this->assertStringContainsString('__P0', $e->getMessage());
            $this->assertStringContainsString('notifyB', $e->getMessage());
            $this->assertStringContainsString('no observed value', $e->getMessage());
            $this->assertStringContainsString('no files were written', $e->getMessage());
        }

        $this->assertSame($untouched, (string) file_get_contents($file),
            'the member file stays byte-identical through the refusal');
        $this->assertFileDoesNotExist($dir . '/out/Refactored/S45MIS.php');
    }

    public function testRequiredHoleRowThatDoesNotReadAsOneExpressionRefusesTheCluster(): void
    {
        // The AntiUnifier repr fallback prints '<ShortType>' for nodes it
        // cannot render; if such a row (or any statement-shaped fragment)
        // reaches a REQUIRED hole slot, there is no honest argument to
        // pass. Refuse with the php-parser reason, touch nothing.
        $dir = $this->makeScratchDir();
        [$file, $blocks] = $this->twoMemberFile($dir);

        $cluster = new Cluster('S45JNK', $blocks, 0.9, false);
        $cluster->generalizedAst = $blocks[0]->ast;
        $cluster->holes = [new Hole('__P0', 'literal', ["'admin'", '<ShortType>'])];
        (new SignatureBuilder())->buildSignature($cluster);
        $cluster->holePaths = ['__P0' => ['stmts', 0, 'stmts', 0, 'expr', 'args', 0, 'value']];
        $untouched = (string) file_get_contents($file);

        try {
            (new ApplyExtractor())->apply($cluster, $dir . '/out');
            $this->fail('expected a loud refusal for the unparseable observed row');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refusing cluster S45JNK', $e->getMessage());
            $this->assertStringContainsString('__P0', $e->getMessage());
            $this->assertStringContainsString('does not read as a single expression', $e->getMessage());
            $this->assertStringContainsString('no files were written', $e->getMessage());
        }

        $this->assertSame($untouched, (string) file_get_contents($file));
        $this->assertFileDoesNotExist($dir . '/out/Refactored/S45JNK.php');
    }

    // ---------------------------------------------------------------
    // output-S49b (php-dup-finder-output-006, joint landing with S49a):
    // the promoted shared formals reach the CODE. The planning walk now
    // reads, parses, locates (and hydrates) every member before deriving
    // the promotion set ONCE — with the same reserved-name projection the
    // signature renderer used (SignatureBuilder::renderedHoleVars) — and
    // every delegating call prepends the member's own locals. Artifact
    // parameters (promoted + required holes + knobs) and call arguments
    // (promoted locals + observed rows + knobs) agree by construction;
    // these pins vouch for each arm of that equality, the apply door's
    // promotion semantics, the honest-evidence rule (unloaded ASTs are
    // hydrated from the located declaration, never guessed from null),
    // and the executed strict_types behaviour the finding demanded.
    // ---------------------------------------------------------------

    /**
     * The finding's own shape (PDREF S1 acceptance): two members sharing
     * their formals ($value, $user) and differing only in one call
     * argument, unified and signature-built through the REAL chain.
     *
     * @return array{0: Cluster, 1: string, 2: string} cluster, fileA, fileB
     */
    private function s49SharedFormalCluster(string $dir, string $id): array
    {
        $srcA = "<?php\ndeclare(strict_types=1);\nfunction mailHigh(\$value, \$user) { return dispatch(\$user, 'high', \$value); }\n";
        $srcB = "<?php\ndeclare(strict_types=1);\nfunction mailLow(\$value, \$user) { return dispatch(\$user, 'low', \$value); }\n";
        $fileA = $dir . '/a.php';
        $fileB = $dir . '/b.php';
        file_put_contents($fileA, $srcA);
        file_put_contents($fileB, $srcB);

        $cluster = new Cluster($id, [
            $this->cloneFunctionBlock($fileA, $srcA, 'mailHigh'),
            $this->cloneFunctionBlock($fileB, $srcB, 'mailLow'),
        ], 0.9, false);
        (new AntiUnifier())->unify($cluster);
        (new SignatureBuilder())->buildSignature($cluster);
        return [$cluster, $fileA, $fileB];
    }

    /** The Function_ node inside a generated artifact (namespace descent). */
    private function s49ArtifactFunction(string $path): Node\Stmt\Function_
    {
        $stmts = (new AstParser())->parseCode((string) file_get_contents($path));
        $this->assertNotNull($stmts, "fixture: artifact {$path} re-parses");
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Namespace_) {
                $stmts = $stmt->stmts;
                break;
            }
        }
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Function_) {
                return $stmt;
            }
        }
        $this->fail("fixture: no function in artifact {$path}");
    }

    /** Executed smoke over a shared-formal rewrite: exact child stdout. */
    private function s49RunSmoke(string $dir, string $bootstrapPath): string
    {
        $run = $dir . '/run.php';
        file_put_contents($run, "<?php\nfunction dispatch(\$u, \$k, \$v) { return \$u . ':' . \$k . ':' . \$v; }\n"
            . 'require ' . var_export($bootstrapPath, true) . ";\n"
            . 'require ' . var_export($dir . '/a.php', true) . ";\n"
            . 'require ' . var_export($dir . '/b.php', true) . ";\n"
            . "echo mailHigh('V', 'U') . ',' . mailLow('W', 'T') . PHP_EOL;\n");
        return (string) shell_exec('php ' . escapeshellarg($run) . ' 2>&1');
    }

    public function testPromotedFormalsLandInTheGeneratedSignatureAndAtEveryCallSite(): void
    {
        // The finding end to end (php-dup-finder-output-006 verbatim:
        // "generated function declares the intersected formals, each
        // rewritten call site passes the member's own locals"), executed
        // under strict_types — pre-S49a the abstraction read $value/$user
        // as FREE VARIABLES and every rewritten call was live broken code.
        $dir = $this->makeScratchDir();
        [$cluster, $fileA, $fileB] = $this->s49SharedFormalCluster($dir, 'S49PS1');
        // output-S37 re-pin: the builder renders signatures on one line, so
        // the leading promoted formals are pinned in the single-line shape.
        $this->assertMatchesRegularExpression('/\(\$value, \$user, /', (string) $cluster->signature,
            'fixture: the promoted shared formals lead the emitted signature');

        $returned = (new ApplyExtractor())->apply($cluster, $dir . '/out');
        $this->assertSame([$fileA, $fileB], $returned);

        $function = $this->s49ArtifactFunction($dir . '/out/Refactored/S49PS1.php');
        $this->assertSame(['value', 'user'], [
            $function->params[0]->var->name,
            $function->params[1]->var->name,
        ], 'the abstraction declares the members\' shared formals as its first parameters');

        $per = [$fileA => ['mailHigh', 'high'], $fileB => ['mailLow', 'low']];
        foreach ($per as $file => [$fn, $row]) {
            $call = $this->s45CallOf($file, $fn);
            $this->assertSame(['value', 'user'], [
                $call->args[0]->value->name,
                $call->args[1]->value->name,
            ], "the {$fn} call site passes the member's own promoted locals");
            $this->assertInstanceOf(Node\Scalar\String_::class, $call->args[2]->value,
                'the hole position still passes THIS member\'s observed row');
            $this->assertSame($row, $call->args[2]->value->value);
            $this->assertSame(count($function->params), count($call->args),
                "the {$fn} call binds every declared parameter");
            $this->assertPhpLints($file);
        }
        $this->assertPhpLints($dir . '/out/Refactored/S49PS1.php');

        $out = $this->s49RunSmoke($dir, $dir . '/out/Refactored/_autoload.php');
        $this->assertSame("U:high:V,T:low:W\n", $out,
            'the rewritten pair executes: promoted locals bind through the whole rewrite');
    }

    public function testDivergingFormalNamesPromoteNothingAtTheCallSites(): void
    {
        // The prefix law's negative arm seen through the apply door:
        // position 0 already disagrees by name ($first vs $other), so
        // NOTHING is promoted — the call keeps the pure hole-only family
        // and no argument can shift into a parameter the artifact never
        // declared. (The shared $rest at position 1 must NOT sneak in:
        // prefix, not subset.)
        $dir = $this->makeScratchDir();
        $srcA = "<?php\nfunction checkA(\$first, \$rest) { return tally(5, \$first); }\n";
        $srcB = "<?php\nfunction checkB(\$other, \$rest) { return tally(9, \$first); }\n";
        $fileA = $dir . '/a.php';
        $fileB = $dir . '/b.php';
        file_put_contents($fileA, $srcA);
        file_put_contents($fileB, $srcB);

        $cluster = new Cluster('S49NEG', [
            $this->cloneFunctionBlock($fileA, $srcA, 'checkA'),
            $this->cloneFunctionBlock($fileB, $srcB, 'checkB'),
        ], 0.9, false);
        (new AntiUnifier())->unify($cluster);
        (new SignatureBuilder())->buildSignature($cluster);
        $this->assertStringNotContainsString("\$first,", (string) $cluster->signature,
            'fixture: no promoted line survives the position-0 divergence');

        (new ApplyExtractor())->apply($cluster, $dir . '/out');

        $function = $this->s49ArtifactFunction($dir . '/out/Refactored/S49NEG.php');
        $paramNames = array_map(static fn (Node\Param $p): string => (string) $p->var->name, $function->params);
        $this->assertNotContains('first', $paramNames);
        $this->assertNotContains('other', $paramNames);
        $this->assertNotContains('rest', $paramNames,
            'position 1 ($rest) is name-equal but must NOT sneak in: prefix law, not subset');
        foreach ([$fileA => ['checkA', 5], $fileB => ['checkB', 9]] as $file => [$fn, $int]) {
            $call = $this->s45CallOf($file, $fn);
            $this->assertCount(count($function->params), $call->args,
                "{$fn}: the two hole rows ride the two declared parameters, nothing promoted leads");
            $this->assertInstanceOf(Node\Scalar\Int_::class, $call->args[1]->value,
                'the literal divergence rides position 1 exactly as the signature declares');
            $this->assertSame($int, $call->args[1]->value->value);
        }
        $this->assertPhpLints($fileA);
        $this->assertPhpLints($fileB);
    }

    public function testEmittedSignatureDeclaresExactlyWhatEveryCallSitePasses(): void
    {
        // The handoff's arity-invariant law made executable over three
        // shapes: promoted+hole, promoted+hole+knob, and hole-only. For
        // every apply-eligible cluster the artifact's declared parameters
        // and each rewritten call's arguments agree 1:1 positionally —
        // the equality S49b derives by construction, pinned from the
        // outside rather than asserted internally.
        $dirA = $this->makeScratchDir();
        [$clusterA, $fileA1, $fileA2] = $this->s49SharedFormalCluster($dirA, 'S49AR1');
        (new ApplyExtractor())->apply($clusterA, $dirA . '/out');
        $paramsA = $this->s49ArtifactFunction($dirA . '/out/Refactored/S49AR1.php')->params;
        foreach ([$fileA1 => 'mailHigh', $fileA2 => 'mailLow'] as $file => $fn) {
            $this->assertSame(count($paramsA), count($this->s45CallOf($file, $fn)->args),
                "shape (a): {$fn} binds every declared parameter");
        }

        $dirB = $this->makeScratchDir();
        [$fileB, $blocksB] = $this->twoMemberFile($dirB);
        $clusterB = new Cluster('S49AR2', $blocksB, 0.9, false);
        $clusterB->generalizedAst = $blocksB[0]->ast;
        $clusterB->holes = [
            new Hole('__O0', 'optional_block', ['send("admin", $u);', '']),
            new Hole('__P0', 'name', ['Alpha\Thing', 'Beta\Thing']),
        ];
        (new SignatureBuilder())->buildSignature($clusterB);
        $clusterB->holePaths = ['__P0' => ['stmts', 0, 'stmts', 0, 'expr', 'args', 0, 'value']];
        (new ApplyExtractor())->apply($clusterB, $dirB . '/out');
        $paramsB = $this->s49ArtifactFunction($dirB . '/out/Refactored/S49AR2.php')->params;
        foreach (['notifyA', 'notifyB'] as $fn) {
            $this->assertSame(count($paramsB), count($this->s45CallOf($fileB, $fn)->args),
                "shape (b): {$fn} — promoted + required + knob on both sides");
        }
        $this->assertCount(4, $paramsB);

        $dirC = $this->makeScratchDir();
        $srcC1 = "<?php\nfunction soloA(\$k) { return keep(1); }\n";
        $srcC2 = "<?php\nfunction soloB(\$k) { return keep(2); }\n";
        $fileC1 = $dirC . '/a.php';
        $fileC2 = $dirC . '/b.php';
        file_put_contents($fileC1, $srcC1);
        file_put_contents($fileC2, $srcC2);
        $clusterC = new Cluster('S49AR3', [
            $this->cloneFunctionBlock($fileC1, $srcC1, 'soloA'),
            $this->cloneFunctionBlock($fileC2, $srcC2, 'soloB'),
        ], 0.9, false);
        (new AntiUnifier())->unify($clusterC);
        (new SignatureBuilder())->buildSignature($clusterC);
        (new ApplyExtractor())->apply($clusterC, $dirC . '/out');
        $paramsC = $this->s49ArtifactFunction($dirC . '/out/Refactored/S49AR3.php')->params;
        $this->assertCount(2, $paramsC, 'shape (c): promoted $k + one hole');
        foreach ([$fileC1 => 'soloA', $fileC2 => 'soloB'] as $file => $fn) {
            $call = $this->s45CallOf($file, $fn);
            $this->assertSame(count($paramsC), count($call->args));
            $this->assertSame('k', $call->args[0]->value->name);
        }
    }

    public function testUnloadedMemberAstsAreHydratedFromTheLocatedDeclarations(): void
    {
        // The lazyAst world: a cluster handed to the apply door may have
        // lost its member ASTs AFTER the signature was built (workers drop
        // them; rebaseClusters re-anchors only content-bound members up to
        // the fork boundary). Promoting from null would derive NOTHING and
        // desync the call against the signature's promoted lines — the
        // loud ArgumentCountError class. Phase one of the plan hydrates
        // each unloaded AST from the located declaration node in the fresh
        // tree of the very file about to be rewritten (Block::$ast is
        // public-mutable by design; this is the loader idiom, narrower
        // than a re-anchor). Acceptance, verbatim from the handoff: a
        // cluster whose members were unloaded-then-re-anchored still
        // passes the arity and RUNS.
        $dir = $this->makeScratchDir();
        [$cluster, $fileA, $fileB] = $this->s49SharedFormalCluster($dir, 'S49REA');
        // output-S37 re-pin: single-line render — promoted formals leading
        // the parameter list is the property this fixture pins.
        $this->assertMatchesRegularExpression('/\(\$value, \$user, /', (string) $cluster->signature,
            'fixture: the signature carries the promoted formals (built while ASTs were loaded)');

        foreach ($cluster->members as $member) {
            $member->ast = null;
        }

        $returned = (new ApplyExtractor())->apply($cluster, $dir . '/out');
        $this->assertSame([$fileA, $fileB], $returned);

        $function = $this->s49ArtifactFunction($dir . '/out/Refactored/S49REA.php');
        foreach ([$fileA => 'mailHigh', $fileB => 'mailLow'] as $file => $fn) {
            $call = $this->s45CallOf($file, $fn);
            $this->assertSame(['value', 'user'], [
                $call->args[0]->value->name,
                $call->args[1]->value->name,
            ], "promotion survived the AST drop at {$fn} (hydrated from the located declaration)");
            $this->assertSame(count($function->params), count($call->args),
                "the unloaded-then-hydrated cluster keeps arity at {$fn}");
        }

        $out = $this->s49RunSmoke($dir, $dir . '/out/Refactored/_autoload.php');
        $this->assertSame("U:high:V,T:low:W\n", $out,
            'and the hydrated rewrite RUNS — not just lints');
    }

    public function testAHoleRenderedNameStopsPromotionAtTheApplyDoorToo(): void
    {
        // S49a's collision law (a hole that RENDERS the name `$u` reserves
        // it; the prefix stops there) must hold at the apply door as well.
        // The door derives the promotion set through the same shared
        // projection SignatureBuilder used — re-deriving with an empty
        // reserved set would prepend the members' locals while the emitted
        // signature declares only the hole: extra arguments into a
        // one-parameter strict_types function, the silent shifted-argument
        // corruption class the S49a handoff named.
        $dir = $this->makeScratchDir();
        [$file, $blocks] = $this->twoMemberFile($dir);

        $cluster = new Cluster('S49COL', $blocks, 0.9, false);
        $cluster->generalizedAst = $blocks[0]->ast;
        $collision = new Hole('__P0', 'literal', ['"admin"', '"moderator"']);
        $collision->suggestedName = '$u';
        $cluster->holes = [$collision];
        (new SignatureBuilder())->buildSignature($cluster);
        $this->assertStringNotContainsString("\$s,", (string) $cluster->signature,
            'fixture: the position-0 collision stops the prefix, so $s is never declared either');
        $cluster->holePaths = ['__P0' => ['stmts', 0, 'stmts', 0, 'expr', 'args', 0, 'value']];

        (new ApplyExtractor())->apply($cluster, $dir . '/out');

        $function = $this->s49ArtifactFunction($dir . '/out/Refactored/S49COL.php');
        $this->assertCount(1, $function->params, 'one hole parameter, nothing promoted (signature side)');
        $this->assertSame('u', $function->params[0]->var->name,
            'the hole keeps its rendered name; the promotion yielded it');
        foreach (['notifyA', 'notifyB'] as $fn) {
            $call = $this->s45CallOf($file, $fn);
            $this->assertCount(1, $call->args, 'the door passes the row only — no promoted locals the signature lacks');
            $this->assertInstanceOf(Node\Scalar\String_::class, $call->args[0]->value);
        }
        $this->assertPhpLints($file);
    }
}

/**
 * S44b harness: a stream wrapper that serves reads from {@see
 * self::$served} but records writes into {@see self::$recorded} where
 * reads NEVER look — the lying-disk shape the read-back verify exists
 * for. Same in-file harness style as the S36 wrappers in
 * UpdateCommandTest.
 */
class S44bLyingDisk
{
    /** @var array<string, string> bytes the fake disk serves on read */
    public static array $served = [];

    /** @var array<string, string> bytes the fake disk recorded on write */
    public static array $recorded = [];

    /** @var resource */
    public $context;

    private string $readBuffer = '';
    private int $position = 0;
    private ?string $writePath = null;

    /** @return array<string,int>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        if (!array_key_exists($path, self::$served)) {
            return false;
        }
        $now = time();
        return [
            'dev' => 0, 'ino' => 0, 'mode' => 0100644, 'nlink' => 1,
            'uid' => 0, 'gid' => 0, 'rdev' => 0, 'size' => strlen(self::$served[$path]),
            'atime' => $now, 'mtime' => $now, 'ctime' => $now, 'blksize' => -1, 'blocks' => -1,
        ];
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $mode = str_replace(['b', 't'], '', $mode);
        if ($mode === 'w' || $mode === 'x' || $mode === 'c') {
            if ($mode === 'x' && array_key_exists($path, self::$served)) {
                return false;
            }
            if ($mode !== 'c') {
                self::$recorded[$path] = '';
            }
            $this->writePath = $path;
            $this->readBuffer = '';
            $this->position = 0;
            return true;
        }
        if ($mode === 'r' || $mode === 'r+' || $mode === 'a' || $mode === 'a+') {
            if (!array_key_exists($path, self::$served)) {
                return false;
            }
            $this->readBuffer = self::$served[$path];
            $this->position = 0;
            return true;
        }
        return false;
    }

    public function stream_write(string $data): int
    {
        if ($this->writePath === null) {
            return 0;
        }
        self::$recorded[$this->writePath] = (self::$recorded[$this->writePath] ?? '') . $data;
        return strlen($data);
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->readBuffer, $this->position, $count);
        $this->position += strlen($chunk);
        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->readBuffer);
    }

    /** @return array<string,int> */
    public function stream_stat(): array
    {
        return ['mode' => 0100644, 'size' => strlen($this->readBuffer)];
    }

    public function stream_close(): void
    {
        $this->writePath = null;
    }

    public function unlink(string $path): bool
    {
        unset(self::$served[$path], self::$recorded[$path]);
        return true;
    }
}
