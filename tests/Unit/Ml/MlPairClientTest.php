<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Ml;

use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\Block;
use Phpdup\Ml\MlClient;
use Phpdup\Ml\MlPairClient;
use Phpdup\Parsing\AstParser;
use Phpdup\Util\LineRange;

final class MlPairClientTest extends TestCase
{
    public function testEmptyBaseUrlReturnsNull(): void
    {
        $client = new MlPairClient('');
        $a = $this->makeBlock();
        $b = $this->makeBlock();
        self::assertNull($client->score($a, $b));
    }

    public function testInvalidUrlReturnsNull(): void
    {
        $client = new MlPairClient('file:///etc/passwd');
        $a = $this->makeBlock();
        $b = $this->makeBlock();
        self::assertNull($client->score($a, $b));
    }

    /**
     * engine-S19a refuse-list matrix through the real score() door: the
     * gate must refuse BEFORE any transport is attempted, so every case
     * below is hermetic (no DNS, no connect). Covers the same set for
     * scoreBatch() because the batch endpoint shares the gate check.
     */
    public function testRejectedUrlProviderCoversBothEndpoints(): void
    {
        $a = $this->makeBlock();
        $b = $this->makeBlock();
        foreach (self::rejectedUrlProvider() as $case) {
            [$url] = $case;
            $client = new MlPairClient($url, timeoutSec: 1);
            self::assertNull($client->score($a, $b), "score() must refuse: $url");
            self::assertNull($client->scoreBatch([[$a, $b]]), "scoreBatch() must refuse: $url");
        }
    }

