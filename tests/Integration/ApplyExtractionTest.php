<?php
declare(strict_types=1);

namespace Phpdup\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Parsing\AstParser;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Refactor\ApplyExtractor;
use PhpParser\Node;
use Symfony\Component\Console\Output\NullOutput;

/**
 * End-to-end apply over the REAL pipeline path (output-S46, covering
 * php-dup-finder-output-002): a fixture tree written into /tmp scratch
 * (tests/Fixtures/apply/ is output-S53's territory), scanned, extracted,
 * clustered and anti-unified by the production stages — then applied.
 *
 * The stages hand ApplyExtractor members with rooted file paths exactly
 * as the scanner recorded them (the output-S38 ROOTED-THREADING law —
 * apply consumes $member->file verbatim, and hole/holePaths ride the real
 * AntiUnifier contract, not a hand-built one). Every output — both
 * rewritten member files, the generated artifact and the bootstrap stub —
 * must pass `php -l`, and an EXTERNAL CALLER running in a child php
 * process must still find every declaration and reach the extracted
 * function THROUGH it: pre-S46 the rewritten file began with a top-level
 * `return \Refactored\…;` that deleted the delegation target and aborted
 * the include, so every caller after it was orphaned. S49a/S49b reuse
 * this external-caller smoke harness pattern.
 */
final class ApplyExtractionTest extends TestCase
{
    /** @var list<string> scratch dirs removed in tearDown */
    private array $scratch = [];

    protected function tearDown(): void
    {
        foreach ($this->scratch as $dir) {
            self::rrmdir($dir);
        }
        $this->scratch = [];
    }

