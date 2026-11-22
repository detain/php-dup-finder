<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Util;

use PHPUnit\Framework\TestCase;
use Phpdup\Util\Http;

/**
 * once-S89 (php-dup-finder-once-112) — direct pins on the shared JSON
 * POST transport the two Ml clients collapsed onto.
 *
 * The clients' own suites (tests/Unit/Ml/*) keep their era pins and now
 * drive Util\Http THROUGH the reflection seams; this file tests the
 * helper on its own contract: refusals dial nothing (honest-fail, the
 * once-S46 doctrine — a null verdict must never be a plausible empty
 * one), the pin shapes are honored on the wire, the byte ceiling is
 * exact, non-2xx is refused, and — the structural half of the twin
 * collapse — no file on the always-compiled transport path carries a
 * compiled read of the PHP 8.5-deprecated response-header magic
 * variable (the divergence bug once-112 caught: MlClient's stream leg
 * kept the never-taken ternary mention its twin had isolated behind
 * LegacyStreamPost; the collapse left exactly ONE correct leg).
 *
 * Hermetic by construction: forked loopback one-shot HTTP servers on
 * ephemeral ports (the house harness, mirrored from MlClientTest),
 * poll-until-deadline reaping, no fixed sleeps, no resolver traffic
 * (every dial target is the 127.0.0.1 literal), no live network.
 */
final class HttpTest extends TestCase
{
    /** Small explicit cap: Http takes the ceiling as a parameter (once-S89 layering — the policy constant stays with the Ml clients). */
    private const CAP = 64;

    private static function requireCurl(): void
    {
        if (!function_exists('curl_init')) {
            self::markTestSkipped('ext-curl not loaded; curl seam untestable here');
        }
    }

    // =====================================================================
    // Honest refusal: pins/pin null → no body AND no dial.
    // =====================================================================

    public function testCurlPinningRefusalDialsNothing(): void
    {
        self::requireCurl();
        [$pid, $port, $server] = $this->forkHttpServer(self::echoResponse(), 2);
        try {
            self::assertNull(Http::curlPost("http://127.0.0.1:$port/score", '{}', null, null, 2, self::CAP));
            $this->assertNoConnectionArrived($server);
        } finally {
            fclose($server);
            $this->reapForkedHttpServer($pid);
        }
    }

    public function testStreamPinningRefusalDialsNothing(): void
    {
        [$pid, $port, $server] = $this->forkHttpServer(self::echoResponse(), 2);
        try {
            self::assertNull(Http::streamPost(null, '{}', 2, self::CAP));
            $this->assertNoConnectionArrived($server);
        } finally {
            fclose($server);
            $this->reapForkedHttpServer($pid);
        }
    }

    // =====================================================================
    // curl leg: fresh handle, literal pins, cap, status, reuse contract.
    // =====================================================================

    public function testCurlPostRoundTripsWithEmptyPinsOnLiteralHost(): void
    {
        self::requireCurl();
        [$pid, $port, $server] = $this->forkHttpServer(self::echoResponse());
        try {
            $resp = Http::curlPost("http://127.0.0.1:$port/score", '{"pairs":[]}', [], null, 3, 1_048_576);
            self::assertIsString($resp, 'fresh-handle curl dial of a literal host failed');
            self::assertStringContainsString('POST /score', $resp);
        } finally {
            fclose($server);
            $this->reapForkedHttpServer($pid);
        }
    }

    public function testCurlPostRefusesNon2xx(): void
    {
        self::requireCurl();
        $response = "HTTP/1.1 503 Service Unavailable\r\nContent-Length: 6\r\n\r\nnope!!";
        [$pid, $port, $server] = $this->forkHttpServer(static fn(string $r): string => $response, 5, 1);
        try {
            self::assertNull(Http::curlPost("http://127.0.0.1:$port/score", '{}', [], null, 3, self::CAP));
        } finally {
            fclose($server);
            $this->reapForkedHttpServer($pid);
        }
    }

    public function testCurlAcceptsBodyExactlyAtCap(): void
    {
        self::requireCurl();
        $at  = str_repeat('A', self::CAP);
        $resp = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($at) . "\r\n\r\n" . $at;
        [$pid, $port, $server] = $this->forkHttpServer(static fn(string $r): string => $resp, 5, 1);
        try {
            self::assertSame($at, Http::curlPost("http://127.0.0.1:$port/score", '{}', [], null, 3, self::CAP));
        } finally {
            fclose($server);
            $this->reapForkedHttpServer($pid);
        }
    }

