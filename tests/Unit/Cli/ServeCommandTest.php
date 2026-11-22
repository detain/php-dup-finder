<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use Phpdup\Cli\ServeCommand;
use Phpdup\Server\Application;
use Phpdup\Server\JobQueue;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class ServeCommandTest extends TestCase
{
    public function testIsLoopbackDetectsLoopbackAddresses(): void
    {
        $command = new ServeCommand();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('isLoopback');

        $this->assertTrue($method->invoke($command, '127.0.0.1'));
        $this->assertTrue($method->invoke($command, 'localhost'));
        $this->assertTrue($method->invoke($command, '::1'));
        $this->assertFalse($method->invoke($command, '0.0.0.0'));
        $this->assertFalse($method->invoke($command, '192.168.1.1'));
        $this->assertFalse($method->invoke($command, '::'));
    }

    public function testIsLoopbackRejectsNonLoopbackIpv4(): void
    {
        $command = new ServeCommand();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('isLoopback');

        $this->assertFalse($method->invoke($command, '10.0.0.1'));
        $this->assertFalse($method->invoke($command, '172.16.0.1'));
        $this->assertFalse($method->invoke($command, '8.8.8.8'));
    }

    public function testExtractHeadersSeparatesKeyFromValue(): void
    {
        $command = new ServeCommand();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('extractHeaders');

        $raw = "GET /healthz HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer secret\r\nContent-Type: application/json\r\n\r\n";
        $headers = $method->invoke($command, $raw);

        $this->assertSame('localhost', $headers['host']);
        $this->assertSame('Bearer secret', $headers['authorization']);
        $this->assertSame('application/json', $headers['content-type']);
    }

    public function testExtractHeadersHandlesMissingColon(): void
    {
        $command = new ServeCommand();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('extractHeaders');

        $raw = "GET /healthz HTTP/1.1\r\nInvalidHeaderNoColon\r\nHost: localhost\r\n\r\n";
        $headers = $method->invoke($command, $raw);

        $this->assertArrayNotHasKey('invalidheadernocolon', $headers);
        $this->assertSame('localhost', $headers['host']);
    }

    public function testExtractHeadersIsCaseInsensitive(): void
    {
        $command = new ServeCommand();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('extractHeaders');

        $raw = "GET /healthz HTTP/1.1\r\nHost: localhost\r\nX-Custom-Header: value\r\n\r\n";
        $headers = $method->invoke($command, $raw);

        $this->assertArrayHasKey('host', $headers);
        $this->assertArrayHasKey('x-custom-header', $headers);
        $this->assertSame('value', $headers['x-custom-header']);
    }

    public function testExtractHeadersHandlesEmptyValue(): void
    {
        $command = new ServeCommand();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('extractHeaders');

        $raw = "GET /healthz HTTP/1.1\r\nHost: localhost\r\nX-Empty: \r\n\r\n";
        $headers = $method->invoke($command, $raw);

        $this->assertArrayHasKey('x-empty', $headers);
        $this->assertSame('', $headers['x-empty']);
    }

    public function testIsAllowedRouteValidatesRouteAllowList(): void
    {
        $command = new ServeCommand();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('isAllowedRoute');

        $this->assertTrue($method->invoke($command, 'GET', '/healthz'));
        $this->assertTrue($method->invoke($command, 'POST', '/analyze'));
        $this->assertTrue($method->invoke($command, 'POST', '/jobs'));
        $this->assertTrue($method->invoke($command, 'GET', '/jobs/abc123'));
        $this->assertTrue($method->invoke($command, 'GET', '/jobs/0'));
        $this->assertFalse($method->invoke($command, 'GET', '/'));
        $this->assertFalse($method->invoke($command, 'DELETE', '/analyze'));
        $this->assertFalse($method->invoke($command, 'POST', '/analyze/extra'));
        $this->assertFalse($method->invoke($command, 'GET', '/jobs'));
        $this->assertFalse($method->invoke($command, 'POST', '/jobs/123'));
    }

    public function testIsAllowedRouteWithJobIdVariations(): void
    {
        $command = new ServeCommand();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('isAllowedRoute');

        $this->assertTrue($method->invoke($command, 'GET', '/jobs/abc123def456'));
        $this->assertTrue($method->invoke($command, 'GET', '/jobs/abc'));
        $this->assertFalse($method->invoke($command, 'GET', '/jobs/ABC123'));
        $this->assertFalse($method->invoke($command, 'GET', '/jobs/abc123extra'));
    }

    public function testIsAllowedRouteRejectsUnlistedMethods(): void
    {
        $command = new ServeCommand();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('isAllowedRoute');

        $this->assertFalse($method->invoke($command, 'PUT', '/analyze'));
        $this->assertFalse($method->invoke($command, 'PATCH', '/analyze'));
        $this->assertFalse($method->invoke($command, 'DELETE', '/jobs'));
        $this->assertFalse($method->invoke($command, 'OPTIONS', '/healthz'));
        $this->assertFalse($method->invoke($command, 'HEAD', '/healthz'));
    }

    public function testBindPublicSecurityCheckRequiresToken(): void
    {
        $command = new ServeCommand();

        $error = $command->validateConfig(
            bindPublic: true,
            token: null,
            host: '0.0.0.0',
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('--bind-public', $error);
        $this->assertStringContainsString('--token', $error);
    }

    public function testNonLoopbackBindWithoutFlagIsRejected(): void
    {
        $command = new ServeCommand();

        $error = $command->validateConfig(
            bindPublic: false,
            token: null,
            host: '192.168.1.100',
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('--bind-public', $error);
    }

    public function testPublicBindWithTokenPassesFirstSecurityCheck(): void
    {
        $command = new ServeCommand();

        $error = $command->validateConfig(
            bindPublic: true,
            token: 'secret-token',
            host: '0.0.0.0',
        );

        $this->assertNull($error, 'bind-public with a valid token must pass validation');
    }

    // ------------------------------------------------------------------
    // shell-S01: worker spawn carries the serve root as a third argv token
    // ------------------------------------------------------------------

    public function testWorkerCommandPassesServeRootAsEscapedThirdToken(): void
    {
        $command = new ServeCommand();
        $method = new \ReflectionMethod($command, 'workerCommand');

        $cmd = $method->invoke(
            $command,
            '/bin/worker bin',
            '/tmp/my sock.sock',
            '/tmp/results dir',
            '/tmp/serve root'
        );

        $this->assertSame(
            escapeshellarg('/bin/worker bin') . ' '
            . escapeshellarg('/tmp/my sock.sock') . ' '
            . escapeshellarg('/tmp/results dir') . ' '
            . escapeshellarg('/tmp/serve root'),
            $cmd
        );
    }

    public function testWorkerCommandOmitsEmptyServeRoot(): void
    {
        $command = new ServeCommand();
        $method = new \ReflectionMethod($command, 'workerCommand');

        $cmd = $method->invoke($command, '/bin/worker', '/tmp/w.sock', '/tmp/results', '');

        $this->assertSame(
            escapeshellarg('/bin/worker') . ' '
            . escapeshellarg('/tmp/w.sock') . ' '
            . escapeshellarg('/tmp/results'),
            $cmd
        );
    }

    // ------------------------------------------------------------------
    // shell-002: crash-proof request handling — a throwing application
    // fails one request with a well-formed 500 instead of killing the daemon
    // ------------------------------------------------------------------

    public function testRespondConvertsApplicationThrowIntoContained500(): void
    {
        $command = new ServeCommand();
        $method = new \ReflectionMethod($command, 'respond');

        $response = $method->invoke($command, static function (): array {
            throw new \RuntimeException('boom');
        });

        $this->assertSame(500, $response['status']);
        $this->assertSame('application/json', $response['headers']['Content-Type']);
        $decoded = json_decode($response['body'], true);
        $this->assertIsArray($decoded);
        $this->assertStringContainsString('boom', $decoded['error']);
    }

    public function testRespondPassesHealthyHandlerResponseThrough(): void
    {
        $command = new ServeCommand();
        $method = new \ReflectionMethod($command, 'respond');

        $ok = ['status' => 200, 'headers' => ['Content-Type' => 'text/plain'], 'body' => 'ok'];

        $this->assertSame(
            $ok,
            $method->invoke($command, static fn(): array => $ok)
        );
    }

    // ------------------------------------------------------------------
    // shell-001 over the raw transport: a poisoned /analyze body gets a
    // 400 response (never a crash) and the same Application keeps serving.
    // ------------------------------------------------------------------

    public function testHandleClientAnswers400ForNonStringPathsAndStaysAlive(): void
    {
        $app = new Application(new JobQueue(), sys_get_temp_dir(), null);
        $method = new \ReflectionMethod(ServeCommand::class, 'handleClient');

        // Poisoned request → 400 (was: TypeError → exit 255). The request is
        // written on $client and consumed by handleClient on $peer; the
        // response flows back the other way.
        [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($client);
        self::assertNotFalse($peer);

        $body = '{"paths":[123]}';
        $request = "POST /analyze HTTP/1.1\r\nHost: x\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
        self::assertNotFalse(@fwrite($client, $request));

        $method->invoke(new ServeCommand(), $peer, $app);
        fclose($peer);

        $raw = stream_get_contents($client);
        fclose($client);
        $this->assertIsString($raw);
        $this->assertStringStartsWith('HTTP/1.1 400', $raw);
        $this->assertStringContainsString('paths must contain only strings', $raw);

        // Daemon survived: a second request on the same Application serves.
        [$client2, $peer2] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($client2);
        self::assertNotFalse($peer2);

        self::assertNotFalse(@fwrite($client2, "GET /healthz HTTP/1.1\r\nHost: x\r\n\r\n"));
        $method->invoke(new ServeCommand(), $peer2, $app);
        fclose($peer2);

        $raw2 = stream_get_contents($client2);
        fclose($client2);
        $this->assertIsString($raw2);
        $this->assertStringStartsWith('HTTP/1.1 200', $raw2);
        $this->assertStringEndsWith('ok', $raw2);
    }

    /**
     * shell-032: the serve cache + sync-worker policy move from hard-coded to
     * operator flags. --serve-cache defaults OFF (the historic re-parse-fresh
     * posture); --serve-workers defaults to 1 (serial) so a many-core box no
     * longer forks detectCpuCount() children per request.
     */
    public function testServeCacheAndServeWorkersOptionsAreConfigured(): void
    {
        $definition = (new ServeCommand())->getDefinition();

        $cache = $definition->getOption('serve-cache');
        $this->assertFalse($cache->isValueRequired(), '--serve-cache is a flag');
        $this->assertFalse($cache->getDefault(), '--serve-cache defaults off (current behaviour)');

        $workers = $definition->getOption('serve-workers');
        $this->assertTrue($workers->isValueRequired());
        $this->assertSame('1', (string) $workers->getDefault(), 'serial by default');
    }

    /**
     * shell-032 (Fix-round-1, LOW-2): a non-numeric --serve-workers must be
     * refused by the startup gate, not cast to 0 = auto. `(int)'banana'` and
     * `(int)'-1'` both meant "auto" — the per-request fork storm the serial
     * default exists to prevent, chosen by a typo. validateConfig() is the
     * pre-bind gate, so the refusal lands before any socket or worker spawn.
     */
    public function testValidateConfigRejectsNonNumericServeWorkers(): void
    {
        $command = new ServeCommand();

        foreach (['banana', '-1', '2.5', '+3', '', '0x4'] as $raw) {
            $error = $command->validateConfig(
                bindPublic: false,
                token: null,
                host: '127.0.0.1',
                serveWorkers: $raw,
            );
            $this->assertNotNull($error, "--serve-workers '{$raw}' must not silently mean auto");
            $this->assertStringContainsString('--serve-workers', $error, "refusal must name the flag for '{$raw}'");
        }

        // A legitimate value still passes the gate unchanged (and 0 = explicit
        // auto opt-up stays admitted — this is validation, not policy).
        $this->assertNull($command->validateConfig(
            bindPublic: false,
            token: null,
            host: '127.0.0.1',
            serveWorkers: '4',
        ));
        $this->assertNull($command->validateConfig(
            bindPublic: false,
            token: null,
            host: '127.0.0.1',
            serveWorkers: '0',
        ));
    }

    /**
     * The refusal is an exit-1 startup posture with a readable message — the
     * serve never begins, so nothing binds and no worker spawns. (The
     * validation-before-bind ordering is the same one the bind-public
     * rejection pins above hold: run() returns 1 before the socket.)
     */
    public function testServeExitsOneBeforeBindOnNonNumericServeWorkers(): void
    {
        $command = new ServeCommand();
        $command->setApplication(new ConsoleApplication('phpdup', '1.0.0'));

        $input = new ArrayInput([
            '--host' => '127.0.0.1',
            '--port' => '18998',
            '--serve-workers' => 'banana',
        ]);
        $output = new BufferedOutput();

        $this->assertSame(1, $command->run($input, $output), 'a typo must refuse startup, not mean auto');
        $this->assertStringContainsString('--serve-workers', $output->fetch());

        // Socket-proven "before serve starts": if the gate ever regresses to
        // bind-then-complain (or accept-loop forever), this connect flips the
        // test red instead of hanging the suite — no listener was ever
        // opened on the port we passed to the refused command.
        $errno = 0;
        $errstr = '';
        $probe = @stream_socket_client('tcp://127.0.0.1:18998', $errno, $errstr, 2.0);
        if ($probe !== false) {
            fclose($probe);
            $this->fail('a refused serve must never open the listener');
        }
        $this->assertStringContainsStringIgnoringCase('refused', $errstr);
    }

    // ------------------------------------------------------------------
    // shell-S35: the phar build is sync-only — a Unix spawn can never exec
    // the worker from inside the artifact (shell: rc 127 on a phar://
    // program path; php: refuses to open a phar:// main script), so the
    // posture is announced up front instead of surfacing as a socket timeout
    // ------------------------------------------------------------------

    public function testPharPostureWarnsAndRefusesWorkerSpawn(): void
    {
        $command = new ServeCommand();
        $command->pharRunningOverride = '/tmp/anywhere/phpdup.phar';
        $output = new BufferedOutput();

        $method = new \ReflectionMethod($command, 'spawnWorker');

        // null pid means execute() falls through to the sync-mode
        // Application; the spawn never reaches the socket wait, the
        // results-dir leg, or proc_open at all.
        $this->assertNull($method->invoke($command, $output, sys_get_temp_dir()));

        $printed = $output->fetch();
        $this->assertStringContainsString('phar build', $printed);
        $this->assertStringContainsString('cannot be spawned', $printed);
        $this->assertStringContainsString('running in sync mode', $printed);
    }

    public function testCheckoutPostureStillReachesTheWorkerBinaryLegs(): void
    {
        $command = new ServeCommand();
        // '' pins the checkout posture verbatim: the phar branch must stay
        // invisible when the daemon runs from the tree.
        $command->pharRunningOverride = '';
        $output = new BufferedOutput();

        $method = new \ReflectionMethod($command, 'spawnWorker');

        // Called directly without a prepared results dir, the documented
        // checkout leg answers with ITS notice — proving control passed the
        // phar short-circuit and the worker-binary existence check (real
        // bin/phpdup-worker on this checkout) untouched.
        $this->assertNull($method->invoke($command, $output, sys_get_temp_dir()));

        $printed = $output->fetch();
        $this->assertStringNotContainsString('phar', $printed);
        $this->assertStringContainsString('result dir not prepared', $printed);
    }
}