    private function makeScratchDir(): string
    {
        $dir = sys_get_temp_dir() . '/phpdup_s46_e2e_' . uniqid();
        @mkdir($dir, 0o775, true);
        $this->scratch[] = $dir;
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

    public function testFileScopeFunctionMembersDelegateAndSurviveIncludeTime(): void
    {
        // The function half of output-002, end to end: two files each
        // declare performAudit() whose bodies differ by one trailing
        // statement (the real AntiUnifier surfaces that difference as an
        // optional_block hole — the ONE hole kind whose call argument is
        // a literal, so the rewritten member is executable with zero free
        // variables in scope: the smoke run is honest, not decorative).
        // Each file also declares a second function AFTER performAudit —
        // pre-S46 the include-time top-level return meant that later
        // declaration was never declared at all. Both bodies end with
        // `return 1` so the generated artifact (which declares `: mixed`
        // under its own strict_types) lands a value — an implicit-fall-off
        // `: mixed` body fatals "none returned" at EXEC, a pre-S46 landmine
        // of the generator disclosed in the impl report (the OLD delete-the-
        // declaration rewrite fatals there identically; the return-shape
        // owners output-S49/S54 inherit it).
        $dir = $this->makeScratchDir();
        $tree = $dir . '/audit';
        mkdir($tree, 0o775, true);
        $fileA = $tree . '/auditA.php';
        $fileB = $tree . '/auditB.php';
        file_put_contents($fileA, "<?php\n"
            . "function performAudit() { logEntry(); stampRow(); sealOne(); return 1; }\n"
            . "function laterOne() { echo \"LATER-ONE\\n\"; }\n");
        file_put_contents($fileB, "<?php\n"
            . "function performAudit() { logEntry(); stampRow(); return 1; }\n"
            . "function laterTwo() { echo \"LATER-TWO\\n\"; }\n");

        $cluster = $this->findClusterNamed($this->runPipeline($tree), 'performAudit', 'function');
        $this->assertNotNull($cluster, 'the real pipeline must cluster the performAudit pair');
        $this->assertSame(2, $cluster->size());

        $returned = (new ApplyExtractor())->apply($cluster, $dir . '/out');
        $this->assertEqualsCanonicalizing([$fileA, $fileB], $returned,
            'the S44L contract: exactly the member files, verified (plan order may differ across the real clusterer)');

        // php -l every output.
        foreach ([$fileA, $fileB] as $memberFile) {
            $this->assertPhpLints($memberFile);
        }
        $artifacts = glob($dir . '/out/Refactored/*.php');
        $this->assertIsArray($artifacts);
        $this->assertCount(2, $artifacts, 'fixture: artifact + bootstrap stub both landed');
        foreach ($artifacts as $artifact) {
            $this->assertPhpLints($artifact);
        }

        // Structural law on the rewritten member: performAudit is still a
        // top-level Function_ with its name, delegating from inside the
        // body — and laterOne still follows it.
        $stmts = (new AstParser())->parseCode((string) file_get_contents($fileA));
        $this->assertNotNull($stmts, 'the rewritten member re-parses');
        $decls = [];
        foreach ($stmts as $stmt) {
            $this->assertNotInstanceOf(Node\Stmt\Return_::class, $stmt,
                'no top-level return stands where the declaration used to');
            if ($stmt instanceof Node\Stmt\Function_) {
                $decls[] = $stmt->name->toString();
            }
        }
        $this->assertSame(['performAudit', 'laterOne'], $decls);

        // External-caller smoke (the harness S49a/S49b reuse): a CHILD php
        // process requires the bootstrap stub, then the rewritten member,
        // then calls the original function — delegation runs through the
        // artifact, and the later declaration is callable at all. Exact
        // stdout: any warning, fatal or redeclare noise fails the match.
        $this->assertSame(
            "LOG\nSTAMP\nLATER-ONE\n",
            $this->runDriver($dir, $fileA, 'laterOne'),
            'external caller reaches the delegation AND the declaration after it',
        );
        $this->assertSame(
            "LOG\nSTAMP\nLATER-TWO\n",
            $this->runDriver($dir, $fileB, 'laterTwo'),
            'the short member delegates identically',
        );
    }

    public function testVoidClassMethodMembersDelegateInsideTheClassBody(): void
    {
        // The method-in-class half, through the real stages: the finding's
        // headline symptom was php's own `unexpected token "return"` —
        // pre-S46 the rewrite left `class LedgerA { return \Refactored\…;
        // }`, which php-parser refuses, so even the re-parse guard (S48)
        // refused the whole cluster. Now the method stays, keeps its
        // signature (param + : void ride through), delegates through a
        // bare statement (void may not return the abstraction's value),
        // and every sibling declaration in the class survives beside it.
        $dir = $this->makeScratchDir();
        $tree = $dir . '/ledger';
        mkdir($tree, 0o775, true);
        $fileA = $tree . '/LedgerA.php';
        $fileB = $tree . '/LedgerB.php';
        file_put_contents($fileA, "<?php\n"
            . "class LedgerA {\n"
            . "    public function post(\$value): void { logEntry(); sealOne(); }\n"
            . "    public function helperA() { echo \"HA\\n\"; }\n"
            . "}\n");
        file_put_contents($fileB, "<?php\n"
            . "class LedgerB {\n"
            . "    public function post(\$value): void { logEntry(); }\n"
            . "    public function helperB() { echo \"HB\\n\"; }\n"
            . "}\n");

        $cluster = $this->findClusterNamed($this->runPipeline($tree), 'post', 'method');
        $this->assertNotNull($cluster, 'the real pipeline must cluster the post() pair');
        $this->assertSame(2, $cluster->size());

        $returned = (new ApplyExtractor())->apply($cluster, $dir . '/out');
        $this->assertEqualsCanonicalizing([$fileA, $fileB], $returned);

        foreach ([$fileA, $fileB] as $memberFile) {
            $this->assertPhpLints($memberFile, 'a rewritten class body that php -l refuses is the output-002 shape');
        }
        foreach ((array) glob($dir . '/out/Refactored/*.php') as $artifact) {
            $this->assertPhpLints($artifact);
        }

        $stmtsA = (new AstParser())->parseCode((string) file_get_contents($fileA));
        $this->assertNotNull($stmtsA, 'the rewritten class file re-parses (the acceptance php-parser leg)');
        $classA = null;
        foreach ($stmtsA as $stmt) {
            if ($stmt instanceof Node\Stmt\Class_) {
                $classA = $stmt;
            }
        }
        $this->assertInstanceOf(Node\Stmt\Class_::class, $classA);
        foreach ($classA->stmts as $bodyStmt) {
            $this->assertNotInstanceOf(Node\Stmt\Return_::class, $bodyStmt,
                'acceptance: no top-level Return_ in the class body (AST check)');
        }

        $post = $classA->getMethod('post');
        $this->assertNotNull($post, 'the class still declares post()');
        $this->assertCount(1, $post->params, 'the signature survives the rewrite');
        $this->assertSame('value', (string) $post->params[0]->var->name);
        $this->assertInstanceOf(Node\Identifier::class, $post->returnType);
        $this->assertSame('void', (string) $post->returnType->name);
        $this->assertCount(1, $post->stmts);
        $this->assertInstanceOf(Node\Stmt\Expression::class, $post->stmts[0],
            'the : void member delegates without returning');
        $call = $post->stmts[0]->expr;
        $this->assertInstanceOf(Node\Expr\FuncCall::class, $call);
        $this->assertInstanceOf(Node\Name\FullyQualified::class, $call->name,
            'S47 law: the delegation is fully qualified');
        $this->assertStringStartsWith('Refactored\\', $call->name->toString());

        $this->assertNotNull($classA->getMethod('helperA'), 'the sibling declaration survives');
        $stmtsB = (new AstParser())->parseCode((string) file_get_contents($fileB));
        $this->assertNotNull($stmtsB);
        foreach ($stmtsB as $stmt) {
            if ($stmt instanceof Node\Stmt\Class_) {
                foreach ($stmt->stmts as $bodyStmt) {
                    $this->assertNotInstanceOf(Node\Stmt\Return_::class, $bodyStmt);
                }
                $this->assertNotNull($stmt->getMethod('post'));
                $this->assertNotNull($stmt->getMethod('helperB'));
            }
        }
    }

    /**
     * @param list<Cluster> $clusters
     * @return Cluster|null the cluster whose members are ALL $kind blocks
     *                      named $name (the real clusterer may also form
     *                      unrelated ones on a small tree — never apply
     *                      to those)
     */
    private function findClusterNamed(array $clusters, string $name, string $kind): ?Cluster
    {
        foreach ($clusters as $cluster) {
            $match = $cluster->size() >= 2;
            foreach ($cluster->members as $member) {
                if ($member->kind !== $kind || $member->name !== $name) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                return $cluster;
            }
        }
        return null;
    }

    /**
     * The production pipeline, the OptionalBlocksTest driver — rooted scan
     * paths, real AntiUnifier hole/holePaths contract, S19 launcher
     * untouched. useCache/exactOnly false as every integration driver sets.
     *
     * @return list<Cluster>
     */
    private function runPipeline(string $tree): array
    {
        $config = new Config(
            paths: [$tree],
            exclude: Config::defaults([])->exclude,
            minBlockSize: 4,
            // Tiny scratch corpus — the default max_df strips every n-gram.
            maxDocumentFrequency: 0.5,
            minClusterImpact: 1,
            lazyAst: false,
        );

        $state = new PipelineState($config);
        $out = new NullOutput();
        (new ScanningStage())->run($state, $out);
        (new PreprocessStage(useCache: false))->run($state, $out);
        (new ClusterStage(exactOnly: false))->run($state, $out);
        (new RefactorStage(useCache: false))->run($state, $out);

        return $state->clusters;
    }

    /**
     * External-caller smoke harness (the pattern S49a/S49b reuse): child
     * php process, stub first so the abstraction is declared, then the
     * rewritten member, then call the original function and the function
     * declared after it. Combined output returned verbatim — exit noise,
     * warnings and fatals included, so an exact string match is a hard bar.
     */
    private function runDriver(string $dir, string $memberFile, string $laterFn): string
    {
        $driver = $dir . '/driver_' . $laterFn . '.php';
        file_put_contents($driver, "<?php\n"
            . "error_reporting(E_ALL);\n"
            . "require " . var_export($dir . '/out/Refactored/_autoload.php', true) . ";\n"
            . "require " . var_export($memberFile, true) . ";\n"
            . "function logEntry() { echo \"LOG\\n\"; }\n"
            . "function stampRow() { echo \"STAMP\\n\"; }\n"
            . "function sealOne() { echo \"SEAL\\n\"; }\n"
            . "if (!function_exists('performAudit')) { echo \"NO-AUDIT-DECL\\n\"; return; }\n"
            . "performAudit();\n"
            . "if (!function_exists('" . $laterFn . "')) { echo \"NO-LATER-DECL\\n\"; return; }\n"
            . $laterFn . "();\n");

        $output = shell_exec('php ' . escapeshellarg($driver) . ' 2>&1');
        $this->assertIsString($output, 'fixture: the child php run produced output');
        return $output;
    }

    private function assertPhpLints(string $path, string $message = ''): void
    {
        $lint = shell_exec('php -l ' . escapeshellarg($path) . ' 2>&1');
        $this->assertStringContainsString('No syntax errors', (string) $lint, ($message !== '' ? $message . ' — ' : '') . "php -l on {$path}");
    }
}
