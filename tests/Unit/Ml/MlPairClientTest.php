<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Ml;

use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\Block;
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
}