    /**
     * PoC class from php-dup-finder-engine-012: loopback spellings that
     * the OLD gate waved through (0x7f000001 et al.) aimed at a live
     * in-test listener on 127.0.0.1. Refused at the gate now; the
     * listener must never accept a connection.
     */
    public function testPoisonedLoopbackSpellingsNeverReachListener(): void
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            self::markTestSkipped("loopback bind unavailable: $errstr");
        }
        try {
            $name = (string)stream_socket_get_name($server, false);
            $port = (int)substr($name, strrpos($name, ':') + 1);
            self::assertGreaterThan(0, $port);

            $a = $this->makeBlock();
            $b = $this->makeBlock();
            foreach ([
                "http://0x7f000001:$port",
                "http://2130706433:$port",
                "http://127.1:$port",
                "http://0177.0.0.1:$port",
                "http://127.0.0.1:$port",
                "http://[::1]:$port",
                "http://[::ffff:127.0.0.1]:$port",
                "http://localhost:$port",
            ] as $poison) {
                $client = new MlPairClient($poison, timeoutSec: 1);
                self::assertNull($client->score($a, $b), "must refuse: $poison");
            }

            $read = [$server];
            $write = null;
            $except = null;
            self::assertSame(
                0,
                stream_select($read, $write, $except, 0, 50_000),
                'no connection may reach the loopback listener',
            );
        } finally {
            fclose($server);
        }
    }

    /**
     * @dataProvider rejectedUrlProvider
     */
    public function testRejectsUnsafeProtocols(string $url): void
    {
        $client = new MlPairClient($url);
        $a = $this->makeBlock();
        $b = $this->makeBlock();
        self::assertNull($client->score($a, $b));
    }

    /** @return array<string,array{0:string}> */
    public static function rejectedUrlProvider(): array
    {
        return [
            'file scheme'   => ['file:///etc/passwd'],
            'gopher scheme' => ['gopher://example.com/'],
            'ftp scheme'    => ['ftp://example.com/foo'],
            'no host'       => ['http:///score-pair'],
            '0.0.0.0 host'  => ['http://0.0.0.0:8000/score-pair'],
            // engine-012 PoC spellings of loopback
            'hex 0x7f000001' => ['http://0x7f000001:8000/score-pair'],
            'decimal 2130706433' => ['http://2130706433:8000/score-pair'],
            'abbrev 127.1'   => ['http://127.1:8000/score-pair'],
            'octal 0177.0.0.1' => ['http://0177.0.0.1:8000/score-pair'],
            // refused address space, canonical literals
            'loopback 127.0.0.1' => ['http://127.0.0.1:8000/score-pair'],
            'v6 loopback [::1]'  => ['http://[::1]:8000/score-pair'],
            'v6 expanded loopback' => ['http://[0:0:0:0:0:0:0:1]:8000/score-pair'],
            'v4-mapped loopback' => ['http://[::ffff:127.0.0.1]:8000/score-pair'],
            'ULA [fd12:3456::1]' => ['http://[fd12:3456::1]:8000/score-pair'],
            'v6 link-local [fe80::1]' => ['http://[fe80::1]:8000/score-pair'],
            'metadata 169.254.169.254' => ['http://169.254.169.254/score-pair'],
            'RFC1918 10.0.0.1'   => ['http://10.0.0.1/score-pair'],
            'RFC1918 172.20.5.6' => ['http://172.20.5.6/score-pair'],
            'RFC1918 192.168.1.1' => ['http://192.168.1.1/score-pair'],
            'CGNAT 100.100.7.7'  => ['http://100.100.7.7/score-pair'],
            'name localhost'     => ['http://localhost:8000/score-pair'],
        ];
    }

    public function testUnreachableServerReturnsNull(): void
    {
        // Since engine-S19a this URL is refused by the SSRF gate itself
        // (127.0.0.1 is loopback), so score() returns null without ever
        // attempting a connect. Behaviour for the caller is unchanged —
        // null, no throw — and the test stays as the caller-facing pin
        // of that posture.
        $client = new MlPairClient('http://127.0.0.1:1', timeoutSec: 1);
        $a = $this->makeBlock();
        $b = $this->makeBlock();
        self::assertNull($client->score($a, $b));
    }

    private function makeBlock(): Block
    {
        $stmts = (new AstParser())->parseCode('<?php function f() { return 1; }');
        $block = new Block(
            file: 'test.php',
            range: new LineRange(1, 1),
            kind: 'function',
            namespace: null,
            class: null,
            name: 'f',
            ast: $stmts[0],
        );
        // Normalize so $canonical is initialised before feature
        // extraction runs against it.
        (new \Phpdup\Normalization\Normalizer(mode: 'aggressive'))->normalize($block);
        return $block;
    }

    /**
     * @dataProvider httpCodeProvider
     * @param list<string> $headers
     */
    public function testParseStatusCode(array $headers, int $expectedCode): void
    {
        $client = new MlPairClient('http://example.com');
        $r = new \ReflectionMethod($client, 'parseStatusCode');
        self::assertSame($expectedCode, $r->invoke($client, $headers));
    }

    /** @return array<string,array{0:list<string>,1:int}> */
    public static function httpCodeProvider(): array
    {
        return [
            '200 OK'           => [['HTTP/1.1 200 OK', 'Content-Type: application/json'], 200],
            '201 Created'      => [['HTTP/1.1 201 Created', 'Content-Type: application/json'], 201],
            '299 OK'           => [['HTTP/1.1 299 OK'], 299],
            '400 Bad Request'  => [['HTTP/1.1 400 Bad Request'], 400],
            '500 Internal Err' => [['HTTP/1.1 500 Internal Server Error'], 500],
            '502 Bad Gateway'  => [['HTTP/1.1 502 Bad Gateway'], 502],
            'no headers'       => [[], 0],
            'no HTTP prefix'   => [['Content-Type: application/json'], 0],
            'HTTP/2 200'       => [['HTTP/2 200 OK'], 200],
        ];
    }

    /**
     * @group network
     */
    public function testNon2xxResponseReturnsNull(): void
    {
        $client = new MlPairClient('https://httpbin.org/status/500', timeoutSec: 10);
        $a = $this->makeBlock();
        $b = $this->makeBlock();
        self::assertNull($client->score($a, $b));
    }

    // =====================================================================
    // engine-S19b — the pair client's transports share MlClient's pinning
    // and cap policy (curlPinEntries/pinnedStreamRequest/MAX_RESPONSE_
    // BYTES), so seam tests mirror MlClientTest's against this class's
    // own private postJsonCurl/postJsonStream to prove the mirror cannot
    // drift. Rationale for seam-level proof (loopback pin vs refusing
    // gate) is documented there.
    // =====================================================================

    public function testGateUrlRefusesEveryRejectedPairUrl(): void
    {
        foreach (self::rejectedUrlProvider() as [$url]) {
            self::assertNull(MlClient::gateUrl($url), "gate decision must refuse: $url");
        }
    }

    public function testPinnedCurlTransportConnectsCheckedIpWithoutResolver(): void
    {
        [$pid, $port, $server] = $this->forkHttpServer(
            static fn(string $request): string => self::echoResponseFor($request)
        );
        try {
            $client   = new MlPairClient('http://ml.invalid', timeoutSec: 3);
            $decision = ['host' => 'ml.invalid', 'port' => $port, 'addresses' => ['127.0.0.1']];
            $method   = new \ReflectionMethod($client, 'postJsonCurl');
            $resp     = $method->invoke($client, "http://ml.invalid:$port/score-batch", '{}', $decision);
            self::assertIsString($resp, 'pinned curl dial of the checked address failed');
            self::assertStringContainsString('POST /score-batch', $resp);
            self::assertStringContainsString("Host: ml.invalid:$port", $resp);
        } finally {
            fclose($server);
            $this->reapForkedHttpServer($pid);
        }
    }

    public function testPinnedStreamTransportConnectsCheckedIpWithoutResolver(): void
    {
        [$pid, $port, $server] = $this->forkHttpServer(
            static fn(string $request): string => self::echoResponseFor($request),
            2
        );
        try {
            $client   = new MlPairClient('http://ml.invalid', timeoutSec: 3);
            $decision = ['host' => 'ml.invalid', 'port' => $port, 'addresses' => ['127.0.0.1']];
            $method   = new \ReflectionMethod($client, 'postJsonStream');
            $resp     = $method->invoke($client, "http://ml.invalid:$port/score-batch", '{}', $decision);
            self::assertIsString($resp, 'pinned stream rewrite to the checked address failed');
            self::assertStringContainsString('POST /score-batch', $resp);
            self::assertStringContainsString("Host: ml.invalid:$port", $resp);
        } finally {
            fclose($server);
            $this->reapForkedHttpServer($pid);
        }
    }

    public function testOversizedBodyCappedOnCurlTransport(): void
    {
        $big      = str_repeat('X', 4 * MlClient::MAX_RESPONSE_BYTES);
        $response = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($big) . "\r\n\r\n" . $big;
        [$pid, $port, $server] = $this->forkHttpServer(
            static fn(string $request): string => $response,
            5
        );
        try {
            $client   = new MlPairClient('http://127.0.0.1', timeoutSec: 5);
            $decision = ['host' => '127.0.0.1', 'port' => $port, 'addresses' => ['127.0.0.1']];
            $mark     = memory_get_usage();
            $method   = new \ReflectionMethod($client, 'postJsonCurl');
            $resp     = $method->invoke($client, "http://127.0.0.1:$port/score-batch", '{}', $decision);
            $delta    = memory_get_usage() - $mark;
            self::assertNull($resp, 'over-cap body must be refused');
            self::assertLessThan(2 * MlClient::MAX_RESPONSE_BYTES, $delta, "curl transport ballooned RSS: $delta bytes");
        } finally {
            fclose($server);
            $this->reapForkedHttpServer($pid);
        }
    }

    public function testOversizedBodyCappedOnStreamTransport(): void
    {
        $big      = str_repeat('X', 4 * MlClient::MAX_RESPONSE_BYTES);
        $response = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($big) . "\r\n\r\n" . $big;
        [$pid, $port, $server] = $this->forkHttpServer(
            static fn(string $request): string => $response,
            5
        );
        try {
            $client   = new MlPairClient('http://127.0.0.1', timeoutSec: 5);
            $decision = ['host' => '127.0.0.1', 'port' => $port, 'addresses' => ['127.0.0.1']];
            $mark     = memory_get_usage();
            $method   = new \ReflectionMethod($client, 'postJsonStream');
            $resp     = $method->invoke($client, "http://127.0.0.1:$port/score-batch", '{}', $decision);
            $delta    = memory_get_usage() - $mark;
            self::assertNull($resp, 'over-cap body must be refused');
            self::assertLessThan(2 * MlClient::MAX_RESPONSE_BYTES, $delta, "stream transport ballooned RSS: $delta bytes");
        } finally {
            fclose($server);
            $this->reapForkedHttpServer($pid);
        }
    }

    public function testEmptyAddressListRefusedWithoutConnection(): void
    {
        [$pid, $port, $server] = $this->forkHttpServer(
            static fn(string $request): string => self::echoResponseFor($request),
            2
        );
        try {
            $client   = new MlPairClient('http://127.0.0.1', timeoutSec: 2);
            $decision = ['host' => '127.0.0.1', 'port' => $port, 'addresses' => []];
            $curl     = new \ReflectionMethod($client, 'postJsonCurl');
            self::assertNull($curl->invoke($client, "http://127.0.0.1:$port/score-batch", '{}', $decision));
            $stream = new \ReflectionMethod($client, 'postJsonStream');
            self::assertNull($stream->invoke($client, "http://127.0.0.1:$port/score-batch", '{}', $decision));
            // Fail-closed means no dial was even attempted.
            $read = [$server];
            $w    = null;
            $e    = null;
            self::assertSame(0, stream_select($read, $w, $e, 0, 50_000));
        } finally {
            fclose($server);
            $this->reapForkedHttpServer($pid);
        }
    }

    /**
     * Response that reflects the first request line and the Host header:
     * bytes visible only to a client that actually talked to the listener.
     */
    private static function echoResponseFor(string $request): string
    {
        $line = strtok($request, "\r\n");
        $host = preg_match('/^Host: .*$/mi', $request, $m) === 1 ? rtrim($m[0], "\r") : '(none)';
        $body = ($line === false ? '' : $line) . "\n" . $host;
        return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
    }

    /**
     * One-shot loopback HTTP server in a forked child (mirrors
     * MlClientTest's helper; test files cannot share code without a new
     * unlocked fixture, so this is a deliberate copy of the same
     * discipline, including the multi-connection $serveCount arm).
     *
     * @param callable(string): string $responseFor
     * @return array{0: int, 1: int, 2: resource} [pid, port, listener]
     */
    private function forkHttpServer(callable $responseFor, int $acceptTimeoutSec = 5, int $serveCount = 1): array
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            self::markTestSkipped('forked HTTP server requires pcntl_fork/pcntl_waitpid');
        }
        if (!function_exists('posix_kill')) {
            self::markTestSkipped('forked HTTP server reaper requires posix_kill');
        }
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            self::markTestSkipped("loopback bind unavailable: $errstr");
        }
        $name = (string)stream_socket_get_name($server, false);
        $port = (int)substr($name, (int)strrpos($name, ':') + 1);
        self::assertGreaterThan(0, $port);

        $pid = pcntl_fork();
        if ($pid === -1) {
            fclose($server);
            self::markTestSkipped('pcntl_fork failed');
        }
        if ($pid === 0) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            $served = 0;
            for ($i = 0; $i < $serveCount; $i++) {
                $conn = @stream_socket_accept($server, $acceptTimeoutSec);
                if ($conn === false) {
                    break;
                }
                $served++;
                stream_set_timeout($conn, $acceptTimeoutSec);
                $request = '';
                while (!str_contains($request, "\r\n\r\n")) {
                    $chunk = fread($conn, 8192);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $request .= $chunk;
                }
                if (preg_match('/^Content-Length: (\d+)$/mi', $request, $m) === 1) {
                    $remaining = (int)$m[1];
                    while ($remaining > 0) {
                        $chunk = fread($conn, min(8192, $remaining));
                        if ($chunk === false || $chunk === '') {
                            break;
                        }
                        $remaining -= strlen($chunk);
                    }
                }
                @fwrite($conn, $responseFor($request));
                fclose($conn);
            }
            fclose($server);
            exit($served > 0 ? 0 : 1);
        }
        return [$pid, $port, $server];
    }

    private function reapForkedHttpServer(int $pid): void
    {
        $status   = 0;
        $deadline = microtime(true) + 20.0;
        while (microtime(true) < $deadline) {
            $reaped = pcntl_waitpid($pid, $status, WNOHANG);
            if ($reaped === $pid) {
                return;
            }
            if ($reaped === -1) {
                self::fail("pcntl_waitpid failed for forked server $pid");
            }
            usleep(20_000);
        }
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
        self::fail("forked HTTP server $pid never finished — client stalled or never connected");
    }
}
