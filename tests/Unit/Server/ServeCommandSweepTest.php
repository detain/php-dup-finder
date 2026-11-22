<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Server;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\ServeCommand;

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

    /** A results dir under the REAL temp dir created by the 0700 recheck. */
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
