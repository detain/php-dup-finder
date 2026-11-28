<?php
declare(strict_types=1);

namespace Phpdup\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Parsing\AstParser;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Refactor\AntiUnifier;
use Phpdup\Refactor\ApplyExtractor;
use Phpdup\Reporting\RefactorPatchReporter;
use Phpdup\Util\LineRange;
use PhpParser\Node;
use Symfony\Component\Console\Output\NullOutput;

/**
 * End-to-end apply regression suite (output-S53, covering
 * php-dup-finder-output-009): the coverage this group always lacked.
 *
 * Every leg drives the REAL chain — either the production stages over a
 * scratch tree MATERIALIZED from a committed `tests/Fixtures/apply/` unit
 * (the S46 house driver; the `.php.dist` corpus contract in that dir's
 * README keeps the frozen CliSmokeTest scan-count pins intact), or the
 * actual `bin/phpdup` binary as a child process (the CliSmokeTest harness
 * pattern) — and asserts on the WRITTEN TREE: extraction correctness
 * (holes parameterized, call sites rewritten, promoted shared formals per
 * S49b, per-member rows per S45), declaration preservation (S46, typed and
 * `: void` shapes), the transactional guarantees (mid-window failure ⇒
 * full rollback with byte-exact restore, S44b) and the named refusal
 * diagnostics of the whole chain (S44a locate + count-verify, S47
 * fully-qualified calls, S48 placement, S50 `$this` hazard gate, S51
 * coverage floor, S52 link refusals, S57 sentinel-inert routed clusters).
 *
 * Laws honored here:
 *  - Committed fixtures are read-only to this suite by construction:
 *    materialize-first, and every provocation (chmod-readonly, planted
 *    symlink, mutated span, stale checkout) lands only inside a fresh
 *    `sys_get_temp_dir()` scratch tree this test owns and removes.
 *  - `--apply` keeps its gated posture: the F1b branch is opened only with
 *    the explicit `--dry-run=false` caller argument.
 *  - Pins state the chain's CURRENT honest shape, including disclosed
 *    edges: the optional-block knob is evaluated-and-discarded in the
 *    abstraction (S46 F-A), a second cluster over the same file can go
 *    stale after the first cluster's rewrite (the locate arm then refuses
 *    with zero writes — namespaced_strict/keepMe exists precisely to keep
 *    that law exercised; this suite never applies that second cluster).
 *  - Deterministic + hermetic: no clock, no network; every child php has
 *    closed stdin and a wall deadline.
 */
final class ApplyEndToEndTest extends TestCase
{
    /** Wall-clock deadline per child process (CliSmokeTest law). */
    private const TIMEOUT_S = 120.0;

    /** @var list<string> scratch roots, removed in tearDown */
    private array $scratch = [];

    protected function tearDown(): void
    {
        foreach ($this->scratch as $dir) {
            self::rrmdir($dir);
        }
        $this->scratch = [];
    }

    // ============================================================ happy paths

    public function testNamespacedStrictPairAppliesDelegatesAndStillRunsUnderStrictTypes(): void
    {
        // The crown leg of the group: namespaced, strict-typed, typed-formal
        // members — the exact shape where S47's qualified-RELATIVE call was
        // a runtime fatal, so this leg RUNS the rewritten code. Covers the
        // plan's namespaced-call-site, strict-types-runtime-smoke,
        // promoted-params (S49b) and php -l every buffer legs.
        $dir = $this->makeScratchDir();
        $tree = $this->materialize('namespaced_strict', $dir . '/tree');
        $fileHigh = $tree . '/src/NotifyHigh.php';
        $fileLow = $tree . '/src/NotifyLow.php';

        $cluster = $this->findClusterWithMembers($this->runPipeline($tree), ['notify', 'notify']);
        $this->assertNotNull($cluster, 'the real pipeline must cluster the notify() pair');
        $this->assertSame(2, $cluster->size());
        // Construction premise (S45): ONE literal hole, two observed rows.
        $this->assertCount(1, $cluster->holes);
        $this->assertSame('literal', $cluster->holes[0]->kind);

        $returned = (new ApplyExtractor())->apply($cluster, $dir . '/out');
        $this->assertEqualsCanonicalizing([$fileHigh, $fileLow], $returned,
            'the S44L contract: exactly the member files, each read back byte-for-byte');

        // php -l EVERY buffer the transaction produced.
        $artifacts = glob($dir . '/out/Refactored/*.php');
        $this->assertIsArray($artifacts);
        $this->assertCount(2, $artifacts, 'fixture: artifact + bootstrap stub both landed');
        foreach (array_merge([$fileHigh, $fileLow], $artifacts) as $buffer) {
            $this->assertPhpLints($buffer);
        }

        // Structural law on every rewritten member: the typed declaration
        // survives (S46), the body is ONE delegating return whose call is
        // FullyQualified (S47), the sibling keepMe stays intact, and no
        // relative `Refactored\` residue rides the member bytes (the P1
        // end-to-end half booked to this harness by output-S50).
        $parser = new AstParser();
        $calls = [];
        foreach ([$fileHigh => 'high', $fileLow => 'low'] as $memberFile => $row) {
            $stmts = $parser->parseCode((string) file_get_contents($memberFile));
            $this->assertNotNull($stmts, "the rewritten {$row} member re-parses");
            $class = $this->firstOfClass($stmts);
            $notify = $class->getMethod('notify');
            $this->assertNotNull($notify, "S46 law: the {$row} member keeps its declaration");
            $this->assertCount(2, $notify->params, 'the member formals survive the rewrite verbatim');
            $this->assertSame('user', (string) $notify->params[0]->var->name);
            $this->assertSame('string', (string) $notify->params[0]->type);
            $this->assertSame('score', (string) $notify->params[1]->var->name);
            $this->assertSame('int', (string) $notify->params[1]->type);
            $this->assertInstanceOf(Node\Identifier::class, $notify->returnType);
            $this->assertSame('string', (string) $notify->returnType->name);
            $this->assertNotNull($class->getMethod('keepMe'), 'the sibling declaration stands');

            $this->assertCount(1, $notify->stmts, 'the body is exactly the delegation');
            $this->assertInstanceOf(Node\Stmt\Return_::class, $notify->stmts[0]);
            $call = $notify->stmts[0]->expr;
            $this->assertInstanceOf(Node\Expr\FuncCall::class, $call);
            $this->assertInstanceOf(Node\Name\FullyQualified::class, $call->name,
                'S47 law: the delegation is fully qualified (relative would resolve under App\Ledger and fatal)');
            $this->assertStringStartsWith('Refactored\\', $call->name->toString());
            $calls[$row] = $call;

            $bytes = (string) file_get_contents($memberFile);
            $this->assertSame(0, preg_match('/(?<!\\\\)\bRefactored\\\\/', $bytes),
                "no relative `Refactored\\` residue in {$row}");
        }
        $keepBytes = (string) file_get_contents($fileHigh);
        $this->assertStringContainsString("'HIGH-KEPT'", $keepBytes,
            'the never-applied sibling body was not touched by the transaction');

        // Call-argument law (S45 + S49b): the promoted shared formals lead
        // as member locals, the observed literal row rides last — and each
        // member passes ITS OWN row.
        foreach ([$fileHigh => 'high', $fileLow => 'low'] as $row) {
            $call = $calls[$row];
            $this->assertInstanceOf(Node\Expr\Variable::class, $call->args[0]->value);
            $this->assertSame('user', (string) $call->args[0]->value->name);
            $this->assertInstanceOf(Node\Expr\Variable::class, $call->args[1]->value);
            $this->assertSame('score', (string) $call->args[1]->value->name);
            $this->assertInstanceOf(Node\Scalar\String_::class, $call->args[2]->value);
            $this->assertSame($row, $call->args[2]->value->value,
                'S45 law: arguments are member-specific, never one shared row');
        }

        // The emitted abstraction: promoted formals FIRST, typed as the
        // members typed them, the hole formal trailing — and one parameter
        // per call argument (S49b arity law, observed from the outside).
        $artifactPhp = array_values(array_filter((array) $artifacts,
            static fn (string $p): bool => basename($p) !== ApplyExtractor::BOOTSTRAP_FILE));
        $this->assertCount(1, $artifactPhp);
        $artifactStmts = $parser->parseCode((string) file_get_contents($artifactPhp[0]));
        $this->assertNotNull($artifactStmts, 'the artifact re-parses');
        $artifact = $this->findArtifactFunction($artifactStmts);
        $this->assertInstanceOf(Node\Stmt\Function_::class, $artifact);
        $this->assertSame(['user', 'score', 'value'], array_map(
            static fn ($p): string => (string) $p->var->name, $artifact->params),
            'S49b law: the shared formals lead the promoted list; the literal hole closes it');
        $this->assertSame('string', (string) $artifact->params[0]->type);
        $this->assertSame('int', (string) $artifact->params[1]->type);
        $this->assertCount(count($calls['high']->args), $artifact->params,
            'the emitted signature declares exactly what every call site passes');

        // Runtime smoke under strict_types: a CHILD php process requires the
        // bootstrap stub, then both rewritten members, and calls through the
        // ORIGINAL namespaced classes. Any unresolved name (the S47 class of
        // bug), warning, TypeError or missing declaration breaks the exact
        // combined stdout.
        $smoke = strtr(<<<'PHP'
            <?php
            error_reporting(E_ALL);
            require '@@STUB@@';
            require '@@HIGH@@';
            require '@@LOW@@';
            function render(string $tag, string $level): string { echo "RENDER:{$tag}:{$level}\n"; return "OK:{$level}"; }
            echo (new App\Ledger\NotifyHigh())->notify('u7', 42) . "\n";
            echo (new App\Ledger\NotifyLow())->notify('u7', 42) . "\n";
            PHP, [
                '@@STUB@@' => $dir . '/out/Refactored/_autoload.php',
                '@@HIGH@@' => $fileHigh,
                '@@LOW@@'  => $fileLow,
            ]);
        $this->assertSame("RENDER:U7:high\nOK:high\nRENDER:U7:low\nOK:low\n",
            $this->runChildPhp($this->writeDriver($dir, 'smoke', $smoke)),
            'delegation resolves through the stub and runs the abstraction end to end');

        // Typed-declaration fidelity probe: the TypeError still fires at the
        // MEMBER boundary with the member's own signature — proof the
        // rewrite kept the typed declaration rather than laundering calls
        // through an untyped shim.
        $typeErr = strtr(<<<'PHP'
            <?php
            error_reporting(E_ALL);
            require '@@STUB@@';
            require '@@HIGH@@';
            function render(string $tag, string $level): string { return "OK:{$level}"; }
            try { (new App\Ledger\NotifyHigh())->notify('u7', 'not-an-int'); echo "NO-TYPE-ERROR\n"; }
            catch (TypeError $e) { echo get_class($e) . ': ' . explode(', called in ', $e->getMessage())[0] . "\n"; }
            PHP, [
                '@@STUB@@' => $dir . '/out/Refactored/_autoload.php',
                '@@HIGH@@' => $fileHigh,
            ]);
        $this->assertSame(
            "TypeError: App\\Ledger\\NotifyHigh::notify(): Argument #2 (\$score) must be of type int, string given\n",
            $this->runChildPhp($this->writeDriver($dir, 'typeerr', $typeErr)),
            'the rewritten member keeps its typed contract at its own boundary');    }

