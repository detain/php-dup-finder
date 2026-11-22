<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Server;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\ServeCommand;
use Phpdup\Server\AnalysisWorker;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class ServeCommandTest extends TestCase
{
    public function testBindPublicWithoutTokenIsRejected(): void
    {
        $command = new ServeCommand();
        $command->setApplication(new Application('phpdup', '1.0.0'));

        $input = new ArrayInput([
            '--host' => '0.0.0.0',
            '--port' => '8080',
            '--bind-public' => true,
            // no --token
        ]);

        $output = new NullOutput();
        $exitCode = $command->run($input, $output);

        $this->assertSame(1, $exitCode);
    }

    public function testNonLoopbackWithoutBindPublicIsRejected(): void
    {
        $command = new ServeCommand();
        $command->setApplication(new Application('phpdup', '1.0.0'));

        $input = new ArrayInput([
            '--host' => '0.0.0.0',
            '--port' => '18082',
            // no --bind-public
        ]);

        $output = new NullOutput();
        $exitCode = $command->run($input, $output);

        $this->assertSame(1, $exitCode);
    }

    /**
     * Verify that --serve-root and --token options are properly configured.
     */
    public function testServeRootOptionIsConfigured(): void
    {
        $command = new ServeCommand();
        $definition = $command->getDefinition();

        $serveRootOption = $definition->getOption('serve-root');
        $this->assertNotNull($serveRootOption);
        $this->assertTrue($serveRootOption->isValueRequired());
        $this->assertSame(getcwd() ?: '.', $serveRootOption->getDefault());
    }

    /**
     * Verify that --token option is properly configured.
     */
    public function testTokenOptionIsConfigured(): void
    {
        $command = new ServeCommand();
        $definition = $command->getDefinition();

        $tokenOption = $definition->getOption('token');
        $this->assertNotNull($tokenOption);
        $this->assertTrue($tokenOption->isValueRequired());
        $this->assertNull($tokenOption->getDefault());
    }

    /**
     * Verify that --bind-public option description mentions token requirement.
     */
    public function testBindPublicOptionDescriptionMentionsToken(): void
    {
        $command = new ServeCommand();
        $definition = $command->getDefinition();

        $bindPublicOption = $definition->getOption('bind-public');
        $this->assertNotNull($bindPublicOption);
        $this->assertStringContainsString('token', $bindPublicOption->getDescription());
    }

    // ------------------------------------------------------------------
    // shell-S02 (shell-021): the results dir is private from birth —
    // created 0700, foreign-owned leftovers refuse startup (exit 1),
    // and the shutdown handler sweeps it so results never outlive serve.
    // ------------------------------------------------------------------

    /** Path of a results dir prepared by the running test, or '' (cleanup). */
    private string $s02ResultsDir = '';

    protected function tearDown(): void
    {
        if ($this->s02ResultsDir !== '' && is_dir($this->s02ResultsDir)) {
            foreach (glob($this->s02ResultsDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->s02ResultsDir);
        }
        $this->s02ResultsDir = '';
        parent::tearDown();
    }

    public function testPrepareResultsDirCreatesPrivate0700Directory(): void
    {
        $command = new ServeCommand();

        $dir = $command->prepareResultsDir();

        $this->assertNotNull($dir);
        $this->s02ResultsDir = $dir;
        $this->assertSame(ServeCommand::resultDirPath(), $dir);
        clearstatcache(true, $dir);
        $this->assertSame('700', substr(sprintf('%o', (int)fileperms($dir)), -3));
        $this->assertSame('', $command->resultsDirRefusal());
    }

    public function testPrepareResultsDirTightensOwnedWorldReadableLeftover(): void
    {
        // An older release created the dir 0755; a leftover of our own must
        // be adopted AND hardened, not refused (the pid-derived path is ours).
        $dir = ServeCommand::resultDirPath();
        if (!is_dir($dir)) {
            mkdir($dir, 0755);
        }
        chmod($dir, 0755);
        $this->s02ResultsDir = $dir;

        $command = new ServeCommand();
        $prepared = $command->prepareResultsDir();

        $this->assertSame($dir, $prepared);
        clearstatcache(true, $dir);
        $this->assertSame('700', substr(sprintf('%o', (int)fileperms($dir)), -3));
    }

    public function testPrepareResultsDirRefusesForeignOwnedDirectory(): void
    {
        // The seam below feeds only the OWNER uid — the real comparison and
        // its refusal message (the load-bearing arm) run unseamed. The dir
        // starts genuinely ours, so a plain prepare must accept it first;
        // then a probe claiming uid 4242 (unit tests cannot chown without
        // root) forces the very same path through the foreign branch.
        $command = new ServeCommand();
        $created = $command->prepareResultsDir();
        $this->assertNotNull($created);
        $this->s02ResultsDir = $created;

        // Left world-readable, the exact state an adoption would "fix":
        // a refusal must leave it byte-for-byte untouched.
        chmod($created, 0755);
        clearstatcache(true, $created);

        $foreign = new ServeCommand();
        $foreign->ownerProbe = static fn (string $dir): int => 4242;

        $this->assertNull($foreign->prepareResultsDir());
        $this->assertStringContainsString('owned by uid 4242', $foreign->resultsDirRefusal());
        // Refusal must not delete or re-permission the foreign tree.
        $this->assertDirectoryExists($created);
        clearstatcache(true, $created);
        $this->assertSame('755', substr(sprintf('%o', (int)fileperms($created)), -3));
    }

    public function testPrepareResultsDirFailClosedOnUnstatableOwner(): void
    {
        // The other half of the unseamed check: an owner that cannot be
        // stat'ed counts as foreign — startup refuses instead of adopting
        // blindly (real-world example: a stale NFS mount dying mid-stat).
        $command = new ServeCommand();
        $created = $command->prepareResultsDir();
        $this->assertNotNull($created);
        $this->s02ResultsDir = $created;

        $unstatable = new ServeCommand();
        $unstatable->ownerProbe = static fn (string $dir): false => false;

        $this->assertNull($unstatable->prepareResultsDir());
        $this->assertStringContainsString('cannot stat the owner', $unstatable->resultsDirRefusal());
        $this->assertDirectoryExists($created);
    }

    public function testServeExitsOneWhenTheResultsDirIsForeignOwned(): void
    {
        // Startup posture (exit 1, runtime): the refusal happens after config
        // validation and before the bind — no silent adoption, no listener.
        $command = new ServeCommand();
        $command->setApplication(new Application('phpdup', '1.0.0'));
        $created = $command->prepareResultsDir();
        $this->assertNotNull($created);
        $this->s02ResultsDir = $created;

        $serve = new ServeCommand();
        $serve->setApplication(new Application('phpdup', '1.0.0'));
        $serve->ownerProbe = static fn (string $dir): int => 4242;

        $exitCode = $serve->run(
            new ArrayInput(['--host' => '127.0.0.1', '--port' => '18999']),
            new NullOutput()
        );

        $this->assertSame(1, $exitCode);

        // If the refusal block ever regresses (returns 1 but still binds, or
        // the guard is removed so execute() accept-loops forever), this
        // connect flips red instead of hanging the suite: no listener was
        // ever opened on the port we passed to the refused command.
        $errno = 0;
        $errstr = '';
        $probe = @stream_socket_client('tcp://127.0.0.1:18999', $errno, $errstr, 2.0);
        if ($probe !== false) {
            fclose($probe);
            $this->fail('a refused serve must never open the listener');
        }
        $this->assertStringContainsStringIgnoringCase('refused', $errstr);
    }

    public function testCleanupWorkerSweepsTheResultsDirectory(): void
    {
        // The registered shutdown handler is this cleanupWorker(); after it
        // runs the results dir (with its job files) is gone.
        $command = new ServeCommand();
        $dir = $command->prepareResultsDir();
        $this->assertNotNull($dir);
        $this->s02ResultsDir = $dir;
        file_put_contents($dir . '/0123456789abcdef.json', '{"clusters":[]}');

        $resultDirProp = new \ReflectionProperty(ServeCommand::class, 'resultDir');
        $resultDirProp->setValue($command, $dir);

        $cleanup = new \ReflectionMethod(ServeCommand::class, 'cleanupWorker');
        $cleanup->invoke($command);

        $this->assertDirectoryDoesNotExist($dir);
        $this->s02ResultsDir = '';

        // Second call is a no-op (idempotent shutdown posture).
        $cleanup->invoke($command);
        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testCleanupWorkerNeverSweepsAForeignPath(): void
    {
        // Belt behind the ownership check: even with the property hijacked to
        // an unrelated directory, the sweep only touches the pid-derived path.
        $stray = sys_get_temp_dir() . '/phpdup-s02-stray-' . uniqid();
        mkdir($stray, 0700);
        file_put_contents($stray . '/keep.json', '{}');

        $command = new ServeCommand();
        $resultDirProp = new \ReflectionProperty(ServeCommand::class, 'resultDir');
        $resultDirProp->setValue($command, $stray);

        $cleanup = new \ReflectionMethod(ServeCommand::class, 'cleanupWorker');
        $cleanup->invoke($command);

        $this->assertDirectoryExists($stray);
        $this->assertFileExists($stray . '/keep.json');

        @unlink($stray . '/keep.json');
        @rmdir($stray);
    }

    // ------------------------------------------------------------------
    // shell-S03 (shell-016): SIGINT/SIGTERM turn a kill into a clean
    // shutdown — handlers installed at startup, dispatched at the TOP of
    // every accept-loop iteration (the 30 s timeout `continue` can no
    // longer run past one), worker reaped + /tmp swept before exit 130.
    // The worker likewise self-exits once its parent dies; the run-loop
    // lifecycle pins live here (not ApplicationTest) because this file is
    // the step's locked surface and the loop is serve-lifecycle, not
    // wire-protocol.
    // ------------------------------------------------------------------

    public function testDispatchRunsBeforeTheAcceptTimeoutContinueLeg(): void
    {
        // Code inspection pin demanded by the acceptance: the dispatch
        // call must sit ABOVE both the accept and its timeout `continue`,
        // so every iteration — including a wake-up from the 30 s timeout —
        // actions a queued signal before looping back into the block.
        $src = (string)file_get_contents(
            (new \ReflectionClass(ServeCommand::class))->getFileName()
        );
        $loopStart = strpos($src, 'while (is_resource($server))');
        $this->assertIsInt($loopStart, 'serve accept loop must exist');
        $loopEnd = strpos($src, 'return 0;', $loopStart);
        $this->assertIsInt($loopEnd);
        $loop = substr($src, $loopStart, $loopEnd - $loopStart);

        $dispatch = strpos($loop, 'pcntl_signal_dispatch();');
        $accept = strpos($loop, 'stream_socket_accept($server');
        $continue = strpos($loop, 'continue;');

        $this->assertIsInt($dispatch, 'the loop must dispatch queued signals');
        $this->assertIsInt($accept);
        $this->assertIsInt($continue);
        $this->assertLessThan($accept, $dispatch, 'dispatch must precede the blocking accept');
        $this->assertLessThan($continue, $dispatch, 'dispatch must precede the timeout continue');
    }

    public function testInstallSignalHandlersRefusesToInstallWithoutPcntl(): void
    {
        // The graceful no-op arm cannot be executed on a pcntl build, so
        // it is pinned by structure: the guard must return before any
        // pcntl_* call is reachable.
        $src = (string)file_get_contents(
            (new \ReflectionClass(ServeCommand::class))->getFileName()
        );
        $start = strpos($src, 'private function installSignalHandlers(): void');
        $this->assertIsInt($start);
        $method = substr($src, $start, strpos($src, "\n    }", $start) - $start);

        $guard = strpos($method, "if (!function_exists('pcntl_signal')");
        $firstPcntlCall = strpos($method, 'pcntl_async_signals(');
        $this->assertIsInt($guard, 'install must guard on pcntl availability');
        $this->assertStringContainsString('return;', substr($method, $guard, $firstPcntlCall - $guard));
        $this->assertIsInt($firstPcntlCall);
        // SIGINT and SIGTERM must both be claimed, and the pcntl_signal
        // registrations must sit after the guard.
        $sigInt = strpos($method, 'pcntl_signal(SIGINT,');
        $sigTerm = strpos($method, 'pcntl_signal(SIGTERM,');
        $this->assertIsInt($sigInt);
        $this->assertIsInt($sigTerm);
        $this->assertGreaterThan($guard, $sigInt);
        $this->assertGreaterThan($guard, $sigTerm);
    }

    public function testSignalWiringRidesTheRealCallSites(): void
    {
        // shell-S03 r1 (n-3): the install/spawn-env/exit-literal legs were
        // probe-only. Same pattern as the dispatch pin — positional
        // inspection of ServeCommand.php, deterministic even where killing
        // a live daemon is impractical. The live probes stay as the
        // behavioral half; this closes them at unit level.
        $src = (string)file_get_contents(
            (new \ReflectionClass(ServeCommand::class))->getFileName()
        );

        // (1) execute() installs the handlers BEFORE the worker spawn: a
        // Ctrl-C during the socket wait must already ride the reap path.
        $execStart = strpos($src, 'protected function execute(InputInterface');
        $loopStart = strpos($src, 'while (is_resource($server))');
        $this->assertIsInt($execStart, 'execute() must exist');
        $this->assertIsInt($loopStart, 'the accept loop must exist');
        $upToLoop = substr($src, $execStart, $loopStart - $execStart);
        $install = strpos($upToLoop, '$this->installSignalHandlers();');
        $spawn = strpos($upToLoop, '$this->spawnWorker(');
        $this->assertIsInt($install, 'execute() must call installSignalHandlers()');
        $this->assertIsInt($spawn, 'execute() must spawn the worker');
        $this->assertLessThan($spawn, $install, 'handlers must be installed before the spawn');

        // (2) the spawn hands proc_open the merged worker environment — the
        // PHPDUP_SERVE_PID token's ONLY route to the worker.
        $spawnStart = strpos($src, 'private function spawnWorker(');
        $this->assertIsInt($spawnStart);
        $spawnBody = substr($src, $spawnStart, (int)strpos($src, "\n    }", $spawnStart) - $spawnStart);
        $procOpen = strpos($spawnBody, 'proc_open(');
        $envArg = strpos($spawnBody, 'self::workerEnvironment()');
        $this->assertIsInt($procOpen, 'spawnWorker must proc_open the worker');
        $this->assertIsInt($envArg, 'the spawn must pass self::workerEnvironment()');
        $this->assertGreaterThan($procOpen, $envArg, 'the environment must ride the proc_open call');

        // (3) the documented number itself: SIGNAL_EXIT_CODE is literally
        // 130, and the handler exits THROUGH the constant — the seam-based
        // assertSame against the same constant alone is a tautology that
        // survives a value change, so the literal is pinned in source.
        $this->assertMatchesRegularExpression(
            '/const SIGNAL_EXIT_CODE = 130;/',
            $src,
            'signal death must exit 130 (128+SIGINT) for both signals'
        );
        $termStart = strpos($src, 'private function terminateOnSignal(');
        $this->assertIsInt($termStart);
        $termBody = substr($src, $termStart, (int)strpos($src, "\n    }", $termStart) - $termStart);
        $this->assertStringContainsString(
            'exit(self::SIGNAL_EXIT_CODE);',
            $termBody,
            'the unseamed handler must exit through the pinned constant'
        );
        $this->assertStringContainsString(
            '$terminator(self::SIGNAL_EXIT_CODE)',
            $termBody,
            'the test seam must receive the same pinned code as the real exit'
        );
    }

    public function testInstalledHandlersTerminateSelfSentSignalsWith130(): void
    {
        // End-to-end for the install leg itself: after
        // installSignalHandlers(), a real posix_kill of our own pid is
        // routed through the real pcntl dispatch into terminateOnSignal — the
        // terminator seam captures 130 in-process, so cleanup runs while
        // phpunit survives.
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')
            || !defined('SIGINT') || !defined('SIGTERM')) {
            $this->markTestSkipped('pcntl/posix required to exercise signal delivery');
        }

        $codes = [];
        $command = new ServeCommand();
        $command->signalTerminator = static function (int $code) use (&$codes): void {
            $codes[] = $code;
        };

        $install = new \ReflectionMethod(ServeCommand::class, 'installSignalHandlers');
        $install->invoke($command);
        try {
            posix_kill(getmypid(), SIGINT);
            pcntl_signal_dispatch();
            posix_kill(getmypid(), SIGTERM);
            pcntl_signal_dispatch();
        } finally {
            // Leave the test process exactly as we found it.
            pcntl_signal(SIGINT, SIG_DFL);
            pcntl_signal(SIGTERM, SIG_DFL);
            if (function_exists('pcntl_async_signals')) {
                pcntl_async_signals(false);
            }
        }

        $this->assertSame(
            [ServeCommand::SIGNAL_EXIT_CODE, ServeCommand::SIGNAL_EXIT_CODE],
            $codes,
            'both SIGINT and SIGTERM must route through the installed handler to exit 130'
        );
    }

    public function testHandleSignalReapsSeededWorkerAndTerminatesWith130(): void
    {
        // The full cleanup posture runs unseamed — only the exit itself
        // is intercepted: live worker child terminated, socket file
        // removed, results dir swept, exit code 130 captured.
        $command = new ServeCommand();

        $dir = $command->prepareResultsDir();
        $this->assertNotNull($dir);
        $this->s02ResultsDir = $dir;
        file_put_contents($dir . '/0123456789abcdef.json', '{"clusters":[]}');
        (new \ReflectionProperty(ServeCommand::class, 'resultDir'))->setValue($command, $dir);

        $socketPath = sys_get_temp_dir() . '/phpdup-s03-seeded-' . uniqid() . '.sock';
        file_put_contents($socketPath, '');
        (new \ReflectionProperty(ServeCommand::class, 'workerSocket'))->setValue($command, $socketPath);

        // A real worker TREE stands in for the spawn (shell-S03 r1, m-2):
        // string spawn puts an sh wrapper in front — the production shape
        // — and a FOREGROUND non-last command keeps the wrapper WAITING
        // on a live child, so cleanupWorker's workerTreePids leg is
        // genuinely hunted. Foreground matters: POSIX (and dash obeys)
        // forces SIG_IGN on INT/QUIT for `&`-backgrounded jobs, which
        // would vaccine the child against the very leg pinned here —
        // the production worker rides the foreground, default
        // disposition. The barrier pins the child's existence BEFORE the
        // signal lands: racing it is exactly what let the old childless
        // fixture pass without exercising the loop.
        $proc = proc_open('sleep 20; true', [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        $workerPid = proc_get_status($proc)['pid'];
        $childPid = $this->waitChildSpawned($workerPid, ['sleep', '20']);
        (new \ReflectionProperty(ServeCommand::class, 'workerProcess'))->setValue($command, $proc);

        $codes = [];
        $command->signalTerminator = static function (int $code) use (&$codes): void {
            $codes[] = $code;
        };

        $handle = new \ReflectionMethod(ServeCommand::class, 'terminateOnSignal');
        $handle->invoke($command, 2); // SIGINT's number; the handler's code is signal-agnostic

        $this->assertSame([ServeCommand::SIGNAL_EXIT_CODE], $codes);
        $this->assertFileDoesNotExist($socketPath);
        $this->assertDirectoryDoesNotExist($dir);
        $this->s02ResultsDir = '';
        // No fclose($pipes): cleanupWorker's proc_close() already closed the
        // parent-side descriptors — a second close would only double-free.

        // Wrapper reaped by proc_close, and the REAL child gone too: the
        // tree hunt is the only leg that kills the child before the
        // wrapper dies, so with the workerTreePids SIGINT loop removed,
        // the orphaned sleep outlives cleanup and the second assertion
        // goes red — in every run order (M-1/m-2).
        $this->assertTreePidReaped($workerPid, 'the wrapper holding the worker');
        if ($childPid !== null) {
            $this->assertTreePidReaped($childPid, 'the worker child behind the wrapper');
        }
    }

    public function testWorkerSelfExitsWhenItsParentIsReparented(): void
    {
        // Real run()-loop exit: the probe says "spawn parent 100", then
        // the kernel-equivalent reparent to 1 → run() returns on the very
        // first poll (no blocking accept) and unlinks the socket file the
        // dead parent's cleanup could never remove. The probe
        // self-destructs the test if a dead verdict INSIDE
        // parentHasDied() is ever ignored; removing the run-loop call
        // site instead surfaces as a plain harness timeout, not a
        // self-destruct (shell-S03 r1, n-4 honesty).
        $socketPath = sys_get_temp_dir() . '/phpdup-s03-orphan-' . uniqid() . '.sock';
        $calls = 0;
        $pids = [100, 1];
        $worker = new AnalysisWorker($socketPath, sys_get_temp_dir(), null);
        $worker->parentPidProbe = function () use (&$calls, $pids): int {
            $calls++;
            if ($calls > count($pids)) {
                $this->fail('worker loop did not self-exit after the parent died');
            }
            return $pids[$calls - 1];
        };

        $worker->run();

        $this->assertSame(2, $calls, 'exit must land on the first post-spawn poll');
        clearstatcache(true, $socketPath);
        $this->assertFileDoesNotExist($socketPath, 'orphan exit must remove the worker socket');
    }

    public function testWorkerParentPollArms(): void
    {
        // The comparison runs unseamed against a fed ppid. The three
        // fail-open arms (unresolvable read, unrecorded start, spawned
        // under init) must NEVER vote for self-exit — WorkerPool-style
        // graceful no-op over speculation.
        $died = new \ReflectionMethod(AnalysisWorker::class, 'parentHasDied');
        $spawnProp = new \ReflectionProperty(AnalysisWorker::class, 'spawnParentPid');

        // A pid the OS will not hand out before pid_max cycles — dead for
        // all practical purposes (guarded below in case this box differs).
        $deadPid = $this->unusedPid();

        $worker = new AnalysisWorker(
            sys_get_temp_dir() . '/phpdup-s03-arm-' . uniqid() . '.sock',
            sys_get_temp_dir()
        );

        $spawnProp->setValue($worker, null);
        $worker->parentPidProbe = static fn (): ?int => 4242;
        $this->assertFalse($died->invoke($worker), 'unrecorded start must disable the check');

        $spawnProp->setValue($worker, 100);
        $worker->parentPidProbe = static fn (): ?int => null;
        $this->assertFalse($died->invoke($worker), 'an unresolvable read must disable the check');

        $spawnProp->setValue($worker, 1);
        $worker->parentPidProbe = static fn (): ?int => 1;
        $this->assertFalse($died->invoke($worker), 'a worker spawned under init cannot observe reparenting');

        // And the live arm: same ppid → alive.
        $spawnProp->setValue($worker, 100);
        $worker->parentPidProbe = static fn (): ?int => 100;
        $this->assertFalse($died->invoke($worker), 'an unchanged parent pid means the parent lives');

        // Different ppid → reparented → the direct parent died.
        $spawnProp->setValue($worker, 100);
        $worker->parentPidProbe = static fn (): ?int => 1;
        $this->assertTrue($died->invoke($worker), 'a changed parent pid is the orphan signal');

        // The daemon-pid leg (the one that fires through the real
        // sh-wrapped chain, where the wrapper keeps the worker's ppid
        // stable): dead serve pid → death, even with ppid unchanged.
        if ($deadPid !== null) {
            $wrapped = new AnalysisWorker(
                sys_get_temp_dir() . '/phpdup-s03-arm2-' . uniqid() . '.sock',
                sys_get_temp_dir(),
                null,
                null,
                $deadPid
            );
            $spawnProp->setValue($wrapped, 100);
            $wrapped->parentPidProbe = static fn (): ?int => 100;
            $this->assertTrue(
                $died->invoke($wrapped),
                'a vanished daemon pid must fire even when the wrapper parent is unchanged'
            );

            // No env token (manual spawn posture) → that leg is simply
            // absent; the ppid arms above govern alone.
            $plain = new AnalysisWorker(
                sys_get_temp_dir() . '/phpdup-s03-arm3-' . uniqid() . '.sock',
                sys_get_temp_dir(),
                null,
                null,
                -5 // non-positive = absent
            );
            $spawnProp->setValue($plain, 100);
            $plain->parentPidProbe = static fn (): ?int => 100;
            $this->assertFalse($died->invoke($plain), 'a junk serve pid must disable the leg, never fire it');
        }
    }

    /**
     * A pid very unlikely to be alive/reused during the test, or null
     * when this box offers no such candidate (then the leg is simply
     * not pinned here — never a hang).
     */
    private function unusedPid(): ?int
    {
        if (!function_exists('posix_kill')) {
            return null;
        }
        $max = (int)@file_get_contents('/proc/sys/kernel/pid_max');
        if ($max <= 2) {
            return null;
        }
        $candidate = $max - 1;
        return @posix_kill($candidate, 0) ? null : $candidate;
    }

    // ------------------------------------------------------------------
    // shell-S03 r1 (M-1/m-2): spawn barriers + liveness helpers for the
    // reap fixtures. A signal sent before dash finishes installing its
    // trap (or before the wrapper forks its child) lands on a default
    // disposition and the reap passes VACUOUSLY — the race the r1 review
    // measured (0.26 s warm-vacuous vs 1.23 s pinned stubborn path). The
    // barriers read /proc and block until the fixture has really settled
    // into the shape the reap is meant to hunt; liveness checks treat a
    // not-yet-adopted zombie as dead (a reaped grandchild is inherited
    // and reaped by init, not by this test — kill(pid,0) still succeeds
    // on the zombie, so raw liveness would race adoption).
    // ------------------------------------------------------------------

    /**
     * Exact argv list of a pid per /proc, or null when unreadable.
     *
     * @return list<string>|null
     */
    private function procArgv(int $pid): ?array
    {
        $raw = @file_get_contents("/proc/{$pid}/cmdline");
        if ($raw === false) {
            return null;
        }

        return $raw === '' ? [] : explode("\0", rtrim($raw, "\0"));
    }

    /**
     * Block until /proc reports $pid carrying exactly $argv. `trap ""
     * INT; exec sleep 30` only ignores SIGINT once the exec has replaced
     * dash (ignored dispositions survive execve), so observing argv
     * [sleep, 30] is the deterministic proof the stubborn path is armed.
     * Linux fails loud when the exec never settles; a box without /proc
     * degrades to a short grace (macOS posture, best-effort).
     *
     * @param list<string> $argv
     */
    private function waitSpawnSettled(int $pid, array $argv, float $timeoutSeconds = 5.0): bool
    {
        if (!is_dir('/proc')) {
            usleep(200000);
            return false;
        }
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if ($this->procArgv($pid) === $argv) {
                return true;
            }
            usleep(10000);
        }
        $this->fail('spawn barrier: pid ' . $pid . ' never became [' . implode(' ', $argv)
            . '] — the reap fixture would pass vacuously');
    }

    /**
     * Direct children of $ppid per the Linux task-children file.
     *
     * @return list<int>
     */
    private function procChildren(int $ppid): array
    {
        $listed = @file_get_contents("/proc/{$ppid}/task/{$ppid}/children");
        if ($listed === false) {
            return [];
        }
        $pids = [];
        foreach (preg_split('/\s+/', trim($listed)) ?: [] as $token) {
            if ($token !== '' && ctype_digit($token)) {
                $pids[] = (int)$token;
            }
        }

        return $pids;
    }

    /**
     * Block until the wrapper $ppid has a DIRECT child with exactly
     * $argv, and return that child's pid (m-2: the workerTreePids SIGINT
     * leg needs a real child to hunt). Linux fails loud when the child
     * never appears; without /proc there is nothing to pin — null lets
     * the caller run the wrapper-only shape.
     *
     * @param list<string> $argv
     */
    private function waitChildSpawned(int $ppid, array $argv, float $timeoutSeconds = 5.0): ?int
    {
        if (!is_dir('/proc')) {
            usleep(200000);
            return null;
        }
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            foreach ($this->procChildren($ppid) as $pid) {
                if ($this->procArgv($pid) === $argv) {
                    return $pid;
                }
            }
            usleep(10000);
        }
        $this->fail('spawn barrier: wrapper ' . $ppid . ' never forked [' . implode(' ', $argv)
            . '] — the tree hunt would pass vacuously');
    }

    /**
     * Dead = the pid is gone, or only a zombie awaiting its (init-side)
     * reaper remains.
     */
    private function pidIsDead(int $pid): bool
    {
        if (!@posix_kill($pid, 0)) {
            return true;
        }
        $stat = @file_get_contents("/proc/{$pid}/stat");
        if ($stat === false) {
            return true;
        }
        $close = strrpos($stat, ')');
        if ($close === false) {
            return false;
        }
        $fields = preg_split('/\s+/', trim(substr($stat, $close + 1))) ?: [];

        return ($fields[0] ?? '') === 'Z';
    }

    /**
     * Pid-liveness assertion after cleanup, bounded poll until the tree
     * member is dead-or-reaped (never a blocking wait).
     */
    private function assertTreePidReaped(int $pid, string $what): void
    {
        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline && !$this->pidIsDead($pid)) {
            usleep(20000);
        }
        $this->assertTrue($this->pidIsDead($pid), 'signal shutdown must reap ' . $what);
    }

    public function testWorkerRunsThroughEnvTokenAndSelfExitsOnDaemonDeath(): void
    {
        // The full production signal for the -9 acceptance leg: a dead
        // daemon pid (env token, real read — no ppid change) makes run()
        // return on its first poll and unlink the socket. This is the
        // chain shape the sh wrapper actually creates: the wrapper never
        // dies from the daemon's death, only the pid check can see it.
        $deadPid = $this->unusedPid();
        if ($deadPid === null) {
            $this->markTestSkipped('no unused pid available to simulate daemon death');
        }

        $socketPath = sys_get_temp_dir() . '/phpdup-s03-env-' . uniqid() . '.sock';
        $calls = 0;
        $worker = new AnalysisWorker($socketPath, sys_get_temp_dir(), null, null, $deadPid);
        $worker->parentPidProbe = function () use (&$calls): int {
            $calls++;
            if ($calls > 3) {
                $this->fail('run() ignored the dead daemon pid');
            }
            // ppid never changes — only the serve-pid leg can exit.
            return 100;
        };

        $worker->run();

        $this->assertSame(1, $calls, 'the dead daemon must stop run() before any ppid second read');
        clearstatcache(true, $socketPath);
        $this->assertFileDoesNotExist($socketPath, 'orphan exit must remove the worker socket');
    }

    public function testWorkerEnvironmentCarriesDaemonPidWithoutLosingTheParentEnv(): void
    {
        // proc_open REPLACES environments when given an array — a merge
        // bug here would strip PATH and break the /usr/bin/env shebang
        // of every spawn. Both halves pinned: token present, inheritance
        // intact.
        $env = ServeCommand::workerEnvironment();

        $this->assertSame((string)getmypid(), $env[ServeCommand::WORKER_PID_ENV]);
        $parent = getenv();
        foreach (['PATH', 'HOME'] as $essential) {
            if (isset($parent[$essential])) {
                $this->assertArrayHasKey($essential, $env);
                $this->assertSame((string)$parent[$essential], $env[$essential]);
            }
        }
        $this->assertNotEmpty($env['PATH'] ?? null, 'the spawn must not run childless of PATH');
    }

    public function testReapIsBoundedEvenWhenTheWrapperIgnoresSIGINT(): void
    {
        // The hang the live probe caught: a shell that waits on a child
        // survives SIGINT, and the old bare proc_close blocked forever —
        // inside the signal handler. A stubborn direct child (trapped
        // INT, exec'd sleep) must still be fully reaped, bounded by the
        // two graces, with the handler completing and code 130 hit.
        if (!function_exists('posix_kill')) {
            $this->markTestSkipped('posix needed to observe the reaped pid');
        }
        $proc = proc_open('trap "" INT; exec sleep 30', [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        $pid = proc_get_status($proc)['pid'];

        // M-1 race fix: block until the exec has landed. Before that, the
        // wrapper still owns a DEFAULT SIGINT disposition — a signal sent
        // that early dies instantly and the test would pass WITHOUT ever
        // reaching the SIGKILL escalation (measured: 0.26 s vacuous in
        // combined runs vs 1.23 s on the pinned stubborn path). argv
        // [sleep, 30] proves the SIG_IGN trap is in force (ignored
        // dispositions survive execve), so the INT grace MUST expire and
        // the escalation arm is exercised deterministically in every
        // run order. The elapsed lower bound below is the anti-vacuity
        // assertion itself: <1 s can only mean the stubborn path skipped.
        $this->waitSpawnSettled($pid, ['sleep', '30']);

        $command = new ServeCommand();
        $captured = null;
        $command->signalTerminator = static function (int $code) use (&$captured): void {
            $captured = $code;
        };
        (new \ReflectionProperty(ServeCommand::class, 'workerProcess'))->setValue($command, $proc);

        $start = microtime(true);
        (new \ReflectionMethod(ServeCommand::class, 'terminateOnSignal'))->invoke($command, 2); // SIGINT number
        $elapsed = microtime(true) - $start;

        $this->assertSame(ServeCommand::SIGNAL_EXIT_CODE, $captured);
        $this->assertGreaterThanOrEqual(
            1.0,
            $elapsed,
            'the pinned stubborn path must spend the full SIGINT grace — a faster cleanup means the escalation was never reached'
        );
        $this->assertLessThan(
            4.0,
            $elapsed,
            'cleanup must escalate to SIGKILL instead of blocking proc_close forever'
        );
        $this->assertTreePidReaped($pid, 'the stubborn worker tree');
    }

    // ------------------------------------------------------------------
    // shell-S04 (shell-007): one stalled client must not freeze the
    // daemon. handleClient installs a per-client read deadline and
    // checks stream_get_meta_data()['timed_out'] in BOTH raw-read loops
    // (header and body); the silent client is answered 408 Request
    // Timeout (that is the pinned choice over a bare close) and the
    // accept loop moves on to the next connection.
    // ------------------------------------------------------------------

    /**
     * Drive one handleClient() call over a real socketpair whose far end
     * stays OPEN after $request was written — the exact stall the finding
     * describes: bytes arrived, the request never completed, the client
     * never closes. Returns the raw response and the wall-clock seconds
     * handleClient needed (hermetic via the deadline seam: the stalled
     * legs cost about one seam second each, never the real 5 s default).
     *
     * @param \Phpdup\Server\Application $app
     * @return array{0:string,1:float}
     */
    private function driveClient(ServeCommand $command, string $request, object $app): array
    {
        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            $this->markTestSkipped('stream_socket_pair unavailable');
        }
        [$client, $peer] = $pair;

        $this->assertNotFalse(@fwrite($client, $request));

        $start = microtime(true);
        (new \ReflectionMethod(ServeCommand::class, 'handleClient'))->invoke($command, $peer, $app);
        $elapsed = microtime(true) - $start;

        // handleClient has returned and its response is buffered on the
        // socket; closing the daemon end delivers it to EOF on $client.
        fclose($peer);
        $raw = stream_get_contents($client);
        fclose($client);
        $this->assertIsString($raw);

        return [$raw, $elapsed];
    }

    public function testStalledHeaderLegIsAnswered408AtTheDeadline(): void
    {
        // Client A sends a partial request line + headers (no terminating
        // blank line) and then goes silent with the socket open. Before
        // shell-007 this blocked the serial loop until A closed; now the
        // first silent read releases at the deadline with 408.
        $command = new ServeCommand();
        $command->readTimeoutSeconds = 1;
        $app = new \Phpdup\Server\Application(new \Phpdup\Server\JobQueue());

        [$raw, $elapsed] = $this->driveClient(
            $command,
            "GET /healthz HTTP/1.1\r\nHost: x\r\n",
            $app
        );

        $this->assertStringStartsWith('HTTP/1.1 408 Request Timeout', $raw);
        // Bounded, not instant: far enough below 2 s to keep CI honest,
        // far enough above 0 to prove the deadline really fired (an
        // immediate-break mutant would answer 400 in microseconds).
        $this->assertGreaterThanOrEqual(0.5, $elapsed);
        $this->assertLessThan(2.0, $elapsed, 'a stalled header read must release at the deadline, not at close');
    }

    public function testStalledBodyLegIsAnswered408AtTheDeadline(): void
    {
        // The other loop the finding names: complete headers claim a 500-byte
        // body, the client sends a partial body and stalls. The header loop
        // exits happily on \r\n\r\n; the stall surfaces mid body-loop and
        // must take the same 408 road.
        $command = new ServeCommand();
        $command->readTimeoutSeconds = 1;
        $app = new \Phpdup\Server\Application(new \Phpdup\Server\JobQueue());

        [$raw, $elapsed] = $this->driveClient(
            $command,
            "POST /analyze HTTP/1.1\r\nHost: x\r\nContent-Length: 500\r\n\r\n{\"paths\":[",
            $app
        );

        $this->assertStringStartsWith('HTTP/1.1 408 Request Timeout', $raw);
        $this->assertGreaterThanOrEqual(0.5, $elapsed);
        $this->assertLessThan(2.0, $elapsed, 'a stalled body read must release at the deadline, not at close');
    }

    public function testCompletedRequestNeverWaitsOnTheDeadline(): void
    {
        // Belt for honest framing: the deadline arms per READ, so a request
        // that arrives complete is answered immediately and the 408 leg
        // never fires on healthy traffic.
        $command = new ServeCommand();
        $command->readTimeoutSeconds = 1;
        $app = new \Phpdup\Server\Application(new \Phpdup\Server\JobQueue());

        [$raw, $elapsed] = $this->driveClient(
            $command,
            "GET /healthz HTTP/1.1\r\nHost: x\r\n\r\n",
            $app
        );

        $this->assertStringStartsWith('HTTP/1.1 200', $raw);
        $this->assertStringEndsWith('ok', $raw);
        $this->assertLessThan(0.5, $elapsed, 'a completed request must never spend the read deadline');
    }

    public function testNextClientIsServedAfterATimedOutOne(): void
    {
        // "the loop continues" acceptance at the request layer: the SAME
        // command instance that 408'd a stalled client serves a fresh
        // healthz immediately — no wedged state survives handleClient.
        // (The accept loop itself is the S03-pinned top-dispatch shape;
        // it simply calls handleClient per accepted connection.)
        $command = new ServeCommand();
        $command->readTimeoutSeconds = 1;
        $app = new \Phpdup\Server\Application(new \Phpdup\Server\JobQueue());

        [$stalled] = $this->driveClient($command, "GET /healthz HTTP/1.1\r\nHost: x\r\n", $app);
        $this->assertStringStartsWith('HTTP/1.1 408', $stalled);

        [$next, $nextElapsed] = $this->driveClient(
            $command,
            "GET /healthz HTTP/1.1\r\nHost: x\r\n\r\n",
            $app
        );
        $this->assertStringStartsWith('HTTP/1.1 200', $next);
        $this->assertLessThan(0.5, $nextElapsed);
    }

    public function testDeadlineIsArmedAndCheckedInBothRawReadLoops(): void
    {
        // Code-inspection pin for the acceptance wording: stream_set_timeout
        // must be installed before the first read, and the timed_out check
        // must live inside BOTH the header loop and the body loop — not
        // just one of them.
        $src = (string)file_get_contents(
            (new \ReflectionClass(ServeCommand::class))->getFileName()
        );
        $start = strpos($src, 'private function handleClient($client, Application $app): void');
        $this->assertIsInt($start, 'handleClient must exist');
        $method = substr($src, $start, strpos($src, "\n    }", $start) - $start);

        $arm = strpos($method, 'stream_set_timeout($client,');
        $headerLoop = strpos($method, 'while (!feof($client))');
        $bodyLoop = strpos($method, 'while (strlen($body) < $expected');
        $this->assertIsInt($arm, 'the deadline must be armed in handleClient');
        $this->assertIsInt($headerLoop);
        $this->assertIsInt($bodyLoop);
        $this->assertLessThan($headerLoop, $arm, 'the deadline must be armed before the first read');

        $headerRegion = substr($method, $headerLoop, $bodyLoop - $headerLoop);
        $bodyRegion = substr($method, $bodyLoop);
        $this->assertStringContainsString('self::readTimedOut($client)', $headerRegion);
        $this->assertStringContainsString('self::readTimedOut($client)', $bodyRegion);
        $this->assertStringContainsString('408', $headerRegion);
        $this->assertStringContainsString('408', $bodyRegion);

        // The 408 reason phrase is the one pinned by the stream tests.
        $this->assertStringContainsString("408 => 'Request Timeout',", $src);
    }

    public function testReadDeadlineConstantIsTheUnseamedDefault(): void
    {
        // The number shell-S09 documents in docs/SERVER.md, and the seam's
        // fallback: only $readTimeoutSeconds may override the constant;
        // the default is unconditionally the class constant itself.
        $this->assertSame(5, ServeCommand::CLIENT_READ_TIMEOUT_SECONDS);

        $src = (string)file_get_contents(
            (new \ReflectionClass(ServeCommand::class))->getFileName()
        );
        $this->assertStringContainsString(
            '$this->readTimeoutSeconds ?? self::CLIENT_READ_TIMEOUT_SECONDS',
            $src,
            'handleClient must fall back to the documented constant'
        );
    }
}