    public function testCurlRefusesBodyOneByteOverCap(): void
    {
        self::requireCurl();
        $over  = str_repeat('B', self::CAP + 1);
        $resp = "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($over) . "\r\n\r\n" . $over;
        [$pid, $port, $server] = $this->forkHttpServer(static fn(string $r): string => $resp, 5, 1);
        try {
            self::assertNull(Http::curlPost("http://127.0.0.1:$port/score", '{}', [], null, 3, self::CAP));
        } finally {
            fclose($server);
            $this->reapForkedHttpServer($pid);
        }
    }

    public function testCurlPostReusesProvidedHandleWithoutStateBleed(): void
    {
        self::requireCurl();
        $ch = curl_init();
        self::assertNotFalse($ch);
        [$pid, $port, $server] = $this->forkHttpServer(self::echoResponse(), 5, 2);
        try {
            $a = Http::curlPost("http://127.0.0.1:$port/first", '{}', [], $ch, 3, self::CAP);
            $b = Http::curlPost("http://127.0.0.1:$port/second", '{}', [], $ch, 3, self::CAP);
            // Every per-request option is re-applied on the shared handle:
            // the second call must see the SECOND path, not the first.
            self::assertIsString($a);
            self::assertStringContainsString('POST /first', $a);
            self::assertIsString($b);
            self::assertStringContainsString('POST /second', $b);
            self::assertStringNotContainsString('/first', $b);
        } finally {
            fclose($server);
            $this->reapForkedHttpServer($pid);
        }
    }

    public function testCurlPostPinsNamedHostToCheckedAddress(): void
    {
        self::requireCurl();
        [$pid, $port, $server] = $this->forkHttpServer(self::echoResponse());
        try {
            // The resolver is never consulted for ml.invalid: the pin
            // entry preloads the checked 127.0.0.1 for host:port.
            $pins = ['ml.invalid:' . $port . ':127.0.0.1'];
            $resp = Http::curlPost("http://ml.invalid:$port/score-pair", '{}', $pins, null, 3, self::CAP);
            self::assertIsString($resp, 'pinned curl dial of the checked address failed');
            self::assertStringContainsString('POST /score-pair', $resp);
            self::assertStringContainsString("Host: ml.invalid:$port", $resp);
        } finally {
            fclose($server);
            $this->reapForkedHttpServer($pid);
        }
    }

    // =====================================================================
    // stream leg: pin record honored, cap, status.
    // =====================================================================

    public function testStreamPostRewrittenPinCarriesExplicitHostHeader(): void
    {
        [$pid, $port, $server] = $this->forkHttpServer(self::echoResponse(), 5, 1);
        try {
            // Exactly the shape MlClient::pinnedStreamRequest mints for a
            // named host: URL points at the checked address, authority
            // travels in the header (RFC 9112 §3.2).
            $pin  = [
                'url'       => "http://127.0.0.1:$port/score-batch",
                'header'    => "Content-Type: application/json\r\nHost: ml.invalid:$port\r\n",
                'peer_name' => null,
            ];
            $resp = Http::streamPost($pin, '{}', 3, self::CAP + 1024);
            self::assertIsString($resp, 'pinned stream rewrite to the checked address failed');
            self::assertStringContainsString('POST /score-batch', $resp);
            self::assertStringContainsString("Host: ml.invalid:$port", $resp);
        } finally {
            fclose($server);
            $this->reapForkedHttpServer($pid);
        }
    }

    public function testStreamPostRefusesNon2xx(): void
    {
        $response = "HTTP/1.1 404 Not Found\r\nContent-Length: 3\r\n\r\nnop";
        [$pid, $port, $server] = $this->forkHttpServer(static fn(string $r): string => $response, 5, 1);
        try {
            $pin  = ['url' => "http://127.0.0.1:$port/score", 'header' => "Content-Type: application/json\r\n", 'peer_name' => null];
            self::assertNull(Http::streamPost($pin, '{}', 3, self::CAP));
        } finally {
            fclose($server);
            $this->reapForkedHttpServer($pid);
        }
    }

    public function testStreamBoundedReadCapIsExact(): void
    {
        // The ceiling is passed as $maxReadBytes+1 to the wrapper read:
        // a cap-length body survives, a longer one is refused whole.
        $at = str_repeat('C', self::CAP);
        $ok = "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($at) . "\r\n\r\n" . $at;
        [$pid, $port, $server] = $this->forkHttpServer(static fn(string $r): string => $ok, 5, 1);
        try {
            $pin = ['url' => "http://127.0.0.1:$port/score", 'header' => "Content-Type: application/json\r\n", 'peer_name' => null];
            self::assertSame($at, Http::streamPost($pin, '{}', 3, self::CAP));
        } finally {
            fclose($server);
            $this->reapForkedHttpServer($pid);
        }

        $big = str_repeat('D', self::CAP + 1);
        $resp = "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($big) . "\r\n\r\n" . $big;
        [$pid2, $port2, $server2] = $this->forkHttpServer(static fn(string $r): string => $resp, 5, 1);
        try {
            $pin2 = ['url' => "http://127.0.0.1:$port2/score", 'header' => "Content-Type: application/json\r\n", 'peer_name' => null];
            self::assertNull(Http::streamPost($pin2, '{}', 3, self::CAP), 'over-cap body must be refused whole, never truncated-then-returned');
        } finally {
            fclose($server2);
            $this->reapForkedHttpServer($pid2);
        }
    }