    public function testTwoMembersInOneFileAreRewrittenFromASingleParseWithVoidDelegation(): void
    {
        // The S44A single-parse law + the S46 `: void` delegation shape end
        // to end: ONE file carries both clone members (two classes sharing
        // `post($value): void`); the bodies differ by the trailing
        // stamper() call, which the real AntiUnifier surfaces as ONE
        // optional_block knob (the S46-proven shape) whose per-member truth
        // rides the rewritten calls. Both nodes are collected from a single
        // read+parse and land as ONE verified file.
        $dir = $this->makeScratchDir();
        $tree = $this->materialize('samesite', $dir . '/tree');
        $fileKit = $tree . '/ledger-kit.php';

        $cluster = $this->findClusterWithMembers($this->runPipeline($tree), ['post', 'post']);
        $this->assertNotNull($cluster, 'the real pipeline must cluster the post() pair');
        $this->assertSame(2, $cluster->size());
        $this->assertSame([$fileKit, $fileKit],
            array_map(static fn (Block $m): string => $m->file, $cluster->members),
            'fixture: both members live in the same file');
        $this->assertCount(1, $cluster->holes);
        $this->assertSame('optional_block', $cluster->holes[0]->kind,
            'the trailing-statement difference must be a presence knob');

        $returned = (new ApplyExtractor())->apply($cluster, $dir . '/out');
        $this->assertSame([$fileKit], $returned,
            'S44A: two collected nodes, ONE verified landing — from a single parse');
        $this->assertPhpLints($fileKit);

        $stmts = (new AstParser())->parseCode((string) file_get_contents($fileKit));
        $this->assertNotNull($stmts, 'the double-member file re-parses');
        $classes = [];
        foreach ((array) $stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Class_) {
                $classes[(string) $stmt->name] = $stmt;
            }
        }
        $this->assertSame(['LedgerA', 'LedgerB'], array_keys($classes),
            'S46 law: both class declarations survive the rewrite');
        $this->assertNotNull($classes['LedgerA']->getMethod('helperA'));
        $this->assertNotNull($classes['LedgerB']->getMethod('helperB'),
            'the sibling declarations stay callable at all costs');

        $voidCalls = [];
        foreach (['LedgerA' => '1', 'LedgerB' => '0'] as $cls => $truth) {
            $post = $classes[$cls]->getMethod('post');
            $this->assertNotNull($post);
            $this->assertSame('void', (string) $post->returnType, 'the : void signature is preserved');
            $this->assertCount(1, $post->stmts, 'the body is exactly the delegation');
            // S46's void shape: a bare Expression, NOT `return <call>;`.
            $this->assertInstanceOf(Node\Stmt\Expression::class, $post->stmts[0]);
            $call = $post->stmts[0]->expr;
            $this->assertInstanceOf(Node\Expr\FuncCall::class, $call);
            $this->assertInstanceOf(Node\Name\FullyQualified::class, $call->name);
            $this->assertStringStartsWith('Refactored\\', $call->name->toString());
            $this->assertInstanceOf(Node\Expr\Variable::class, $call->args[0]->value,
                'S49b: the promoted formal is passed as the member local');
            $this->assertSame('value', (string) $call->args[0]->value->name);
            $voidCalls[$cls] = $this->knobTruth($call);
        }
        $this->assertSame('1', $voidCalls['LedgerA'], 'LedgerA had stamper() — its knob row is true');
        $this->assertSame('0', $voidCalls['LedgerB'], 'LedgerB lacked it — its knob row is false');

        // The abstraction: promoted $value first, defaulted-bool knob last;
        // the shared statements ride verbatim while the knob statement is
        // evaluated-and-discarded (the S46 F-A disclosure pinned as truth:
        // stamper() is NOT called from the artifact body).
        $artifactPhp = array_values(array_filter((array) glob($dir . '/out/Refactored/*.php'),
            static fn (string $p): bool => basename($p) !== ApplyExtractor::BOOTSTRAP_FILE));
        $this->assertCount(1, $artifactPhp);
        $artifactBytes = (string) file_get_contents($artifactPhp[0]);
        $this->assertPhpLints($artifactPhp[0]);
        $this->assertStringContainsString('logEntry();', $artifactBytes,
            'statements ALL members share stay in the template');
        $this->assertStringContainsString('sealRow($value);', $artifactBytes,
            'the promoted local is what the shared statement consumes');
        $this->assertStringNotContainsString('stamper(', $artifactBytes,
            'F-A truth: the optional-block body gates nothing yet — the holed call never fires');
        $artifactStmts = (new AstParser())->parseCode($artifactBytes);
        $artifact = $this->findArtifactFunction((array) $artifactStmts);
        $this->assertInstanceOf(Node\Stmt\Function_::class, $artifact);
        $this->assertCount(2, $artifact->params, 'the signature declares exactly what the call sites pass');
        $this->assertSame('value', (string) $artifact->params[0]->var->name);
        $this->assertSame('bool', (string) $artifact->params[1]->type);
        $this->assertInstanceOf(Node\Expr\ConstFetch::class, $artifact->params[1]->default,
            'S45: the knob formal carries its default');
        $this->assertSame('false', strtolower((string) $artifact->params[1]->default->name));

        // Runtime, part one: the delegation FIRES — the artifact's shared
        // statements run through the rewritten member. But calling a `: void`
        // member also finishes the artifact's body, whose last statement is
        // the evaluated-and-discarded knob, so the `: mixed` function falls
        // off its own end and PHP fatals (pre-S46 landmine disclosed by
        // ApplyExtractionTest's docblock; the return-shape owners output-S49/
        // S54 inherit it). src/** is out of this lane's locks, so the suite
        // pins the CURRENT truth exactly: the shared-statement output first,
        // then the single fatal line — and nothing after (the uncaught error
        // kills the child before LedgerB's call). Flip this pin to a clean
        // combined bar the day the return shape lands.
        $first = strtr(<<<'PHP'
            <?php
            error_reporting(E_ALL);
            require '@@STUB@@';
            require '@@KIT@@';
            function logEntry() { echo "LOG\n"; }
            function sealRow($value) { echo "SEAL:{$value}\n"; }
            function stamper() { echo "STAMP\n"; }
            (new LedgerA())->post('x');
            (new LedgerB())->post('y');
            PHP, [
                '@@STUB@@' => $dir . '/out/Refactored/_autoload.php',
                '@@KIT@@'  => $fileKit,
            ]);
        $ran = $this->runChildPhp($this->writeDriver($dir, 'kit', $first));
        $this->assertStringStartsWith("LOG\nSEAL:x\n", $ran,
            'the delegation resolves and the shared template statements run');
        $this->assertMatchesRegularExpression(
            '/^PHP Fatal error:  Uncaught TypeError: Refactored\\\\post\(\): Return value must be of type mixed, '
            . 'none returned in \S*\/out\/Refactored\/F[0-9a-f]{16}\.php:17\n/s',
            substr($ran, strlen("LOG\nSEAL:x\n")),
            'CURRENT TRUTH: the knob-terminated `: mixed` artifact falls off its end (S49/S54 return-shape landmine)');
        $this->assertStringNotContainsString('SEAL:y', $ran,
            'the uncaught fatal stops the child — the second call never runs');

