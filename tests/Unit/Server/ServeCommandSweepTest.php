<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Server;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\ServeCommand;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * once-S71 (php-dup-finder-once-085): the startup stale-results sweep and
 * the tokenless exposure notice.
 *
 * Landing note: this file is NEW and outside the step's lock paths — the
 * plan's acceptance explicitly demands the probes it pins ("stale
 * /tmp/phpdup-results-* from dead pids pruned at startup (count assert);
 * 0700 recheck"), following the fa8bd7a precedent for plan-demanded new
 * test files. The two pre-existing ServeCommand test files are untouched.
 *
 * The sweep tests work in a private scratch directory (the `?string
 * $tempDir` seam of {@see ServeCommand::sweepStaleResultsDirs()}), never
 * against the shared /tmp, and use pids above the kernel's hard ceiling
 * (4194304) as the provably-dead arm: such a pid cannot exist, so no
 * liveness race can resurrect the directory between the probe and the
 * assertion. The live arm runs a real `sleep` child.
 *
 * Fix-round-1 (r1 MEDIUM-1): the two startup legs inside `execute()` — the
 * sweep call and the SECURITY notice writeln — were unpinned (the r1 `mw`
 * mutant neutered both call sites and the whole file stayed green). The
 * pins added here close that gap through the repo's two precedent shapes:
 * a pre-bind output pin (a tokenless run forced to fail at the bind
 * refusal, pattern tests/Unit/Cli/ServeCommandTest.php:373) and a
 * zero-race source-order pin (pattern tests/Unit/Server/ServeCommandTest.php:336).
 * No real-/tmp litter is planted and the sweep COUNT line is never
 * asserted from a run: sibling serve boots sweep the same shared /tmp.
 */
final class ServeCommandSweepTest extends TestCase
{
    /** Pids beyond the Linux hard ceiling — impossible processes, never reusable. */
    private const DEAD_PID = 4199999;
    private const DEAD_PID_2 = 4199998;
    private const DEAD_PID_3 = 4199997;

    /** Scratch temp dir for this test ('' = nothing to clean). */
    private string $scratch = '';

    /** Live child pid spawned for the liveness arm (killed in tearDown). */
    private ?int $livePid = null;

    /** A results dir under the REAL temp dir (created by the 0700 recheck or the run-to-bind pin). */
    private string $realResultsDir = '';