    // =====================================================================
    // Shared status-line parser (the twins' byte-identical 8 lines, now
    // one function; matrix mirrors both clients' era providers).
    // =====================================================================

    /**
     * @dataProvider httpCodeProvider
     * @param list<string> $headers
     */
    public function testParseStatusCode(array $headers, int $expectedCode): void
    {
        self::assertSame($expectedCode, Http::parseStatusCode($headers));
    }

    /** @return array<string,array{0:list<string>,1:int}> */
    public static function httpCodeProvider(): array
    {
        return [
            '200 OK'           => [['HTTP/1.1 200 OK', 'Content-Type: application/json'], 200],
            '204 No Content'   => [['HTTP/1.1 204 No Content'], 204],
            '299 OK'           => [['HTTP/1.1 299 OK'], 299],
            '400 Bad Request'  => [['HTTP/1.1 400 Bad Request'], 400],
            '500 Internal Err' => [['HTTP/1.1 500 Internal Server Error'], 500],
            'HTTP/2 200'       => [['HTTP/2 200 OK'], 200],
            'no headers'       => [[], 0],
            'no HTTP prefix'   => [['Content-Type: application/json'], 0],
        ];
    }

    // =====================================================================
    // Structural half of once-112's divergence fix: the deprecated
    // magic-variable READ may exist compiled only inside the lazily
    // loaded legacy helper — every file on the always-compiled path
    // (both clients, Util\Http, this test) must carry ZERO. Counted at
    // token level (T_VARIABLE only), so prose mentions in docblocks and
    // the needle built below are invisible to nothing and safe for 8.5.
    // =====================================================================

    public function testNoCompiledMagicVariableReadOnAlwaysLoadedPath(): void
    {
        $needle = '$' . 'http_response_header';
        $root   = dirname(__DIR__, 3);

        foreach (
            [
                'src/Util/Http.php',
                'src/Ml/MlClient.php',
                'src/Ml/MlPairClient.php',
                'tests/Unit/Util/HttpTest.php',
            ] as $relative
        ) {
            $file = $root . '/' . $relative;
            self::assertFileExists($file);
            self::assertSame(
                0,
                $this->countCompiledVariableReads($file, $needle),
                "$relative must not compile a read of the PHP 8.5-deprecated response-header magic variable"
            );
        }

        // The helper keeps exactly the one compiled read that powers the
        // pre-8.5 branch, and remains referenced ONLY from a branch
        // PHP >= 8.5 never executes (see Util\Http::streamPost comment).
        self::assertSame(
            1,
            $this->countCompiledVariableReads($root . '/src/Ml/LegacyStreamPost.php', $needle),
            'the legacy helper must keep its single compiled read — the transport has no other pre-8.5 header path'
        );
    }

    /**
     * T_VARIABLE occurrences only: comments are stripped by the tokenizer
     * itself and quoted needles never become variable tokens.
     */
    private function countCompiledVariableReads(string $file, string $needle): int
    {
        $count  = 0;
        $tokens = token_get_all((string) file_get_contents($file));
        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_VARIABLE && $token[1] === $needle) {
                $count++;
            }
        }
        return $count;
    }

    // =====================================================================
    // House fork-loopback harness (mirrors MlClientTest; pcntl-guarded).
    // =====================================================================

    /**
     * Response reflecting the first request line + Host header — bytes
     * only a client that actually talked to the listener can observe.
     *
     * @return callable(string): string
     */
    private static function echoResponse(): callable
    {
        return static function (string $request): string {
            $line = strtok($request, "\r\n");
            $host = preg_match('/^Host: .*$/mi', $request, $m) === 1 ? rtrim($m[0], "\r") : '(none)';
            $body = ($line === false ? '' : $line) . "\n" . $host;
            return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
        };
    }

    /**
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
        $name = (string) stream_socket_get_name($server, false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
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

    /**
     * Bounded reap (poll-until-deadline, house discipline): the child
     * finishes by serving or by accept-timeout. Anything else is a hung
     * transport — kill and fail loudly.
     */
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

    /**
     * Negative control: nothing in the accept queue → no dial attempted.
     *
     * @param resource $server
     */
    private function assertNoConnectionArrived($server): void
    {
        $read = [$server];
        $w    = null;
        $e    = null;
        self::assertSame(
            0,
            stream_select($read, $w, $e, 0, 50_000),
            'transport attempted a connection it must refuse'
        );
    }
}
