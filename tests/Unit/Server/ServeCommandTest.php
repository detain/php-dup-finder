<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Server;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\ServeCommand;
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
}
