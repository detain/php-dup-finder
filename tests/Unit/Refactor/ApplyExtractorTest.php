<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Refactor;

use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Util\LineRange;
use Phpdup\Parsing\AstParser;
use Phpdup\Refactor\AntiUnifier;
use Phpdup\Refactor\ApplyExtractor;
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
        $this->assertStringNotContainsString('function notifyA', $rewritten);
        $this->assertStringNotContainsString('function notifyB', $rewritten);
        $this->assertStringNotContainsString('send("admin"', $rewritten);
        $this->assertStringNotContainsString('send("moderator"', $rewritten,
            'the stale-range miss class: member 2 must not survive unrewritten');
        $this->assertSame(2, substr_count($rewritten, 'Refactored\\notifyByThreshold('),
            'both declarations became calls to the extracted function');

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
}
