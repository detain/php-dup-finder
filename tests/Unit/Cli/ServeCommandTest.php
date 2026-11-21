<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use Phpdup\Cli\ServeCommand;
use Phpdup\Server\Application;
use Phpdup\Server\JobQueue;
use PHPUnit\Framework\TestCase;

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
}
