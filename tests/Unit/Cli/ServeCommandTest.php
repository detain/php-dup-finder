<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use Phpdup\Cli\ServeCommand;
use PHPUnit\Framework\TestCase;

final class ServeCommandTest extends TestCase
{
    public function testIsLoopbackDetectsLoopbackAddresses(): void
    {
        $command = new ServeCommand();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('isLoopback');
        $method->setAccessible(true);

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
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($command, '10.0.0.1'));
        $this->assertFalse($method->invoke($command, '172.16.0.1'));
        $this->assertFalse($method->invoke($command, '8.8.8.8'));
    }

    public function testExtractHeadersSeparatesKeyFromValue(): void
    {
        $command = new ServeCommand();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('extractHeaders');
        $method->setAccessible(true);

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
        $method->setAccessible(true);

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
        $method->setAccessible(true);

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
        $method->setAccessible(true);

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
        $method->setAccessible(true);

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
        $method->setAccessible(true);

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
        $method->setAccessible(true);

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
}
