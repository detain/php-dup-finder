<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Pipeline\Stages;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Cli\Command;
use Phpdup\Extraction\Block;
use Phpdup\Pipeline\Pipeline;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Pipeline\StageInterface;
use Phpdup\Refactor\ScriptKindApplyRefusal;
use Phpdup\Util\LineRange;
use Phpdup\Watch\FileChangeType;
use Phpdup\Watch\WatchRunner;
use PhpParser\Node\Stmt\Nop;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class ScanningStageTest extends TestCase
{
    /** @var list<string> scratch dirs (tmp git repos) to remove in tearDown */
    private array $tmpDirs = [];

    private int $dirSeq = 0;

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            self::rrmdir($dir);
        }
        $this->tmpDirs = [];
    }

    public function testPopulatesFilesAndCounters(): void
    {
        $config = Config::defaults([__DIR__ . '/../../../Fixtures/sql']);
        $state  = new PipelineState($config);

        (new ScanningStage())->run($state, new NullOutput());

        $this->assertNotEmpty($state->files);
        $this->assertSame(count($state->files), $state->totalFiles);
        $this->assertSame(count($state->files), $state->scannedFiles);
        foreach ($state->files as $f) {
            $this->assertStringEndsWith('.php', $f);
        }
    }

    public function testFilesAreSorted(): void
    {
        $config = Config::defaults([__DIR__ . '/../../../Fixtures']);
        $state  = new PipelineState($config);

        (new ScanningStage())->run($state, new NullOutput());

        $sorted = $state->files;
        sort($sorted);
        $this->assertSame($sorted, $state->files);
    }

    public function testReportsName(): void
    {
        $this->assertSame(Stage::Scanning, (new ScanningStage())->name());
    }

    // ------------------------------------------------------------------
    // shell-S23 — php-dup-finder-shell-003 (argv-safe git) +
    // php-dup-finder-shell-040 (per-root repo, stderr split, early abort)
    // ------------------------------------------------------------------

    public function testHostileDiffBaseIsRefusedByGitAndWritesNothing(): void
    {
        $repo = $this->makeRepo([['m1.php', "<?php\nfunction m1() { return 1; }\n"]]);

        // shell-003 original: escapeshellarg'd `--output=/tmp/x` was ONE
        // argv token, git parsed it as an OPTION and the appended `..HEAD`
        // silently rode along in the value — arbitrary file write. With the
        // shell-less argv + `--end-of-options` framing, the token can only
        // reach the revision parser, which refuses it.
        $hostile = '--output=' . $repo . '/PWNED';
        $config  = Config::defaults([$repo])->withOverrides(['diff_base' => $hostile]);
        $state   = new PipelineState($config);

        (new ScanningStage())->run($state, new BufferedOutput());

        $this->assertNotNull($state->scanError, 'git must refuse the option-shaped ref');
        $this->assertStringContainsString('--diff-base git diff failed', (string) $state->scanError);
        $this->assertStringContainsString('PWNED', (string) $state->scanError, 'git refusal must surface in the message');
        $this->assertSame([], $state->files, 'failure aborts BEFORE discovery — nothing scanned');
        $this->assertNull($state->diffBaseFiles);
        clearstatcache();
        $this->assertFileDoesNotExist($repo . '/PWNED');
        $this->assertFileDoesNotExist($repo . '/PWNED..HEAD');
    }

    public function testHostileDiffBaseExitsTwoWithoutPipelineContinuation(): void
    {
        $repo = $this->makeRepo([['m1.php', "<?php\nfunction m1() { return 1; }\n"]]);
        $hostile = '--output=' . $repo . '/PWNED';

        $tester = $this->commandTester();
        $exit   = $tester->execute(
            ['paths' => [$repo], '--diff-base' => $hostile],
            ['capture_stderr_separately' => true],
        );

        $combined = $tester->getErrorOutput() . $tester->getDisplay();
        $this->assertSame(2, $exit, 'invalid diff-base exits 2 (docs contract); display was: ' . $combined);
        $this->assertStringContainsString('--diff-base git diff failed', $combined);
        // Early-abort posture: the run must not continue through the
        // pipeline — no discovery completion line, no diff-base info line,
        // no "extracting/analyzing" continuation, no report output.
        $this->assertStringNotContainsString('scanning ', $combined);
        $this->assertStringNotContainsString('changed file(s)', $combined);
        $this->assertStringNotContainsString('block kinds', $combined);
        $this->assertStringNotContainsString('clusters', $combined);
        // M-1 (fix round 1): the abandoned-generator half of shell-040 is
        // observable only in the line the pump-break mutant emits — the
        // report tail's zero-work summary ("(0 reused · 0 processed ...)").
        // Without the scanError break in Command's pump the run still exits
        // 2 with the right message, so this is the sole discriminator.
        $this->assertStringNotContainsString(
            'reused',
            $combined,
            'downstream stages must never run once scanError aborts the pump',
        );
        clearstatcache();
        $this->assertFileDoesNotExist($repo . '/PWNED');
    }

    public function testGitStderrNeverBecomesFilenames(): void
    {
        // A branch and a tag with the same name make git print
        // "warning: refname 'amb' is ambiguous." on stderr while still
        // succeeding (exit 0). The old `2>&1` merged that line into the
        // filename list; now stderr is kept separate end-to-end.
        $repo = $this->makeRepo([['amb1.php', "<?php\nfunction amb1() { return 1; }\n"]], ambiguousRef: 'amb');

        // M-2 (fix round 1): plant a real UNTRACKED file named exactly the
        // line git prints on stderr (verified byte-for-byte on git 2.43).
        // The pre-fix posture merged stderr into the parsed stdout stream,
        // where the only guard against warning-poisoning was the
        // file_exists($root.'/'.$line) gate — and here that gate would
        // PASS. Real code never parses stderr at all, so the decoy must
        // stay out of the collected list.
        $decoyPath = $repo . "/warning: refname 'amb' is ambiguous.";
        $this->assertNotFalse(file_put_contents($decoyPath, ''), 'decoy filename must be creatable');

        $config = Config::defaults([$repo])->withOverrides(['diff_base' => 'amb']);
        $state  = new PipelineState($config);
        (new ScanningStage())->run($state, new BufferedOutput());

        $this->assertNull($state->scanError, 'a warning-bearing ref must not fail the run: ' . var_export($state->scanError, true));
        $this->assertNotNull($state->diffBaseFiles);
        foreach ((array) $state->diffBaseFiles as $f) {
            $this->assertStringNotContainsString('warning', $f);
            $this->assertStringNotContainsString('ambiguous', $f);
            $this->assertFileExists($f);
        }
        // The changed file of the HEAD commit must still be collected.
        $this->assertContains(realpath($repo . '/amb1.php'), $state->diffBaseFiles);
        clearstatcache();
        $decoyReal = realpath($decoyPath);
        $this->assertNotFalse($decoyReal);
        $this->assertNotContains(
            $decoyReal,
            $state->diffBaseFiles,
            'a stderr warning line must never enter the filename list, even when a file of that exact name exists',
        );
    }

    public function testPerRootProbesEachNamedRepository(): void
    {
        // shell-040: git used to run once in the PROCESS cwd — analyzing
        // two separate repos with --diff-base collected from the wrong
        // repository entirely (this test runs with the phpunit cwd inside
        // the phpdup repo, so the old code found zero matching paths).
        $repoA = $this->makeRepo([['alpha.php', "<?php\nfunction alpha() { return 1; }\n"]]);
        $repoB = $this->makeRepo([['beta.php', "<?php\nfunction beta() { return 2; }\n"]]);

        $config = Config::defaults([$repoA, $repoB])->withOverrides(['diff_base' => 'HEAD~1']);
        $state  = new PipelineState($config);
        (new ScanningStage())->run($state, new BufferedOutput());

        $this->assertNull($state->scanError);
        $expected = [realpath($repoA . '/alpha.php'), realpath($repoB . '/beta.php')];
        sort($expected);
        $actual = $state->diffBaseFiles;
        sort($actual);
        $this->assertSame($expected, $actual, 'each root must be probed in its own repository');
    }

    public function testNonRepositoryRootFailsHonestly(): void
    {
        $dir = $this->scratchDir('nonrepo'); // plain dir — no git init

        // L-1 (fix round 1): populate the dir so this test can witness
        // walk-before-abort. With the probe postponed behind discovery
        // (the pre-shell-S23 posture) the scan would find this file first
        // and the assertSame([], $state->files) below flips red — on an
        // empty dir that pin was blind (files genuinely empty either way).
        file_put_contents($dir . '/seen.php', "<?php\nfunction seen() { return 1; }\n");

        $config = Config::defaults([$dir])->withOverrides(['diff_base' => 'HEAD~1']);
        $state  = new PipelineState($config);
        (new ScanningStage())->run($state, new BufferedOutput());

        $this->assertNotNull($state->scanError, 'a non-repo root must abort, not pretend');
        $this->assertStringContainsString($dir, (string) $state->scanError);
        $this->assertStringContainsString('--diff-base git diff failed', (string) $state->scanError);
        $this->assertSame([], $state->files);
    }

    public function testHappyPathCollectsChangedFilesWithUnchangedOutput(): void
    {
        $repo = $this->makeRepo([['hp1.php', "<?php\nfunction hp1() { return 1; }\n"]]);

        $config = Config::defaults([$repo])->withOverrides(['diff_base' => 'HEAD~1']);
        $state  = new PipelineState($config);
        $buf    = new BufferedOutput();
        (new ScanningStage())->run($state, $buf);

        $this->assertNull($state->scanError);
        $this->assertSame([realpath($repo . '/hp1.php')], $state->diffBaseFiles);
        $display = str_replace("\r", '', $buf->fetch());
        // Message text and line order are the pre-shell-S23 happy-path shape.
        $this->assertStringContainsString(
            'phpdup diff-base HEAD~1: 1 changed file(s) from git diff --name-only HEAD~1..HEAD',
            $display,
        );
        $this->assertMatchesRegularExpression(
            '/diff-base HEAD~1:.*\n.*scanning 1 path\(s\)/',
            $display,
        );
    }

    public function testHappyPathCommandProbeExitsZero(): void
    {
        $repo = $this->makeRepo([['hp2.php', "<?php\nfunction hp2() { return 1; }\n"]]);

        $tester = $this->commandTester();
        $exit   = $tester->execute(['paths' => [$repo], '--diff-base' => 'HEAD~1']);

        $this->assertSame(0, $exit, 'happy --diff-base run keeps exiting 0; display was: ' . $tester->getDisplay());
        $this->assertStringContainsString(
            'diff-base HEAD~1: 1 changed file(s) from git diff --name-only HEAD~1..HEAD',
            $tester->getDisplay(),
        );
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * @param list<array{0: string, 1: string}> $secondCommitFiles files added/changed in the HEAD commit
     */
    private function makeRepo(array $secondCommitFiles, ?string $ambiguousRef = null): string
    {
        $dir = $this->scratchDir('repo');
        $this->git($dir, ['init', '-q', '-b', 'main']);
        $this->git($dir, ['commit', '-q', '--allow-empty', '-m', 'init']);
        if ($ambiguousRef !== null) {
            $this->git($dir, ['tag', $ambiguousRef]);
            $this->git($dir, ['branch', $ambiguousRef]);
        }
        foreach ($secondCommitFiles as [$name, $contents]) {
            file_put_contents($dir . '/' . $name, $contents);
            $this->git($dir, ['add', '--', $name]);
        }
        $this->git($dir, ['commit', '-q', '-m', 'change']);

        return $dir;
    }

    /** @param list<string> $args git subcommand argv (run inside $dir) */
    private function git(string $dir, array $args): void
    {
        $argv = ['git', '-C', $dir,
            '-c', 'user.email=test@phpdup.invalid',
            '-c', 'user.name=phpdup-test',
            '-c', 'commit.gpgsign=false',
            '-c', 'core.hooksPath=/dev/null',
            ...$args,
        ];
        $cmd = implode(' ', array_map('escapeshellarg', $argv));
        $out = [];
        $code = 0;
        exec($cmd . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, 'git ' . implode(' ', $args) . ' failed: ' . implode("\n", $out));
    }

    private function scratchDir(string $tag): string
    {
        $dir = sys_get_temp_dir() . '/phpdup-s23-' . $tag . '-' . (++$this->dirSeq) . '-' . getmypid() . '-' . spl_object_id($this);
        if (!is_dir($dir)) {
            $this->assertNotFalse(mkdir($dir, 0777, true));
        }
        $this->tmpDirs[] = $dir;

        return $dir;
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                self::rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function commandTester(): CommandTester
    {
        $app = new Application('phpdup', '0.1.0');
        $app->add(new Command());

        return new CommandTester($app->find('analyze'));
    }

    // ── shell-S34 — php-dup-finder-shell-025: escape untrusted FS bytes ───
    //
    // Render-side pins for the sinks whose bytes arrive raw: the stage's
    // diff-base echo and discovery debug line (ScanningStage), the pump's
    // scanError and typed-halt renders (Command::dispatch), and the watch
    // change/failure lines (WatchRunner). OutputFormatter::escape() is
    // identity for innocuous values, so every pre-existing render pin in
    // this file (and in tests/Unit/Watch/WatchRunnerTest.php) holds
    // byte-exact — those suites are the anti-collateral belt here.

    public function testS34StageDiffBaseEchoEscapesMarkupRef(): void
    {
        $repo = $this->makeRepo([['hp3.php', "<?php\nfunction hp3() { return 3; }\n"]]);
        // Git accepts <> in ref names (check-ref-format verified at lane
        // time) — so the echo is the ONLY defence between argv bytes and
        // the formatter.
        $this->git($repo, ['tag', '<error>boom', 'HEAD~1']);

        $config = Config::defaults([$repo])->withOverrides(['diff_base' => '<error>boom']);
        $state  = new PipelineState($config);
        $buf    = new BufferedOutput();
        (new ScanningStage())->run($state, $buf);

        $this->assertNull($state->scanError, 'the weird ref must still resolve: ' . var_export($state->scanError, true));
        $display = str_replace("\r", '', $buf->fetch());
        $this->assertStringContainsString(
            'phpdup diff-base <error>boom: 1 changed file(s) from git diff --name-only <error>boom..HEAD',
            $display,
            'ref bytes must arrive literally — pre-escape Symfony consumes <error> as markup',
        );
    }

    public function testS34DebugDiscoveryLineEscapesMarkupPaths(): void
    {
        $dir = $this->scratchDir('dbg100');
        // Every candidate name carries markup, so the one line the leg
        // emits (totalFiles hits 100) is guaranteed to hold a raw tag
        // whatever walk order the filesystem used.
        for ($k = 0; $k < 100; $k++) {
            $this->assertNotFalse(file_put_contents($dir . '/f' . $k . "<error>z.php", "<?php\nfunction f{$k}() { return 1; }\n"));
        }

        $config = Config::defaults([$dir]);
        $state  = new PipelineState($config);
        $buf    = new BufferedOutput(BufferedOutput::VERBOSITY_DEBUG);
        (new ScanningStage())->run($state, $buf);

        $display = str_replace("\r", '', $buf->fetch());
        $this->assertStringContainsString('discovered file', $display, 'the debug leg must have fired');
        $this->assertMatchesRegularExpression(
            '/\d+<error>z\.php/',
            $display,
            'the discovered path must be rendered literally, not consumed as markup',
        );
    }

    public function testS34ScanErrorRenderEscapesMarkupBytes(): void
    {
        $dir  = $this->scratchDir('pump-<error>wn');
        $this->assertNotFalse(file_put_contents($dir . '/seen.php', "<?php\nfunction seen() { return 1; }\n"));

        // Post-once-S34, admission nulls diff_base on the analyze pipeline
        // config, so the scanError pump render is no longer reachable via
        // CommandTester — drive the private dispatch() directly. The
        // constructed scanError string stays RAW (its substring pins prove
        // that); only the render is escaped.
        $config = Config::defaults([$dir])->withOverrides(['diff_base' => 'HEAD~1']);
        $output = new BufferedOutput();
        $code   = $this->driveDispatch(
            $config,
            static fn (): Pipeline => new Pipeline([new ScanningStage()]),
            $output,
        );
        // fetch() consumes — capture once, then assert.
        $display = str_replace("\r", '', $output->fetch());

        $this->assertSame(2, $code, 'rendered display was: ' . $display);
        $this->assertStringContainsString('--diff-base git diff failed in ' . $dir, $display);
        $this->assertStringContainsString('<error>wn', $display, 'the path markup must survive as literal text');
    }

    public function testS34TypedHaltRenderEscapesMarkupBytes(): void
    {
        $dir    = $this->scratchDir('pump-halt');
        $config = Config::defaults([$dir]);
        $stage  = new class implements StageInterface {
            public function name(): Stage
            {
                return Stage::Clustering;
            }

            public function run(PipelineState $state, OutputInterface $output): void
            {
                throw ScriptKindApplyRefusal::forBlock(
                    new Block('/tmp/p<error>q.php', new LineRange(1, 2), 'script', null, null, null, new Nop()),
                    'ast loader',
                );
            }
        };

        $output = new BufferedOutput();
        $code   = $this->driveDispatch(
            $config,
            // NOT static: the arrow fn must bind $stage.
            fn (): Pipeline => new Pipeline([$stage]),
            $output,
        );
        $display = str_replace("\r", '', $output->fetch());

        $this->assertSame(1, $code, 'a typed runtime refusal keeps exit 1 (ft-n2 posture); display was: ' . $display);
        $this->assertStringContainsString(
            '/tmp/p<error>q.php',
            $display,
            'the refusal message embeds the block file path — raw bytes must render literally',
        );
    }

    public function testS34WatchChangeAndFailureLinesEscapeUntrustedBytes(): void
    {
        $scratch = $this->scratchDir('watchesc');
        $this->assertNotFalse(file_put_contents($scratch . '/a.php', "<?php\nfunction a() { return 1; }\n"));

        $crashStage = new class implements StageInterface {
            public function name(): Stage
            {
                return Stage::Preprocessing;
            }

            public function run(PipelineState $state, OutputInterface $output): void
            {
                throw new \RuntimeException('poisoned <error>cache row');
            }
        };

        $output = new BufferedOutput();
        $runner = new WatchRunner(
            new Pipeline([$crashStage]),
            static fn (): PipelineState => new PipelineState(Config::defaults([sys_get_temp_dir()])),
            $output,
            new NullLogger(),
            $scratch,
        );
        // Public seam convention (WatchRunnerTest): drive handleChange
        // directly, never enter the blocking loop.
        $runner->handleChange('a<error>b.php', FileChangeType::Modified);

        $display = str_replace("\r", '', $output->fetch());
        $this->assertStringContainsString(
            'change detected (modified: a<error>b.php)',
            $display,
            'the watched path must render literally inside the line',
        );
        $this->assertStringContainsString(
            'watch pass failed (RuntimeException): poisoned <error>cache row',
            $display,
            'the crash message bytes (which routinely carry paths) must render literally',
        );

        // The typed-halt arm of runGuarded (the watch mirror of the
        // Command pump catch): ScriptKindApplyRefusal embeds a path too.
        $haltStage = new class implements StageInterface {
            public function name(): Stage
            {
                return Stage::Refactoring;
            }

            public function run(PipelineState $state, OutputInterface $output): void
            {
                throw ScriptKindApplyRefusal::forBlock(
                    new Block('/tmp/p<error>q.php', new LineRange(1, 2), 'script', null, null, null, new Nop()),
                    'ast loader',
                );
            }
        };
        $haltOut  = new BufferedOutput();
        $haltRun  = new WatchRunner(
            new Pipeline([$haltStage]),
            static fn (): PipelineState => new PipelineState(Config::defaults([sys_get_temp_dir()])),
            $haltOut,
            new NullLogger(),
            $scratch,
        );
        $this->assertTrue($haltRun->runGuarded(new PipelineState(Config::defaults([$scratch]))));
        $this->assertStringContainsString(
            '/tmp/p<error>q.php',
            str_replace("\r", '', $haltOut->fetch()),
            'the typed-halt line embeds the block file path — escape at the render',
        );
    }

    /**
     * Drive Command's private dispatch() pump (the sole render site for
     * the scanError and typed-halt lines outside the watch loop). The
     * plain-path branch reads only the tuiMode/watchMode keys of $opts
     * before the renders under test.
     */
    private function driveDispatch(Config $config, \Closure $buildPipeline, BufferedOutput $output): int
    {
        $dispatch = new \ReflectionMethod(Command::class, 'dispatch');

        return $dispatch->invoke(
            new Command(),
            new ArrayInput([]),
            $output,
            $config,
            $buildPipeline,
            ['tuiMode' => false, 'watchMode' => false],
            null,
        );
    }
}