        // Runtime, part two: never touching the fatal path, the untouched
        // siblings answer through the rewritten file — the class body still
        // loads and the S46 keep-members law is live.
        $second = strtr(<<<'PHP'
            <?php
            error_reporting(E_ALL);
            require '@@STUB@@';
            require '@@KIT@@';
            echo (new LedgerA())->helperA(3) . "\n";
            echo (new LedgerB())->helperB();
            PHP, [
                '@@STUB@@' => $dir . '/out/Refactored/_autoload.php',
                '@@KIT@@'  => $fileKit,
            ]);
        $this->assertSame("3\nB-HELPER-1\n",
            $this->runChildPhp($this->writeDriver($dir, 'helpers', $second)),
            'the rewritten file still loads and its non-member siblings run');
    }

    public function testCliApplyHappyPathRewritesTheTreeAndSummarizesVerifiedRewrites(): void
    {
        // The bin-level leg: exactly what a user (and CI) hits —
        // `bin/phpdup analyze <tree> --apply --dry-run=false` — asserted on
        // the written tree and the F1b summary tallies (S44b verified
        // counts, S47/S50 Bootstrap row) instead of an in-process call.
        $dir = $this->makeScratchDir();
        $tree = $this->materialize('namespaced_strict', $dir . '/tree');
        $fileHigh = $tree . '/src/NotifyHigh.php';
        $fileLow = $tree . '/src/NotifyLow.php';
        $arrivedMode = substr(sprintf('%o', (int) fileperms($fileHigh)), -4);

        $run = $this->cli(['analyze', $tree, '--apply', '--dry-run=false',
            '--max-df=0.5', '--min-block-size=3', '--workers=1', '--min-impact=5'], $dir);

        $this->assertSame(0, $run['exit'], 'CLI apply happy path exits 0 — output: ' . $run['combined']);

        $summaryPath = $dir . '/refactored/' . RefactorPatchReporter::APPLY_SUMMARY_FILE;
        $this->assertFileExists($summaryPath, 'the F1b summary lands where the CLI said it would');
        $summary = (string) file_get_contents($summaryPath);
        $this->assertStringContainsString('# Applied: 1 clusters', $summary,
            'exactly one cluster applied — the notify pair: ' . $summary);
        $this->assertStringContainsString('# Verified member rewrites: 2 files', $summary,
            'the S44b tally counts only read-back-verified member rewrites');
        $this->assertMatchesRegularExpression(
            '/# Bootstrap: \S*_autoload\.php — require_once it/',
            $summary, 'S47/S50: the loading story rides the summary, right after the Files row');
        $this->assertStringNotContainsString('# Skipped:', $summary, 'the happy corpus must not skip anything');

        $artifacts = glob($dir . '/refactored/Refactored/*.php');
        $this->assertIsArray($artifacts);
        $this->assertCount(2, $artifacts, 'artifact + stub land under the absolutized apply dir');
        foreach (array_merge([$fileHigh, $fileLow], $artifacts) as $buffer) {
            $this->assertPhpLints($buffer);
        }
        foreach ([$fileHigh, $fileLow] as $memberFile) {
            $this->assertStringContainsString('\\Refactored\\', (string) file_get_contents($memberFile),
                basename($memberFile) . ' was rewritten by the CLI apply');
        }
        $stub = $dir . '/refactored/Refactored/' . ApplyExtractor::BOOTSTRAP_FILE;
        $this->assertFileExists($stub);
        $this->assertSame('0644', substr(sprintf('%o', (int) fileperms($stub)), -4),
            'S52: the generated stub rides the measured mode');
        $this->assertSame($arrivedMode, substr(sprintf('%o', (int) fileperms($fileHigh)), -4),
            'S52: a rewritten member keeps the mode it arrived with');
    }

    // ====================================================== refusal taxonomy

    public function testReadOnlyMemberMidWindowFailureRollsBackTheWholeWindowByteExact(): void
    {
        // The transactional crown, e2e: with ONE member file blocked read-
        // only inside the scratch copy, the write window fails after earlier
        // landings — the S44b rollback must restore every already-written
        // file byte-for-byte, remove the generated files and the directories
        // the window created, and say so with the named voice.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('filesystem write refusal cannot be provoked for uid 0 (house root-guard, S44b precedent)');
        }
        $dir = $this->makeScratchDir();
        $tree = $this->materialize('namespaced_strict', $dir . '/tree');
        $fileHigh = $tree . '/src/NotifyHigh.php';
        $fileLow = $tree . '/src/NotifyLow.php';

        $cluster = $this->findClusterWithMembers($this->runPipeline($tree), ['notify', 'notify']);
        $this->assertNotNull($cluster);

        $preHigh = (string) file_get_contents($fileHigh);
        $preLow = (string) file_get_contents($fileLow);
        // Block ONE target file. Whichever member plans first, the law is
        // order-independent: every already-written byte comes back.
        chmod($fileLow, 0o444);

        try {
            (new ApplyExtractor())->apply($cluster, $dir . '/out');
            $this->fail('the blocked member file must stop the write window');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('write window rolled back', $e->getMessage(),
                'the rollback voice is named: ' . $e->getMessage());
            $this->assertStringContainsString('path(s) restored', $e->getMessage());
        } finally {
            @chmod($fileLow, 0o644);
        }

        $this->assertSame($preHigh, (string) file_get_contents($fileHigh),
            'an already-written member landing is restored byte-for-byte');
        $this->assertSame($preLow, (string) file_get_contents($fileLow),
            'the blocked member never moved from its pre-apply bytes');
        $this->assertFileDoesNotExist($dir . '/out/Refactored/' . ApplyExtractor::BOOTSTRAP_FILE,
            'the stub that landed inside the window is gone');
        $this->assertDirectoryDoesNotExist($dir . '/out/Refactored',
            'the transaction leaves no directory residue behind');
        $this->assertDirectoryDoesNotExist($dir . '/out');
    }

    public function testPlantedSymlinkMemberRefusesTheClusterBeforeAnyByte(): void
    {
        // S52 confinement through clustered members: swap a member path for
        // a symlink (planted in the RUNTIME copy only) pointing outside the
        // scanned tree. The entry gate must refuse by name and write
        // nothing — not into the tree, not through the link.
        $dir = $this->makeScratchDir();
        $tree = $this->materialize('namespaced_strict', $dir . '/tree');
        $fileLow = $tree . '/src/NotifyLow.php';

        $cluster = $this->findClusterWithMembers($this->runPipeline($tree), ['notify', 'notify']);
        $this->assertNotNull($cluster);

        $original = (string) file_get_contents($fileLow);
        $outsideDir = $dir . '/outside';
        mkdir($outsideDir, 0o775, true);
        $outside = $outsideDir . '/victim.php';
        $this->assertNotFalse(file_put_contents($outside, $original));
        unlink($fileLow);
        $this->assertTrue(symlink($outside, $fileLow), 'fixture: symlink planted in the scratch copy');
        $fileHigh = $tree . '/src/NotifyHigh.php';
        $preHigh = (string) file_get_contents($fileHigh);

        try {
            (new ApplyExtractor())->apply($cluster, $dir . '/out');
            $this->fail('the symlinked member must be refused at the entry');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('is a symbolic link', $e->getMessage(),
                'the refusal names the LINK, not a later symptom: ' . $e->getMessage());
            $this->assertStringContainsString($fileLow, $e->getMessage());
            $this->assertStringContainsString($outside, $e->getMessage(), 'and it carries the readlink target');
            $this->assertStringContainsString('no files were written', $e->getMessage());
        }

        $this->assertSame($original, (string) file_get_contents($outside),
            'the link target was never written through');
        $this->assertSame($preHigh, (string) file_get_contents($fileHigh),
            'the plain member file stands untouched');
        $this->assertDirectoryDoesNotExist($dir . '/out', 'the entry gate precedes mkdir: no out tree at all');
    }

    public function testStaleRecordedSpanRefusesLocateWithZeroWrites(): void
    {
        // The S44a locate leg through real clustered members: move one
        // member's recorded span off its declaration (simulating a mutated
        // checkout between scan and apply — the same class of event the
        // disclosed multi-cluster range-shift law produces for real). The
        // plan phase must refuse loudly, naming the dead span, touching
        // nothing.
        $dir = $this->makeScratchDir();
        $tree = $this->materialize('namespaced_strict', $dir . '/tree');
        $fileHigh = $tree . '/src/NotifyHigh.php';
        $fileLow = $tree . '/src/NotifyLow.php';

        $cluster = $this->findClusterWithMembers($this->runPipeline($tree), ['notify', 'notify']);
        $this->assertNotNull($cluster);
        $preHigh = (string) file_get_contents($fileHigh);
        $preLow = (string) file_get_contents($fileLow);

        $stale = $cluster->members[1];
        $stale->range = new LineRange($stale->range->start + 2, $stale->range->start + 2);

        try {
            (new ApplyExtractor())->apply($cluster, $dir . '/out');
            $this->fail('a member whose recorded span no longer holds it must be refused');
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $this->assertStringContainsString('refusing cluster', $msg);
            $this->assertStringContainsString((string) $stale->range->start, $msg,
                'the refusal names the dead span: ' . $msg);
            $this->assertStringContainsString('stale against the file on disk', $msg);
            $this->assertStringContainsString('No files were written.', $msg,
                'the locate arm rides its own capitalized spine voice');
        }
        $this->assertSame($preHigh, (string) file_get_contents($fileHigh));
        $this->assertSame($preLow, (string) file_get_contents($fileLow));
        $this->assertDirectoryDoesNotExist($dir . '/out');
    }

    public function testMissingMemberFileRefusesWithItsNamedDiagnostic(): void
    {
        // The same-zero-write taxonomy one arm earlier: a member whose file
        // vanished after the scan is refused by name before any byte.
        $dir = $this->makeScratchDir();
        $tree = $this->materialize('namespaced_strict', $dir . '/tree');
        $fileHigh = $tree . '/src/NotifyHigh.php';

        $cluster = $this->findClusterWithMembers($this->runPipeline($tree), ['notify', 'notify']);
        $this->assertNotNull($cluster);
        $preHigh = (string) file_get_contents($fileHigh);
        $fileLow = $tree . '/src/NotifyLow.php';
        unlink($fileLow);

        try {
            (new ApplyExtractor())->apply($cluster, $dir . '/out');
            $this->fail('a missing member file must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('does not exist', $e->getMessage(), $e->getMessage());
            $this->assertStringContainsString($fileLow, $e->getMessage());
            $this->assertStringContainsString('no files were written', $e->getMessage());
        }
        $this->assertSame($preHigh, (string) file_get_contents($fileHigh));
        $this->assertDirectoryDoesNotExist($dir . '/out');
    }

    public function testNestedMemberPairInOneFileRefusesAtCountVerifyWithZeroWrites(): void
    {
        // The S44a nested-member class end to end: at the documented loose
        // knobs the real AntiUnifier merges nestOuterX with the nestInnerX
        // declaration INSIDE it — one cluster, one file, two collected nodes
        // where one stands in the other's subtree. The single replacement
        // pass cannot address the inner node once the outer one is
        // replaced, and the count-verify turns that into a loud, zero-write
        // refusal. (A neutered guard would half-write: outer delegated,
        // inner silently kept its stale body.)
        $dir = $this->makeScratchDir();
        $tree = $this->materialize('nested_pair', $dir . '/tree');
        $fileX = $tree . '/src/BoxX.php';

        $clusters = $this->runPipeline($tree, [
            'minBlockSize' => 2,
            'similarityThreshold' => 0.3,
            'treeThreshold' => 0.5,
        ]);
        $nested = $this->findClusterWithMembers($clusters, ['nestOuterX', 'nestInnerX']);
        $this->assertNotNull($nested, 'the loose knobs must merge a member with its own nested declaration — got: '
            . implode(' | ', array_map(static fn (Cluster $c): string => implode(',', array_map(
                static fn (Block $m): string => (string) $m->name, $c->members)), $clusters)));
        $this->assertCount(1, $nested->holes);
        $this->assertSame('optional_block', $nested->holes[0]->kind,
            'fixture: the merge must ride a presence knob (rows are never re-parsed as expressions)');

        $preX = (string) file_get_contents($fileX);
        try {
            (new ApplyExtractor())->apply($nested, $dir . '/out');
            $this->fail('the nested member pair must be refused, not half-applied');
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $this->assertStringContainsString('refusing cluster', $msg, $msg);
            $this->assertStringContainsString('only 1 of 2 collected member nodes', $msg,
                'the refusal is the count-verify voice with the honest tally: ' . $msg);
            $this->assertStringContainsString('nested under another member', $msg);
            $this->assertStringContainsString('no files were written', $msg);
        }
        $this->assertSame($preX, (string) file_get_contents($fileX));
        $this->assertDirectoryDoesNotExist($dir . '/out');
    }

    // ===================================================== evidence-gate legs

    public function testRoutedStormKeepsASentinelInertSignatureAndRefusesLoudly(): void
    {
        // S57 routing, e2e over the static 33-member corpus: the cluster
        // exceeds MAX_UNIFY_MEMBERS, so the walk goes through
        // representatives and the zero-hole result is degraded by the
        // synthetic whole-template sentinel. The laws pinned: the sentinel
        // never reaches the SIGNATURE (SignatureBuilder and
        // ParameterSynthesizer skip it — neutering either formalizes
        // `$__cap` and flips this red), and the abstraction machinery
        // refuses rather than laundering an unobserved tail.
        $dir = $this->makeScratchDir();
        $tree = $this->materialize('storm', $dir . '/tree');
        $fileStorm = $tree . '/storm33.php';

        $clusters = $this->runPipeline($tree);
        $routed = null;
        foreach ($clusters as $cluster) {
            if ($cluster->size() > AntiUnifier::MAX_UNIFY_MEMBERS) {
                $routed = $cluster;
                break;
            }
        }
        $this->assertNotNull($routed, 'the real pipeline must route the 33-member storm through representatives');
        $this->assertCount(33, $routed->members);

        $placeholders = array_map(static fn ($h): string => $h->placeholder, $routed->holes);
        $this->assertSame([AntiUnifier::CAP_SENTINEL], $placeholders,
            'a routed zero-hole cluster owes exactly the synthetic sentinel hole');
        $this->assertTrue($routed->holes[0]->isDegraded(), 'the tail was never observed — the sentinel says so');

        $this->assertIsString($routed->signature);
        $this->assertStringNotContainsString(AntiUnifier::CAP_SENTINEL, (string) $routed->signature,
            'S57 law: the sentinel is signature-INERT — a neuter of either skip formalizes $__cap');
        $this->assertStringNotContainsString('$__cap', (string) $routed->signature);

        $pre = (string) file_get_contents($fileStorm);
        try {
            (new ApplyExtractor())->apply($routed, $dir . '/out');
            $this->fail('a routed sentinel cluster must not silently apply');
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $this->assertStringContainsString('refusing cluster', $msg, $msg);
            $this->assertStringContainsString('hole placement failed', $msg,
                'the empty sentinel holePath meets the placement guard: ' . $msg);
            $this->assertStringContainsString('<empty path>', $msg);
            $this->assertStringContainsString('no files were written', $msg);
        }
        $this->assertStringNotContainsString('Refactored', (string) file_get_contents($fileStorm),
            'no member of the storm was rewritten');
        $this->assertSame($pre, (string) file_get_contents($fileStorm));
        $this->assertDirectoryDoesNotExist($dir . '/out');
    }

    public function testCliCoverageFloorRefusesNamedAndLeavesTheTreeUntouched(): void
    {
        // S51's F1b evidence gate at the bin level: the real clustered, real
        // holed notify pair runs through `--apply --dry-run=false` with a
        // deliberately low --max-hole-coverage ceiling — the named reason
        // must ride apply-summary.md as a Skipped row, and NOT one byte of
        // the tree may move.
        $dir = $this->makeScratchDir();
        $tree = $this->materialize('namespaced_strict', $dir . '/tree');
        $before = [
            $tree . '/src/NotifyHigh.php' => (string) file_get_contents($tree . '/src/NotifyHigh.php'),
            $tree . '/src/NotifyLow.php'  => (string) file_get_contents($tree . '/src/NotifyLow.php'),
        ];

        $run = $this->cli(['analyze', $tree, '--apply', '--dry-run=false',
            '--max-df=0.5', '--min-block-size=3', '--workers=1', '--min-impact=5', '--max-hole-coverage=0.01'], $dir);
        $this->assertSame(0, $run['exit'], 'a refused apply is a Skipped row, not a crash: ' . $run['combined']);

        $summary = (string) file_get_contents($dir . '/refactored/' . RefactorPatchReporter::APPLY_SUMMARY_FILE);
        $this->assertMatchesRegularExpression(
            '/# cluster [0-9a-zA-Z]+: hole coverage 0\.\d+ exceeds max_hole_coverage 0\.01 — generalized template is mostly holes; apply refused/',
            $summary, 'the refusal reason is NAMED with the measured fraction: ' . $summary);
        $this->assertStringNotContainsString('# Files:', $summary);
        $this->assertStringContainsString('# Applied: 0 clusters', $summary);

        foreach ($before as $path => $original) {
            $this->assertSame($original, (string) file_get_contents($path), basename($path) . ' stands untouched');
        }
        $this->assertDirectoryDoesNotExist($dir . '/refactored/Refactored', 'the gate precedes the write window');
    }

    public function testCliThisHazardGateRefusesNamedAndLeavesTheTreeUntouched(): void
    {
        // S50's detectUnsafe gate at the bin level: widget paint members
        // that touch $this must be refused with the NAMED hazard reason
        // (context-aware extraction required), the cluster falling back to
        // preview-patch posture — while the written tree stays byte-exact
        // and no abstraction lands.
        $dir = $this->makeScratchDir();
        $tree = $this->materialize('unsafe', $dir . '/tree');
        $fileA = $tree . '/widget-a.php';
        $fileB = $tree . '/widget-b.php';
        $before = [
            $fileA => (string) file_get_contents($fileA),
            $fileB => (string) file_get_contents($fileB),
        ];

        $run = $this->cli(['analyze', $tree, '--apply', '--dry-run=false',
            '--max-df=0.5', '--min-block-size=3', '--workers=1', '--min-impact=5'], $dir);
        $this->assertSame(0, $run['exit'], 'an unsafe-gated cluster is a Skipped row, not a crash: ' . $run['combined']);

        $summary = (string) file_get_contents($dir . '/refactored/' . RefactorPatchReporter::APPLY_SUMMARY_FILE);
        $this->assertStringContainsString('member uses $this — needs context-aware extraction', $summary,
            'the refusal names the hazard class: ' . $summary);
        $this->assertStringContainsString('# Skipped:', $summary);
        $this->assertStringNotContainsString('# Files:', $summary);
        $this->assertStringContainsString('# Applied: 0 clusters', $summary);

        foreach ($before as $path => $original) {
            $this->assertSame($original, (string) file_get_contents($path), basename($path) . ' stands byte-exact');
        }
        $this->assertDirectoryDoesNotExist($dir . '/refactored/Refactored', 'the hazard gate precedes the write window');
    }

    // ================================================================ helpers

    private function fixtureRoot(): string
    {
        return dirname(__DIR__) . '/Fixtures/apply';
    }

    private function makeScratchDir(): string
    {
        $dir = sys_get_temp_dir() . '/phpdup_s53_' . uniqid('', true);
        @mkdir($dir, 0o775, true);
        $this->scratch[] = $dir;
        return $dir;
    }

    /**
     * Materialize a fixture unit into a scratch tree, renaming each
     * `<name>.php.dist` to `<name>.php` (the corpus contract in the
     * fixture README: committed sources stay invisible to the frozen
     * CliSmokeTest scan-count pins; the scratch COPY is the live corpus).
     */
    private function materialize(string $unit, string $to, string $sub = ''): string
    {
        $from = $this->fixtureRoot() . '/' . $unit . ($sub !== '' ? '/' . $sub : '');
        $this->assertDirectoryExists($from, "fixture unit {$unit} must ship");
        self::rcopy($from, $to);
        return $to;
    }

    private static function rcopy(string $from, string $to): void
    {
        @mkdir($to, 0o775, true);
        foreach ((array) scandir($from) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($from . '/' . $entry)) {
                self::rcopy($from . '/' . $entry, $to . '/' . $entry);
            } else {
                $dest = $to . '/' . (str_ends_with($entry, '.php.dist')
                    ? substr($entry, 0, -strlen('.dist'))
                    : $entry);
                copy($from . '/' . $entry, $dest);
            }
        }
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
            if (is_dir($path) && !is_link($path)) {
                self::rrmdir($path);
            } else {
                @chmod($path, 0o644);
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * The production pipeline, the S46 house driver — rooted scan paths,
     * genuine AntiUnifier hole/holePaths contract. Tiny scratch corpora
     * need the documented relaxations: the shipped default max_df strips
     * every n-gram of a two-file tree, and the default min-block-size of 8
     * would skip the fixture bodies entirely.
     *
     * @param array<string, mixed> $tweaks named Config overrides
     * @return list<Cluster>
     */
    private function runPipeline(string $tree, array $tweaks = []): array
    {
        $params = [
            'paths' => [$tree],
            'exclude' => Config::defaults([])->exclude,
            'minBlockSize' => 3,
            'maxDocumentFrequency' => 1.0,
            'minClusterImpact' => 1,
            'lazyAst' => false,
        ];
        $config = new Config(...array_merge($params, $tweaks));

        $state = new PipelineState($config);
        $out = new NullOutput();
        (new ScanningStage())->run($state, $out);
        (new PreprocessStage(useCache: false))->run($state, $out);
        (new ClusterStage(exactOnly: false))->run($state, $out);
        (new RefactorStage(useCache: false))->run($state, $out);

        return $state->clusters;
    }

    /**
     * The cluster whose members' names are exactly the wanted multiset.
     * Tiny trees may grow unrelated clusters — those are never applied to.
     *
     * @param list<Cluster> $clusters
     * @param list<string>  $names
     */
    private function findClusterWithMembers(array $clusters, array $names): ?Cluster
    {
        $wanted = $names;
        sort($wanted);
        foreach ($clusters as $cluster) {
            $got = array_map(static fn (Block $m): string => (string) $m->name, $cluster->members);
            sort($got);
            if ($got === $wanted) {
                return $cluster;
            }
        }
        return null;
    }

    /** @param list<Node> $stmts */
    private function firstOfClass(array $stmts): Node\Stmt\Class_
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Namespace_) {
                return $this->firstOfClass((array) $stmt->stmts);
            }
            if ($stmt instanceof Node\Stmt\Class_) {
                return $stmt;
            }
        }
        throw new \LogicException('fixture: the parsed file must declare a class');
    }

    /** The single generated Function_ of an artifact file (under its namespace). */
    private function findArtifactFunction(array $stmts): ?Node\Stmt\Function_
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Namespace_) {
                return $this->findArtifactFunction((array) $stmt->stmts);
            }
            if ($stmt instanceof Node\Stmt\Function_) {
                return $stmt;
            }
        }
        return null;
    }

    /** The knob-truth bit string of a rewritten delegation call. */
    private function knobTruth(Node\Expr\FuncCall $call): string
    {
        $bits = '';
        foreach ($call->args as $arg) {
            $value = $arg->value;
            if ($value instanceof Node\Expr\ConstFetch) {
                $bits .= strtolower((string) $value->name) === 'true' ? '1' : '0';
                continue;
            }
            $this->assertInstanceOf(Node\Expr\Variable::class, $value,
                'member calls pass locals and knob booleans only');
        }
        return $bits;
    }

    private function writeDriver(string $dir, string $tag, string $code): string
    {
        $path = $dir . '/driver_' . $tag . '.php';
        $this->assertNotFalse(file_put_contents($path, $code));
        return $path;
    }

    /** Run one child php process to completion; return COMBINED stdout+stderr. */
    private function runChildPhp(string $script, ?int &$rc = null): string
    {
        $proc = proc_open([PHP_BINARY, $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc, 'proc_open refused ' . $script);
        fclose($pipes[0]);
        $out = '';
        $err = '';
        $deadline = microtime(true) + self::TIMEOUT_S;
        while (true) {
            $status = proc_get_status($proc);
            foreach ([1 => 'out', 2 => 'err'] as $fd => $slot) {
                if (!is_resource($pipes[$fd])) {
                    continue;
                }
                stream_set_blocking($pipes[$fd], false);
                $chunk = stream_get_contents($pipes[$fd]);
                if ($chunk !== '' && $chunk !== false) {
                    $$slot .= $chunk;
                }
            }
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                $this->fail('child php exceeded the wall deadline: ' . $script);
            }
            usleep(20_000);
        }
        foreach ([1, 2] as $fd) {
            if (is_resource($pipes[$fd])) {
                fclose($pipes[$fd]);
            }
        }
        proc_close($proc);
        return $out . $err;
    }

    /**
     * Drive the real bin/phpdup as a child process (CliSmokeTest pattern):
     * closed stdin, wall deadline, throwaway cache via -c, cwd = the
     * scratch root so the default relative apply dir lands inside it.
     *
     * @param list<string> $args
     * @return array{exit: int, out: string, err: string, combined: string}
     */
    private function cli(array $args, string $cwd): array
    {
        $cfgPath = $cwd . '/cfg.json';
        file_put_contents($cfgPath, (string) json_encode(['cache_dir' => $cwd . '/cache']));
        @mkdir($cwd . '/cache', 0o775, true);

        $cmd = array_merge([PHP_BINARY, self::repoRoot() . '/bin/phpdup'], $args, ['-c', $cfgPath]);
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        $this->assertIsResource($proc, 'proc_open refused ' . implode(' ', $cmd));
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = '';
        $err = '';
        $deadline = microtime(true) + self::TIMEOUT_S;
        $outOpen = true;
        $errOpen = true;
        while ($outOpen || $errOpen) {
            $read = array_values(array_filter([$outOpen ? $pipes[1] : null, $errOpen ? $pipes[2] : null]));
            $w = $e = null;
            if ($read !== [] && stream_select($read, $w, $e, 1) > 0) {
                foreach ($read as $stream) {
                    $chunk = fread($stream, 8192);
                    if ($chunk === false || $chunk === '') {
                        fclose($stream);
                        if ($stream === $pipes[1]) {
                            $outOpen = false;
                        } else {
                            $errOpen = false;
                        }
                    } elseif ($stream === $pipes[1]) {
                        $out .= $chunk;
                    } else {
                        $err .= $chunk;
                    }
                }
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                $this->fail('bin/phpdup child exceeded the wall deadline: ' . implode(' ', $cmd));
            }
        }
        $status = proc_get_status($proc);
        // proc_close is the authoritative reaper: at pipe-EOF the child may
        // not be waited yet, and proc_get_status then still reports
        // running=true with a -1 placeholder exitcode (race seen on the
        // fork-heavy legs). proc_close returns the real exit code.
        $exit = proc_close($proc);
        if ($status['running'] && $exit === -1) {
            $this->fail('bin/phpdup child closed its output but never terminated: ' . implode(' ', $cmd));
        }

        return ['exit' => $exit, 'out' => $out, 'err' => $err, 'combined' => $out . $err];
    }

    private function assertPhpLints(string $path, string $message = ''): void
    {
        $lint = shell_exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1');
        $this->assertStringContainsString('No syntax errors', (string) $lint,
            ($message !== '' ? $message . ' — ' : '') . "php -l on {$path}");
    }

    private static function repoRoot(): string
    {
        $root = realpath(dirname(__DIR__, 2));
        if ($root === false || !is_file($root . '/bin/phpdup')) {
            throw new \RuntimeException('apply e2e harness cannot find bin/phpdup above tests/');
        }
        return $root;
    }

    // ====================================================== once-S04 safety closure
    //
    // php-dup-finder-once-004 ("dormant stays dormant until gated"): the
    // armed apply path's three new honest arms — the CLI-level git-dirty
    // gate, the durable backup-before-write window, and the post-write
    // `php -l` belt — plus the dormancy and zero-clobber proofs around
    // them. Everything above (the S53 legs) stays exactly as shipped;
    // these legs only ADD. Red-before-green discipline: each safety arm
    // has a named pin that cannot pass on the pre-closure extractor or
    // the pre-gate Command; the dormancy and tally legs are cure-
    // verifications allowed to pre-green.

    public function testArmedApplyOnCleanGitTreeBacksUpEveryOriginalBeforeRewriting(): void
    {
        // The finding's "backs up before write" acceptance leg, driven at
        // the real bin level: a clean git work tree arms the CLI, and a
        // successful apply leaves each member file's EXACT original bytes
        // readable back under refactored/backup/ — the durable undo that
        // outlives the transaction — while the rewrite and tallies land
        // as the S53 happy leg pinned them. RED on master: no backup
        // concept exists there.
        self::skipWithoutGit();
        $dir = $this->makeScratchDir();
        $tree = $this->materialize('namespaced_strict', $dir . '/tree');
        $fileHigh = $tree . '/src/NotifyHigh.php';
        $fileLow = $tree . '/src/NotifyLow.php';
        $preHigh = (string) file_get_contents($fileHigh);
        $preLow = (string) file_get_contents($fileLow);
        $this->gitIn($dir, 'init', '-q');
        $this->gitIn($dir, 'add', '-A');
        $this->gitIn($dir, '-c', 'user.name=e2e', '-c', 'user.email=e2e@example.invalid', 'commit', '-q', '-m', 'clean baseline');

        $run = $this->cli(['analyze', $tree, '--apply', '--dry-run=false',
            '--max-df=0.5', '--min-block-size=3', '--workers=1', '--min-impact=5'], $dir);
        $this->assertSame(0, $run['exit'], 'clean git tree arms the apply — output: ' . $run['combined']);
        $this->assertStringContainsString('real apply armed', $run['combined'],
            'the armed posture announces itself, with the backup location');
        $this->assertStringContainsString('/backup', $run['combined']);

        $summary = (string) file_get_contents($dir . '/refactored/' . RefactorPatchReporter::APPLY_SUMMARY_FILE);
        $this->assertStringContainsString('# Applied: 1 clusters', $summary);
        $this->assertStringContainsString('# Verified member rewrites: 2 files', $summary);

        foreach ([[$fileHigh, $preHigh], [$fileLow, $preLow]] as [$member, $original]) {
            $backupPath = ApplyExtractor::backupPath($dir . '/refactored', $member);
            $this->assertFileExists($backupPath,
                'the durable original for ' . basename($member) . ' lands under the named backup path');
            $this->assertSame($original, (string) file_get_contents($backupPath),
                'the backup holds the EXACT pre-apply bytes — the undo is real');
            $this->assertSame('0644', substr(sprintf('%o', (int) fileperms($backupPath)), -4),
                'backups ride the generated-file mode (S52 family)');
            $this->assertNotSame((string) file_get_contents($member), (string) file_get_contents($backupPath),
                'the backup is the original — distinct from the file the apply rewrote');
        }

        $this->assertStringContainsString('\\Refactored\\', (string) file_get_contents($fileHigh),
            'the member file itself really was rewritten');
        $artifacts = glob($dir . '/refactored/Refactored/*.php');
        $this->assertIsArray($artifacts);
        $this->assertCount(2, $artifacts, 'backups live OUTSIDE the artifact namespace (the stub globs *.php)');
        foreach ($artifacts as $buffer) {
            $this->assertPhpLints($buffer);
        }
        $this->assertPhpLints($fileHigh);
        $this->assertPhpLints($fileLow);
    }

    public function testArmedApplyRefusesGitDirtyTreeAndUntrackedScratchStillProceeds(): void
    {
        // The finding's "refuses on dirty target tree" acceptance leg at
        // the CLI gate: a TRACKED uncommitted change under the scan root
        // refuses the armed run before any analysis — loud, exit 3 (the
        // gate-trip class), naming the tree and the changed file — and
        // the whole tree stays byte-identical, apply-dir untouched.
        // Scenario B proves the disclosed posture of --untracked-files=no:
        // untracked scratch is not the apply path's business, so the same
        // tree with an UNTRACKED file added still runs clean. RED on
        // master: no gate there — scenario A's exit would be 0.
        self::skipWithoutGit();

        // --- A: tracked change refuses the run.
        $dir = $this->makeScratchDir();
        $tree = $this->materialize('namespaced_strict', $dir . '/tree');
        $fileHigh = $tree . '/src/NotifyHigh.php';
        $fileLow = $tree . '/src/NotifyLow.php';
        $this->gitIn($dir, 'init', '-q');
        $this->gitIn($dir, 'add', '-A');
        $this->gitIn($dir, '-c', 'user.name=e2e', '-c', 'user.email=e2e@example.invalid', 'commit', '-q', '-m', 'clean baseline');
        $this->assertNotFalse(file_put_contents($fileHigh, (string) file_get_contents($fileHigh) . "\n// dirty\n"));
        $preHigh = (string) file_get_contents($fileHigh);
        $preLow = (string) file_get_contents($fileLow);

        $run = $this->cli(['analyze', $tree, '--apply', '--dry-run=false',
            '--max-df=0.5', '--min-block-size=3', '--workers=1', '--min-impact=5'], $dir);
        $this->assertSame(3, $run['exit'], 'dirty work tree trips the gate, exit 3 — output: ' . $run['combined']);
        $this->assertStringContainsString('real apply refused', $run['combined']);
        $this->assertStringContainsString('the target tree is dirty', $run['combined']);
        $this->assertStringContainsString('NotifyHigh.php', $run['combined'], 'the changed file is named');
        $this->assertStringContainsString('No analysis was run and no file was written', $run['combined']);
        $this->assertStringNotContainsString('real apply armed', $run['combined'],
            'a refused gate never announces the armed posture');
        $this->assertSame($preHigh, (string) file_get_contents($fileHigh), 'dirty tree byte-unchanged');
        $this->assertSame($preLow, (string) file_get_contents($fileLow));
        $this->assertDirectoryDoesNotExist($dir . '/refactored', 'the refusal is zero-write');

        // --- B: untracked scratch does NOT refuse (posture proof).
        $dirB = $this->makeScratchDir();
        $treeB = $this->materialize('namespaced_strict', $dirB . '/tree');
        $this->gitIn($dirB, 'init', '-q');
        $this->gitIn($dirB, 'add', '-A');
        $this->gitIn($dirB, '-c', 'user.name=e2e', '-c', 'user.email=e2e@example.invalid', 'commit', '-q', '-m', 'clean baseline');
        $this->assertNotFalse(file_put_contents($dirB . '/tree/notes.txt', "scratch, not tracked\n"));

        $runB = $this->cli(['analyze', $treeB, '--apply', '--dry-run=false',
            '--max-df=0.5', '--min-block-size=3', '--workers=1'], $dirB);
        $this->assertSame(0, $runB['exit'], 'untracked noise never blocks the armed apply — output: ' . $runB['combined']);
        $this->assertFileExists($dirB . '/refactored/' . RefactorPatchReporter::APPLY_SUMMARY_FILE);
    }

    public function testDormantApplyPathsNeverTouchTreeBytesAndWriteNoGeneratedTree(): void
    {
        // "Dormant stays dormant until gated", pinned from both sides:
        // bare `--apply` (preview posture, the S28 default) writes ONLY
        // the apply.diff scaffold into the apply dir and leaves every
        // member byte untouched — no artifact tree, no backups, no
        // summary claim; without --apply at all nothing lands either.
        // Cure-verification leg (may pre-green on master by design — the
        // closure must not move it).
        $dir = $this->makeScratchDir();
        $tree = $this->materialize('namespaced_strict', $dir . '/tree');
        $before = $this->treeDigests($tree);

        $run = $this->cli(['analyze', $tree, '--apply',
            '--max-df=0.5', '--min-block-size=3', '--workers=1', '--min-impact=5'], $dir);
        $this->assertSame(0, $run['exit'], 'preview apply still exits 0 — output: ' . $run['combined']);
        $this->assertStringNotContainsString('real apply armed', $run['combined'],
            'the preview posture never announces the armed apply');
        $this->assertSame($before, $this->treeDigests($tree),
            '--apply without --dry-run=false rewrites NOTHING');
        $this->assertFileExists($dir . '/refactored/' . RefactorPatchReporter::APPLY_DIFF_FILE);
        $this->assertDirectoryDoesNotExist($dir . '/refactored/Refactored');
        $this->assertDirectoryDoesNotExist($dir . '/refactored/backup');
        $this->assertFileDoesNotExist($dir . '/refactored/' . RefactorPatchReporter::APPLY_SUMMARY_FILE);

        $dir2 = $this->makeScratchDir();
        $tree2 = $this->materialize('namespaced_strict', $dir2 . '/tree');
        $before2 = $this->treeDigests($tree2);
        $run2 = $this->cli(['analyze', $tree2, '--max-df=0.5', '--min-block-size=3', '--workers=1'], $dir2);
        $this->assertSame(0, $run2['exit'], 'bare analyze still exits 0 — output: ' . $run2['combined']);
        $this->assertDirectoryDoesNotExist($dir2 . '/refactored', 'no apply flag, no apply dir, ever');
        $this->assertSame($before2, $this->treeDigests($tree2));
    }

    public function testDriftedMemberBetweenPlanAndWindowRefusesTheClusterDirtyAgainstThePlan(): void
    {
        // The window's own DIRTY PRE-WINDOW RE-CHECK: captureSnapshots
        // re-reads each planned member and refuses when the bytes moved
        // after the plan read them — narrowing the scan→apply race and
        // keeping the promised zero-write refusal honest even for callers
        // that bypass the CLI gate. Crafted directly (the race window is
        // microscopic end-to-end): plan bytes recorded, disk drifted.
        // RED on master: captureSnapshots is 4-arg there and trusts the
        // plan bytes outright (ArgumentCountError, not a refusal).
        $dir = $this->makeScratchDir();
        $tree = $this->materialize('namespaced_strict', $dir . '/tree');
        $fileHigh = $tree . '/src/NotifyHigh.php';
        $cluster = $this->findClusterWithMembers($this->runPipeline($tree), ['notify', 'notify']);
        $this->assertNotNull($cluster);
        $original = (string) file_get_contents($fileHigh);

        // --- changed-after-plan: drift the member bytes on disk.
        $this->assertNotFalse(file_put_contents($fileHigh, $original . "\n// raced\n"));
        $planned = [$fileHigh => ['original' => $original, 'rewritten' => $original . "\n// plan\n"]];
        $method = new \ReflectionMethod(ApplyExtractor::class, 'captureSnapshots');
        $outDir = $dir . '/out';
        try {
            $method->invoke(new ApplyExtractor(), $cluster, $planned, $outDir . '/Refactored/f.php', $outDir . '/Refactored/_autoload.php', []);
            $this->fail('a drifted member must refuse the snapshot leg');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('the target tree is dirty against the recorded plan', $e->getMessage());
            $this->assertStringContainsString('changed after the plan read it', $e->getMessage());
            $this->assertStringContainsString($fileHigh, $e->getMessage());
            $this->assertStringContainsString('no files were written', $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist($outDir, 'the refusal still precedes every mkdir');

        // --- unreadable-after-plan: the same voice for a vanished reader.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            return; // house root-guard (S44b precedent): chmod cannot refuse uid 0
        }
        chmod($fileHigh, 0o000);
        try {
            try {
                $method->invoke(new ApplyExtractor(), $cluster, $planned, $outDir . '/Refactored/f.php', $outDir . '/Refactored/_autoload.php', []);
                $this->fail('an unreadable member must refuse the snapshot leg');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('became unreadable after the plan read it', $e->getMessage());
                $this->assertStringContainsString('no files were written', $e->getMessage());
            }
        } finally {
            @chmod($fileHigh, 0o644);
        }
    }

    public function testExistingBackupEvidenceIsNeverClobberedAndProjectedCollisionsRefuse(): void
    {
        // "Backs up before write" carries a non-negotiable corollary:
        // the earliest original is the evidence. A full apply through the
        // REAL flow meets a pre-planted file at its projected backup path
        // and must refuse loudly, keeping the planted bytes and the whole
        // tree untouched, artifact namespace absent. The duplicate-name
        // class (the slug maps `b_c.php` and `b.c.php` onto one file) is
        // refused by the planner itself. RED on master: no backupPlan, no
        // clobber refusal — the first apply would simply succeed.
        $dir = $this->makeScratchDir();
        $tree = $this->materialize('namespaced_strict', $dir . '/tree');
        $fileHigh = $tree . '/src/NotifyHigh.php';
        $cluster = $this->findClusterWithMembers($this->runPipeline($tree), ['notify', 'notify']);
        $this->assertNotNull($cluster);
        $preHigh = (string) file_get_contents($fileHigh);

        $outDir = $dir . '/out';
        $planted = ApplyExtractor::backupPath($outDir, $fileHigh);
        $this->assertNotFalse(mkdir($dir . '/out/backup', 0o775, true));
        $this->assertNotFalse(file_put_contents($planted, "MARKER original evidence"));

        try {
            (new ApplyExtractor())->apply($cluster, $outDir);
            $this->fail('a planted backup target must refuse the whole cluster');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($planted, $e->getMessage(), 'the refusal names the path');
            $this->assertStringContainsString('refusing to overwrite the original evidence', $e->getMessage());
            $this->assertStringContainsString('no files were written', $e->getMessage());
        }
        $this->assertSame('MARKER original evidence', (string) file_get_contents($planted),
            'the earlier evidence survives untouched');
        $this->assertSame($preHigh, (string) file_get_contents($fileHigh), 'zero member churn');
        $this->assertDirectoryDoesNotExist($outDir . '/Refactored', 'no artifact namespace residue');

        // Projected-slug twins: two distinct members, one backup name.
        $planner = new \ReflectionMethod(ApplyExtractor::class, 'backupPlan');
        $planned = [
            '/a/b_c.php' => ['original' => 'x', 'rewritten' => 'y'],
            '/a/b.c.php' => ['original' => 'z', 'rewritten' => 'w'],
        ];
        try {
            $planner->invoke(new ApplyExtractor(), $cluster, $planned, $outDir,
                $outDir . '/Refactored/f.php', $outDir . '/Refactored/_autoload.php');
            $this->fail('one backup path cannot serve two sources');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('project onto the same backup path', $e->getMessage());
            $this->assertStringContainsString('no files were written', $e->getMessage());
        }
    }

    public function testPostWriteLintGateRefusesDivergentBufferAndRollbackComposesItLoudly(): void
    {
        // The finding's "post-rewrite php -l gate fails-closed with
        // rollback". The gate speaks the INTERPRETER, the re-parse guard
        // speaks php-parser, and the two genuinely diverge: S46's named
        // abstract-method-with-body family parses fine and `php -l`
        // fatals — the pinned positive control below. The belt itself is
        // driven two ways: directly over a poisoned buffer (refuses,
        // naming it, quoting the linter), and through the real rollback
        // composition so the user-facing voice carries BOTH the lint
        // clause and the restored-tree clause. RED on master: no gate,
        // no composition.
        $dir = $this->makeScratchDir();

        // Positive control (disclosed doctrine): php-parser accepts what
        // php -l refuses — this is why a belt is needed at all.
        $divergent = "<?php declare(strict_types=1);\nabstract class Divergent {\n"
            . "    abstract public function f(): void { return; }\n}\n";
        $this->assertNotNull((new AstParser())->parseCode($divergent),
            'php-parser accepts the abstract-body shape (the divergence the lint gate belts)');
        $control = $dir . '/divergent.php';
        $this->assertNotFalse(file_put_contents($control, $divergent));
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($control) . ' 2>&1', $lintOut, $lintRc);
        $this->assertNotSame(0, $lintRc, 'the real interpreter refuses exactly what the parser accepted');

        $lintGate = new \ReflectionMethod(ApplyExtractor::class, 'lintAppliedFiles');
        $tree = $this->materialize('namespaced_strict', $dir . '/tree');
        $cluster = $this->findClusterWithMembers($this->runPipeline($tree), ['notify', 'notify']);
        $this->assertNotNull($cluster);

        $poison = $dir . '/poison_member.php';
        $this->assertNotFalse(file_put_contents($poison, "<?php\nfunction broken( { }\n"));
        try {
            $lintGate->invoke(
                new ApplyExtractor(),
                $cluster,
                $control,                       // the artifact slot carries the divergent buffer — checked first
                $tree . '/src/NotifyHigh.php',
                [$tree . '/src/NotifyLow.php']
            );
            $this->fail('the lint gate must refuse buffers php -l refuses');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('failed the post-write `php -l` gate', $e->getMessage());
            $this->assertStringContainsString($control, $e->getMessage(), 'the refusal names the buffer');
            $this->assertStringNotContainsString($poison, $e->getMessage(),
                'the gate stops at the FIRST refusal (per-buffer voice), never a laundry list');
        }

        // The gate walks the MEMBER slots too (clean artifact/stub, poisoned member).
        try {
            $lintGate->invoke(new ApplyExtractor(), $cluster,
                $tree . '/src/NotifyHigh.php', $tree . '/src/NotifyLow.php', [$poison]);
            $this->fail('a poisoned member must refuse the lint gate');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('failed the post-write `php -l` gate', $e->getMessage());
            $this->assertStringContainsString($poison, $e->getMessage(), 'the member refusal names its path');
        }

        // Clean paths pass the same gate (no false refusal on the happy
        // corpus — the direct driver the real window just exercised).
        $lintGate->invoke(new ApplyExtractor(), $cluster,
            $tree . '/src/NotifyLow.php', $tree . '/src/NotifyHigh.php', []);

        // Rollback composition: the in-window cause clause joins the
        // restored-tree voice in ONE loud refusal and the tree comes back
        // byte-exact. A real member, a real snapshot, the lint voice as
        // cause; the window-created backup rides as an absent-before
        // entry — a rolled-back window leaves NO backup residue, it never
        // claims an undo it then deletes under the user's feet.
        $member = $tree . '/src/NotifyHigh.php';
        $before = (string) file_get_contents($member);
        $this->assertNotFalse(file_put_contents($member, "// overwritten by the poisoned window\n" . $before));
        $backup = ApplyExtractor::backupPath($dir . '/out', $member);
        $snapshot = [$member => $before, $backup => false];
        $rollback = new \ReflectionMethod(ApplyExtractor::class, 'rollbackWriteWindow');
        $cause = sprintf('the rewrite of %s failed the post-write `php -l` gate (Parse error)', $member);
        $exception = $rollback->invoke(
            new ApplyExtractor(), $cluster, $snapshot, $dir . '/out', $dir . '/out/Refactored',
            false, false, $cause, $dir . '/out/backup', false
        );
        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertStringContainsString('failed the post-write `php -l` gate', $exception->getMessage(),
            'the lint clause reaches the user');
        $this->assertStringContainsString('write window rolled back', $exception->getMessage());
        $this->assertStringContainsString($member, $exception->getMessage());
        $this->assertSame($before, (string) file_get_contents($member),
            'the rollback restores the tree byte-for-byte under the lint refusal');
        $this->assertFileDoesNotExist($backup,
            'the rolled-back window removes the backup it created — zero residue');
    }

    public function testAppliedTallyEqualsTheReParsedGitDiffCount(): void
    {
        // The finding's count-honesty acceptance: "'Applied: N' count
        // equals re-parsed diff hunks" — operationalised against git
        // itself: the summary's verified-rewrite tally equals the number
        // of changed TRACKED files in `git diff` equals `git status`
        // entries, and 'Applied: N' equals the artifact count minus the
        // stub. Cure-verification leg (the tallies pre-date this closure;
        // the git cross-check is the new proof).
        self::skipWithoutGit();
        $dir = $this->makeScratchDir();
        $tree = $this->materialize('namespaced_strict', $dir . '/tree');
        $this->gitIn($dir, 'init', '-q');
        $this->gitIn($dir, 'add', '-A');
        $this->gitIn($dir, '-c', 'user.name=e2e', '-c', 'user.email=e2e@example.invalid', 'commit', '-q', '-m', 'clean baseline');

        $run = $this->cli(['analyze', $tree, '--apply', '--dry-run=false',
            '--max-df=0.5', '--min-block-size=3', '--workers=1', '--min-impact=5'], $dir);
        $this->assertSame(0, $run['exit'], 'output: ' . $run['combined']);

        $summary = (string) file_get_contents($dir . '/refactored/' . RefactorPatchReporter::APPLY_SUMMARY_FILE);
        $this->assertSame(1, preg_match('/# Applied: (\d+) clusters/', $summary, $applied));
        $this->assertSame(1, preg_match('/# Verified member rewrites: (\d+) files/', $summary, $rewrites));

        $diff = $this->gitIn($dir, '-c', 'core.quotepath=false', 'diff', '--unified=0');
        $diffFiles = array_values(preg_grep('/^\\+\\+\\+ b\\//', explode("\n", $diff['out'])));
        $status = $this->gitIn($dir, 'status', '--porcelain', '--untracked-files=no');
        $statusRows = array_values(array_filter(
            explode("\n", $status['out']),
            static fn (string $l): bool => trim($l) !== ''
        ));

        $this->assertCount((int) $rewrites[1], $statusRows,
            'git sees exactly as many changed tracked files as the summary tallied');
        $this->assertCount((int) $rewrites[1], $diffFiles,
            'the re-parsed diff carries one +++ header per verified rewrite');
        $this->assertSame(2, (int) $rewrites[1], 'the notify pair: two member rewrites');
        $this->assertSame(1, (int) $applied[1], 'one cluster applied');
        $artifacts = glob($dir . '/refactored/Refactored/*.php');
        $this->assertIsArray($artifacts);
        $this->assertCount((int) $applied[1] + 1, $artifacts, 'Applied: N artifacts == generated files minus the stub');
    }

    // ------------------------------------------------------ S04 helpers

    /**
     * Skip (named, honestly) when the machine has no git at all — the
     * gate legs and the diff cross-check are about git, and the house
     * precedent for environment-absent legs is a named markTestSkipped,
     * never a silent pass.
     */
    private static function skipWithoutGit(): void
    {
        exec('git --version 2>&1', $out, $rc);
        if ($rc !== 0) {
            self::markTestSkipped('git is not available on this machine (' . implode(' ', $out) . ')');
        }
    }

    /**
     * Run one git child inside $cwd (test-harness sibling of the
     * production gate helper, same doctrine: argv form, closed stdin,
     * proc_close authoritative). Asserts success — a red test on git
     * tooling failure is honest, never a silent skip.
     *
     * @return array{exit: int, out: string}
     */
    private function gitIn(string $cwd, string ...$args): array
    {
        $proc = proc_open(array_merge(['git'], $args),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        $this->assertIsResource($proc, 'proc_open refused git ' . implode(' ', $args));
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, 'git ' . implode(' ', $args) . ' failed: ' . $out . $err);

        return ['exit' => $exit, 'out' => $out];
    }

    /** @return array<string, string> path => sha256 over every file under $root */
    private function treeDigests(string $root): array
    {
        $digests = [];
        $stack = [$root];
        while ($stack !== []) {
            $dir = array_pop($stack);
            foreach ((array) scandir($dir) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $dir . '/' . $entry;
                if (is_dir($path) && !is_link($path)) {
                    $stack[] = $path;
                } elseif (is_file($path)) {
                    $digest = hash_file('sha256', $path);
                    $this->assertNotFalse($digest);
                    $digests[$path] = $digest;
                }
            }
        }
        ksort($digests);
        return $digests;
    }
}
