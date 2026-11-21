<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Pipeline\Stages;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Cli\Command;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
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
}
