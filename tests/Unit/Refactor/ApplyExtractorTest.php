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
        $refl->setAccessible(true);
        $name = $refl->invoke($ext, $cluster->signature);

        $this->assertSame('notifyByThreshold', $name);
    }

    public function testExtractParametersFromSignature(): void
    {
        $ext = new ApplyExtractor();
        $refl = new \ReflectionMethod($ext, 'extractParameters');
        $refl->setAccessible(true);

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
        $refl->setAccessible(true);

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

    public function testSetNodeAtPathReplacesDeepNestedNode(): void
    {
        $parser = new AstParser();
        $stmts = $parser->parseCode('<?php function test($x) { if ($x > 0) { return 1; } }');
        $func = $stmts[0];
        $this->assertInstanceOf(Node\Stmt\Function_::class, $func);

        $ext = new ApplyExtractor();
        $refl = new \ReflectionMethod($ext, 'setNodeAtPath');
        $refl->setAccessible(true);

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
}