    protected function tearDown(): void
    {
        if ($this->livePid !== null) {
            if (function_exists('posix_kill') && defined('SIGKILL')) {
                @posix_kill($this->livePid, SIGKILL);
            } else {
                @exec('kill -9 ' . $this->livePid . ' 2>/dev/null');
            }
            $this->livePid = null;
        }
        if ($this->realResultsDir !== '') {
            foreach (glob($this->realResultsDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->realResultsDir);
            $this->realResultsDir = '';
        }
        if ($this->scratch !== '') {
            $this->rrmdir($this->scratch);
            $this->scratch = '';
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // sweepStaleResultsDirs: the count assert
    // ------------------------------------------------------------------

    public function testSweepRemovesDeadOwnDirsAndCountsOnlyFullRemovals(): void
    {
        $this->requireLivenessOracle();
        $temp = $this->scratchTemp();

        $first = $this->makeResultsDir($temp, self::DEAD_PID, ['a.json', 'b.json']);
        $blocked = $this->makeResultsDir($temp, self::DEAD_PID_2, ['c.json']);
        mkdir($blocked . '/stray'); // never ours to delete → rmdir fails
        $third = $this->makeResultsDir($temp, self::DEAD_PID_3, ['d.json']);

        $swept = (new ServeCommand())->sweepStaleResultsDirs($temp);

        $this->assertSame(2, $swept, 'only fully-removed directories count');
        $this->assertDirectoryDoesNotExist($first);
        $this->assertDirectoryDoesNotExist($third);
        $this->assertDirectoryExists($blocked);
        $this->assertFileDoesNotExist($blocked . '/c.json', 'plain files are unlinked');
        $this->assertDirectoryExists($blocked . '/stray', 'the stray subdir survives, untouched');
    }

    public function testSweepSkipsOwnPidLivePidForeignOwnerSymlinksAndGarbageNames(): void
    {
        $this->requireLivenessOracle();
        $temp = $this->scratchTemp();

        $own = $this->makeResultsDir($temp, getmypid(), ['mine.json']);
        $live = $this->makeResultsDir($temp, $this->liveChildPid(), ['running.json']);
        $foreign = $this->makeResultsDir($temp, self::DEAD_PID, ['theirs.json']);

        // A dead-pid name that is a SYMLINK — the tree it points at lives
        // OUTSIDE the swept directory so "never deletes through a link" is
        // observable as the target surviving whole.
        $outside = $temp . '/link-target-real';
        mkdir($outside);
        file_put_contents($outside . '/payload.json', 'keep');
        $symlinked = $temp . '/' . ServeCommand::RESULTS_DIR_PREFIX . self::DEAD_PID_3;
        symlink($outside, $symlinked);

        $garbageDir = $temp . '/' . ServeCommand::RESULTS_DIR_PREFIX . 'notapid';
        mkdir($garbageDir);
        $garbageFile = $temp . '/' . ServeCommand::RESULTS_DIR_PREFIX . '88888888';
        file_put_contents($garbageFile, 'x');

        $command = new ServeCommand();
        $command->ownerProbe = static fn (string $dir): int|false
            => $dir === $foreign ? getmyuid() + 7 : fileowner($dir);

        $swept = $command->sweepStaleResultsDirs($temp);

        $this->assertSame(0, $swept);
        $this->assertDirectoryExists($own);
        $this->assertFileExists($own . '/mine.json');
        $this->assertDirectoryExists($live);
        $this->assertFileExists($live . '/running.json');
        $this->assertDirectoryExists($foreign);
        $this->assertFileExists($foreign . '/theirs.json');
        $this->assertDirectoryExists($symlinked);
        $this->assertFileExists($symlinked . '/payload.json', 'sweep never deletes through a symlink');
        $this->assertDirectoryExists($garbageDir, 'non-numeric suffix is not a results dir');
        $this->assertFileExists($garbageFile, 'a plain file wearing the prefix is not a dir');
    }

    public function testSweepTreatsUnstatableOwnerAsForeignFailClosed(): void
    {
        $this->requireLivenessOracle();
        $temp = $this->scratchTemp();

        $dir = $this->makeResultsDir($temp, self::DEAD_PID, ['x.json']);

        $command = new ServeCommand();
        $command->ownerProbe = static fn (string $dir): ?int => null;

        $this->assertSame(0, $command->sweepStaleResultsDirs($temp));
        $this->assertDirectoryExists($dir);
    }

    public function testSweepOnMissingTempDirIsZeroAndHarmless(): void
    {
        $missing = sys_get_temp_dir() . '/phpdup-sweep-test-nope-' . getmypid();
        $this->assertDirectoryDoesNotExist($missing);

        $this->assertSame(0, (new ServeCommand())->sweepStaleResultsDirs($missing));
    }

    // ------------------------------------------------------------------
    // 0700 recheck: sweep-adjacent creation is still private by birth
    // ------------------------------------------------------------------

    public function testPrepareResultsDirStaysPrivate0700AlongsideStaleDirs(): void
    {
        $this->requireLivenessOracle();
        $temp = $this->scratchTemp();

        // A 0755-looking leftover of a dead run under scratch must not
        // influence the fresh dir's mode, and must itself be removed.
        $stale = $this->makeResultsDir($temp, self::DEAD_PID, ['old.json']);
        chmod($stale, 0755);

        $swept = (new ServeCommand())->sweepStaleResultsDirs($temp);
        $this->assertSame(1, $swept, 'a world-readable dead leftover is still ours to sweep');
        $this->assertDirectoryDoesNotExist($stale);

        $dir = (new ServeCommand())->prepareResultsDir();
        $this->assertNotNull($dir);
        $this->realResultsDir = $dir;

        clearstatcache(true, $dir);
        $mode = fileperms($dir);
        $this->assertNotFalse($mode);
        $this->assertSame(0700, $mode & 0777, 'results dir is private by birth, sweep or no sweep');
    }

    // ------------------------------------------------------------------
    // tokenlessExposureNotice: the warning arms
    // ------------------------------------------------------------------

    public function testNoticeIsSilentWhenTokenSet(): void
    {
        $this->assertSame('', ServeCommand::tokenlessExposureNotice('secret', '/srv/app'));
        // an EMPTY token string is "no token" (validateConfig treats it so):
        // the notice must fire rather than hide behind --token ''.
        $this->assertNotSame('', ServeCommand::tokenlessExposureNotice('', '/srv/app'));
        $this->assertNotSame('', ServeCommand::tokenlessExposureNotice(null, '/srv/app'));
    }

    public function testNoticeNamesTheExposureTheConfinementAndTheRemedy(): void
    {
        $notice = ServeCommand::tokenlessExposureNotice(null, '/srv/app');

        $this->assertStringContainsString('SECURITY', $notice);
        $this->assertStringContainsString('without --token', $notice);
        $this->assertStringContainsString('every user on this machine', $notice);
        $this->assertStringContainsString('loopback confines the network, not the other users', $notice);
        $this->assertStringContainsString('confined to serve root /srv/app', $notice);
        $this->assertStringContainsString('Pass --token', $notice);
        $this->assertStringContainsString('docs/SERVER.md', $notice);
    }

    public function testNoticeOnEmptyServeRootDisclaimsConfinement(): void
    {
        $notice = ServeCommand::tokenlessExposureNotice(null, '');

        $this->assertStringContainsString('scans are NOT confined', $notice);
        $this->assertStringNotContainsString('confined to serve root', $notice);
    }

    // ------------------------------------------------------------------
    // single-token law: path builder and sweep speak one prefix const
    // ------------------------------------------------------------------

    public function testResultDirPathRidesThePrefixConstant(): void
    {
        $this->assertSame(
            sys_get_temp_dir() . '/' . ServeCommand::RESULTS_DIR_PREFIX . getmypid(),
            ServeCommand::resultDirPath()
        );
    }

    // ------------------------------------------------------------------
    // once-S71 fix1 (r1 MEDIUM-1): the execute() startup call sites
    // ------------------------------------------------------------------

    /**
     * Pre-bind output pin (pattern: ServeCommandTest::
     * testServeExitsOneBeforeBindOnNonNumericServeWorkers). A tokenless
     * run is forced to fail at the bind refusal by occupying the port with
     * our own listener first: execute() must have walked the whole startup
     * block (stale sweep, then the SECURITY notice) BEFORE it can print
     * "bind failed" — if the notice call site is ever neutered (r1 mutant
     * `mw`) or the block moved after the bind, the SECURITY line never
     * reaches the output and this goes red. The sweep COUNT line is
     * deliberately not asserted: it would mean planting litter in the
     * shared /tmp, which sibling serve boots sweep too (r1 fix-brief).
     */
    public function testTokenlessStartupNoticeRidesExecuteBeforeTheBindRefusal(): void
    {
        $errno = 0;
        $errstr = '';
        // Port 0: the kernel picks a free one — no fixed-port collision
        // with sibling lanes, no reservation race to lose.
        $listener = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($listener === false) {
            $this->markTestSkipped("cannot occupy a local port to force the bind refusal: {$errstr}");
        }
        $name = (string) stream_socket_get_name($listener, false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $this->assertGreaterThan(0, $port, 'the occupied listener must report its port');

        $command = new ServeCommand();
        $command->setApplication(new ConsoleApplication('phpdup', '1.0.0'));
        // shell-S35 seam: the phar posture makes spawnWorker return null
        // (sync mode), so every startup leg up to the bind is reached for
        // real without ever forking a worker child out of the test run.
        $command->pharRunningOverride = '/tmp/anywhere/phpdup.phar';

        $output = new BufferedOutput();
        $exit = $command->run(
            new ArrayInput(['--host' => '127.0.0.1', '--port' => (string) $port]),
            $output
        );
        fclose($listener);
        // execute()'s prepare leg created the real pid-named results dir;
        // tearDown unlinks its contents and removes the directory itself.
        $this->realResultsDir = ServeCommand::resultDirPath();

        $text = $output->fetch();
        $this->assertSame(1, $exit, 'an occupied port must refuse startup at the bind leg');
        $this->assertStringContainsString(
            'SECURITY: serving without --token',
            $text,
            'execute() must print the tokenless exposure notice through its startup call site'
        );
        $this->assertStringContainsString('phpdup: bind failed', $text, 'the refusal itself must be reported');
        $this->assertStringNotContainsString('listening on', $text, 'a refused startup must never claim a listener');

        // Socket-proven "the command never bound" (the :388-399 shape):
        // the only listener this port ever had was ours, now closed.
        $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 2.0);
        if ($probe !== false) {
            fclose($probe);
            $this->fail('a bind-refused serve must leave no listener on the port');
        }
        $this->assertStringContainsStringIgnoringCase('refused', $errstr);
    }

    /**
     * Source-order pin (pattern: Server/ServeCommandTest::
     * testSignalWiringRidesTheRealCallSites). Positional read of the real
     * execute() body: the sweep and the notice must sit on a textual path
     * to the bind, BEFORE the spawn — zero /tmp race, zero timing, and the
     * exact call-site strings are the ones the r1 `mw` mutant rewrites to
     * constants, so neutering either leg makes the matching strpos return
     * false and this test goes red with it.
     */
    public function testStartupSweepAndNoticeCallSitesSitBeforeTheSpawnAndBindLegs(): void
    {
        $src = (string) file_get_contents(
            (new \ReflectionClass(ServeCommand::class))->getFileName()
        );

        $execStart = strpos($src, 'protected function execute(InputInterface');
        $this->assertIsInt($execStart, 'execute() must exist');
        $bindPos = strpos($src, '@stream_socket_server(', $execStart);
        $this->assertIsInt($bindPos, 'execute() must reach the bind leg');
        $preBind = substr($src, $execStart, $bindPos - $execStart);

        $sweep = strpos($preBind, '$this->sweepStaleResultsDirs();');
        $this->assertIsInt($sweep, 'the startup sweep must ride execute() before any bind');
        $notice = strpos($preBind, 'self::tokenlessExposureNotice(');
        $this->assertIsInt($notice, 'the tokenless notice must ride execute() before any bind');
        $spawn = strpos($preBind, '$this->spawnWorker($output');
        $this->assertIsInt($spawn, 'execute() must attempt the worker spawn before the bind');

        $this->assertLessThan($spawn, $sweep, 'stale dirs are swept before a worker could write any');
        $this->assertLessThan($spawn, $notice, 'the exposure notice precedes the first route that could answer');
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * The sweep's liveness gate needs posix_kill or /proc; where neither
     * exists the sweep is (by design) a total no-op and the dead-pid arms
     * are untestable — skip loudly rather than assert nothing. Also skips
     * on the absurd case of an impossible pid reported alive.
     */
    private function requireLivenessOracle(): void
    {
        if (!function_exists('posix_kill') && !is_dir('/proc')) {
            $this->markTestSkipped('no pid-liveness oracle available; sweep no-ops by design');
        }
        if (function_exists('posix_kill') && @posix_kill(self::DEAD_PID, 0) === true) {
            $this->markTestSkipped('an impossible pid reported alive; refusing a flaky sweep assertion');
        }
    }

    private function scratchTemp(): string
    {
        $dir = sys_get_temp_dir() . '/phpdup-sweep-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $this->assertNotFalse(mkdir($dir, 0700));
        $this->scratch = $dir;

        return $dir;
    }

    /** @param list<string> $files */
    private function makeResultsDir(string $temp, int $pid, array $files): string
    {
        $dir = $temp . '/' . ServeCommand::RESULTS_DIR_PREFIX . $pid;
        $this->assertNotFalse(mkdir($dir, 0700));
        foreach ($files as $file) {
            $this->assertNotFalse(file_put_contents($dir . '/' . $file, 'result'));
        }

        return $dir;
    }

    private function liveChildPid(): int
    {
        if (!function_exists('posix_kill')) {
            $this->markTestSkipped('the live-child arm needs posix_kill');
        }
        $out = [];
        @exec('sleep 30 >/dev/null 2>&1 & echo $!', $out);
        $raw = trim((string) ($out[0] ?? ''));
        $pid = (int) $raw;
        if ($pid <= 0 || $raw !== (string) $pid || @posix_kill($pid, 0) !== true) {
            $this->markTestSkipped('cannot spawn a live child to pin the liveness arm');
        }
        $this->livePid = $pid;

        return $pid;
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
