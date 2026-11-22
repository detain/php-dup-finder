<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Ml;

use PHPUnit\Framework\TestCase;
use Phpdup\Ml\MlClient;

final class MlClientTest extends TestCase
{
    /**
     * Gate matrix for engine-S19a (php-dup-finder-engine-012).
     *
     * HERMETIC by construction: every refusal is decided before the
     * resolvers (blocked canonical literals, the localhost deny-list, and
     * the inet_aton shorthand family which the gate refuses as invalid
     * literals); every acceptance is a canonical public IP literal, so no
     * row performs DNS. The two resolver-dependent rows live in dedicated
     * @group network tests below.
     *
     * @return array<string,array{0:string,1:bool}>
     */
    public static function urlProvider(): array
    {
        return [
            // SSRF — IPv4 loopback and "this host" space
            'reject 127.0.0.1'    => ['http://127.0.0.1:8000/score', false],
            'reject 127.255.255.255' => ['http://127.255.255.255/', false],
            'reject 0.0.0.0'      => ['http://0.0.0.0:8000/score', false],
            'reject 0.1.2.3'      => ['http://0.1.2.3/', false],
            // SSRF — localhost deny-list (RFC 6761), no resolution
            'reject localhost'    => ['http://localhost/', false],
            'reject localhost:8080' => ['http://localhost:8080/', false],
            'reject x.localhost'  => ['http://ml.localhost/', false],
            // SSRF — IPv6 loopback, unspecified, expanded forms
            'reject [::1]'        => ['http://[::1]/', false],
            'reject [0:0:0:0:0:0:0:1]' => ['http://[0:0:0:0:0:0:0:1]/', false],
            'reject [::]'         => ['http://[::]/', false],
            'reject [::2]'        => ['http://[::2]/', false],
            // SSRF — IPv4-mapped IPv6 wrapping loopback / private
            'reject [::ffff:127.0.0.1]' => ['http://[::ffff:127.0.0.1]/', false],
            'reject [::ffff:10.1.2.3]'  => ['http://[::ffff:10.1.2.3]/', false],
            'reject [::ffff:169.254.1.1]' => ['http://[::ffff:169.254.1.1]/', false],
            // SSRF — cloud metadata link-local
            'reject 169.254.169.254' => ['http://169.254.169.254/latest/meta-data', false],
            'reject 169.254.0.1' => ['http://169.254.0.1/', false],
            // SSRF — RFC 1918 private ranges
            'reject 10.x'         => ['http://10.0.0.1/', false],
            'reject 172.16.x'     => ['http://172.16.0.1/', false],
            'reject 172.31.x'     => ['http://172.31.255.255/', false],
            'reject 192.168.x'    => ['http://192.168.1.1/', false],
            // SSRF — CGNAT 100.64.0.0/10 (missed by the old filter_var posture)
            'reject 100.64.0.1'   => ['http://100.64.0.1/', false],
            'reject 100.127.255.255' => ['http://100.127.255.255/', false],
            // SSRF — IPv6 ULA fc00::/7 and link-local fe80::/10, multicast
            'reject fc00::1'      => ['http://[fc00::1]/', false],
            'reject fd12:3456::1' => ['http://[fd12:3456::1]/', false],
            'reject fe80::1'      => ['http://[fe80::1]/', false],
            'reject ff02::1'      => ['http://[ff02::1]/', false],
            'reject zone-id [fe80::1%25eth0]' => ['http://[fe80::1%25eth0]/', false],
            // SSRF — IETF reserved, benchmarking, multicast, broadcast
            'reject 192.0.0.10'   => ['http://192.0.0.10/', false],
            'reject 198.18.5.5'   => ['http://198.18.5.5/', false],
            'reject 198.19.9.9'   => ['http://198.19.9.9/', false],
            'reject 224.0.0.5'    => ['http://224.0.0.5/', false],
            'reject 240.0.0.1'    => ['http://240.0.0.1/', false],
            'reject 255.255.255.255' => ['http://255.255.255.255/', false],
            // engine-012(a) PoC family — inet_aton shorthands for 127.0.0.1.
            // Refused as invalid literals; the transports must never be
            // reached with a host they would numeric-parse on their own.
            'reject 0x7f000001'   => ['http://0x7f000001/', false],
            'reject 0X7F000001 (case-folded)' => ['http://0X7F000001/', false],
            'reject 2130706433'   => ['http://2130706433/', false],
            'reject 127.1'        => ['http://127.1/', false],
            'reject 0177.0.0.1 (octal-prefixed)' => ['http://0177.0.0.1/', false],
            'reject 192.168.1 (partial dotted)' => ['http://192.168.1/', false],
            'reject 0x7f.0x1'     => ['http://0x7f.0x1/', false],
            // Bracket syntax violations
            'reject bracketed IPv4 [8.8.8.8]' => ['http://[8.8.8.8]/', false],
            'reject junk bracketed [not-ipv6]' => ['http://[not-ipv6]/', false],
            // Scheme rejections
            'reject file://'      => ['file:///etc/passwd', false],
            'reject gopher://'    => ['gopher://example.com/', false],
            'reject ftp://'       => ['ftp://example.com/foo', false],
            // Host rejections
            'reject empty'        => ['', false],
            'reject no host'      => ['http:///score', false],
            // Accepts — canonical PUBLIC literals, decided without DNS.
            // (TEST-NET documentation ranges are deliberately NOT in the
            // refused space: they are neither private nor routable to any
            // internal listener, and the old gate allowed them.)
            'accept 8.8.8.8'          => ['http://8.8.8.8/score', true],
            'accept TEST-NET 203.0.113.7' => ['http://203.0.113.7/score', true],
            'accept [2001:db8::1]'    => ['https://[2001:db8::1]/score', true],
            'accept [::ffff:8.8.8.8]' => ['http://[::ffff:8.8.8.8]/', true],
            // Range boundaries (one step outside each refused block)
            'accept 100.63.255.255 (below CGNAT)' => ['http://100.63.255.255/', true],
            'accept 100.128.0.1 (above CGNAT)'    => ['http://100.128.0.1/', true],
            'accept 172.15.255.255 (below RFC1918)' => ['http://172.15.255.255/', true],
            'accept 172.32.0.1 (above RFC1918)'   => ['http://172.32.0.1/', true],
            'accept 169.253.255.255 (below link-local)' => ['http://169.253.255.255/', true],
            'accept 223.255.255.255 (below multicast)'  => ['http://223.255.255.255/', true],
            'accept [fbff::1] (below ULA)'        => ['http://[fbff::1]/', true],
            'accept [fe7f::1] (below v6 link-local)' => ['http://[fe7f::1]/', true],
        ];
    }

    /** @dataProvider urlProvider */
    public function testIsAllowedUrl(string $url, bool $expected): void
    {
        self::assertSame($expected, MlClient::isAllowedUrl($url));
    }

    /**
     * The fail-closed flip: an unresolvable name is refused where the old
     * gate allowed it ("Non-IP hostnames that fail resolution are
     * allowed"). @group network because the row performs a resolver query
     * (NXDOMAIN for the RFC 6761 .invalid TLD in any reachable-DNS box;
     * zero records also refuse in an offline box, so the expectation
     * holds either way — the group marker only protects slow-resolver
     * environments from long waits).
     *
     * @group network
     */
    public function testUnresolvableNameIsRefused(): void
    {
        self::assertFalse(MlClient::isAllowedUrl('http://nonexistent-host-x.invalid/score'));
    }

    /**
     * Positive half of the resolve path: a real resolvable name with
     * public A records is accepted. @group network by nature (example.com
     * is IANA-run; DNS only, no HTTP request is made).
     *
     * @group network
     */
    public function testResolvablePublicNameIsAccepted(): void
    {
        self::assertTrue(MlClient::isAllowedUrl('https://example.com/score'));
    }

    /**
     * engine-012 PoC class, end to end: start a loopback listener and
     * hand the client the poisonous `0x7f000001`-style spellings of its
     * address. Before the rebuild the gate waved these through and curl
     * AND the stream transport both numeric-parsed them into a real
     * connection to the listener. Now the gate must refuse every form
     * before any transport exists — proven here by the listener never
     * accepting anything. Fully hermetic (in-process socketpair-scale
     * loopback only; no external network).
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

            $cluster = $this->makeCluster();
            foreach ([
                "http://0x7f000001:$port",
                "http://2130706433:$port",
                "http://127.1:$port",
                "http://0177.0.0.1:$port",
                "http://127.0.0.1:$port",
                "http://[::1]:$port",
                "http://[0:0:0:0:0:0:0:1]:$port",
                "http://[::ffff:127.0.0.1]:$port",
                "http://localhost:$port",
            ] as $poison) {
                $client = new MlClient($poison, timeoutSec: 1);
                self::assertNull(
                    $client->score($cluster),
                    "poisoned URL $poison must be refused by the gate",
                );
            }

            // Negative control: nothing connected. stream_select with a
            // bounded 50 ms window must report zero readable sockets.
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
     * isBlockedAddress directly via reflection — the inet_pton bit-check
     * classifier. No network; inputs are canonical forms or deliberate
     * garbage (unparsable → refused, fail-closed posture flip from the
     * old helper which returned false for anything unclassified).
     *
     * @return array<string,array{0:string,1:bool}>
     */
    public static function blockedAddressProvider(): array
    {
        return [
            // v4 refused blocks
            'block 127.0.0.1'         => ['127.0.0.1', true],
            'block 127.255.255.255'   => ['127.255.255.255', true],
            'block 10.9.9.9'          => ['10.9.9.9', true],
            'block 172.16.0.1'        => ['172.16.0.1', true],
            'block 172.31.255.255'    => ['172.31.255.255', true],
            'block 192.168.7.7'       => ['192.168.7.7', true],
            'block 169.254.169.254'   => ['169.254.169.254', true],
            'block 100.64.0.1'        => ['100.64.0.1', true],
            'block 100.127.255.255'   => ['100.127.255.255', true],
            'block 0.0.0.0'           => ['0.0.0.0', true],
            'block 0.1.2.3'           => ['0.1.2.3', true],
            'block 192.0.0.10'        => ['192.0.0.10', true],
            'block 198.18.0.1'        => ['198.18.0.1', true],
            'block 198.19.255.255'    => ['198.19.255.255', true],
            'block 224.0.0.5'         => ['224.0.0.5', true],
            'block 239.10.20.30'      => ['239.10.20.30', true],
            'block 240.0.0.1'         => ['240.0.0.1', true],
            'block 255.255.255.255'   => ['255.255.255.255', true],
            // v6 refused
            'block ::1'               => ['::1', true],
            'block 0:0:0:0:0:0:0:1'   => ['0:0:0:0:0:0:0:1', true],
            'block ::'                => ['::', true],
            'block ::2'               => ['::2', true],
            'block ::ffff:127.0.0.1'  => ['::ffff:127.0.0.1', true],
            'block ::ffff:169.254.1.1' => ['::ffff:169.254.1.1', true],
            'block fc00::1'           => ['fc00::1', true],
            'block fdff::'            => ['fdff::', true],
            'block fe80::1'           => ['fe80::1', true],
            'block febf::1'           => ['febf::1', true],
            'block ff02::1'           => ['ff02::1', true],
            // public → not blocked
            'allow 8.8.8.8'           => ['8.8.8.8', false],
            'allow 9.255.255.255'     => ['9.255.255.255', false],
            'allow 11.0.0.1'          => ['11.0.0.1', false],
            'allow 100.63.255.255'    => ['100.63.255.255', false],
            'allow 100.128.0.1'       => ['100.128.0.1', false],
            'allow 169.253.255.255'   => ['169.253.255.255', false],
            'allow 169.255.0.1'       => ['169.255.0.1', false],
            'allow 172.15.255.255'    => ['172.15.255.255', false],
            'allow 172.32.0.1'        => ['172.32.0.1', false],
            'allow 192.0.1.255'       => ['192.0.1.255', false],
            'allow 192.0.2.1 (TEST-NET)'   => ['192.0.2.1', false],
            'allow 192.169.0.1'       => ['192.169.0.1', false],
            'allow 198.17.255.255'    => ['198.17.255.255', false],
            'allow 198.20.0.1'        => ['198.20.0.1', false],
            'allow 198.51.100.10 (TEST-NET-2)' => ['198.51.100.10', false],
            'allow 203.0.113.7 (TEST-NET-3)'   => ['203.0.113.7', false],
            'allow 223.255.255.255'   => ['223.255.255.255', false],
            'allow 2001:db8::1'       => ['2001:db8::1', false],
            'allow ::ffff:8.8.8.8'    => ['::ffff:8.8.8.8', false],
            'allow fbff:ffff::1'      => ['fbff:ffff::1', false],
            'allow fe7f::1'           => ['fe7f::1', false],
            // unparsable → refused (fail-closed; old helper said "not blocked")
            'block garbage not.an.ip' => ['not.an.ip', true],
            'block empty string'      => ['', true],
            'block shorthand 0x7f000001' => ['0x7f000001', true],
            'block shorthand 127.1'   => ['127.1', true],
            'block overlong 1.2.3.4.5' => ['1.2.3.4.5', true],
        ];
    }

    /** @dataProvider blockedAddressProvider */
    public function testIsBlockedAddress(string $input, bool $expected): void
    {
        $r = new \ReflectionMethod(MlClient::class, 'isBlockedAddress');
        self::assertSame($expected, $r->invoke(null, $input));
    }

    /**
     * looksLikeNumericShorthand boundary: numeric forms yes, hex-ish REAL
     * names no ("db1" must stay resolvable on compose networks).
     *
     * @return array<string,array{0:string,1:bool}>
     */
    public static function shorthandProvider(): array
    {
        return [
            'hex 0x7f000001'    => ['0x7f000001', true],
            'decimal 2130706433' => ['2130706433', true],
            'partial 127.1'     => ['127.1', true],
            'partial 192.168.1' => ['192.168.1', true],
            'octal-ish 0177.0.0.1' => ['0177.0.0.1', true],
            'mixed 127.0x1'     => ['127.0x1', true],
            'name db1'          => ['db1', false],
            'name ml3.example.com' => ['ml3.example.com', false],
            'name cafe'         => ['cafe', false],
            'name example.com'  => ['example.com', false],
            'name 0x7f000001.attacker.example' => ['0x7f000001.attacker.example', false],
        ];
    }

    /** @dataProvider shorthandProvider */
    public function testLooksLikeNumericShorthand(string $host, bool $expected): void
    {
        $r = new \ReflectionMethod(MlClient::class, 'looksLikeNumericShorthand');
        self::assertSame($expected, $r->invoke(null, $host));
    }

    public function testEmptyBaseUrlReturnsNull(): void
    {
        $client = new MlClient('');
        $cluster = $this->makeCluster();
        self::assertNull($client->score($cluster));
    }

    public function testInvalidBaseUrlReturnsNull(): void
    {
        $client = new MlClient('file:///etc/passwd');
        $cluster = $this->makeCluster();
        self::assertNull($client->score($cluster));
    }

    private function makeCluster(): \Phpdup\Clustering\Cluster
    {
        // Construct a minimal Cluster instance via reflection rather
        // than the full constructor to avoid coupling this test to
        // unrelated Cluster invariants.
        $r = new \ReflectionClass(\Phpdup\Clustering\Cluster::class);
        /** @var \Phpdup\Clustering\Cluster $cluster */
        $cluster = $r->newInstanceWithoutConstructor();
        foreach (
            [
                'id'           => 'Xtest',
                'similarity'   => 0.9,
                'members'      => [],
                'holes'        => [],
                'patternTags'  => [],
            ] as $prop => $value
        ) {
            if ($r->hasProperty($prop)) {
                $p = $r->getProperty($prop);
                $p->setValue($cluster, $value);
            }
        }
        return $cluster;
    }

    /**
     * @dataProvider httpCodeProvider
     * @param list<string> $headers
     */
    public function testParseStatusCode(array $headers, int $expectedCode): void
    {
        $client = new MlClient('http://example.com');
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
            'no HTTP prefix'    => [['Content-Type: application/json'], 0],
            'HTTP/2 200'       => [['HTTP/2 200 OK'], 200],
        ];
    }

    /**
     * @group network
     */
    public function testNon2xxResponseReturnsNull(): void
    {
        // httpbin.org/status/500 returns a 500 response
        $client = new MlClient('https://httpbin.org/status/500', timeoutSec: 10);
        $cluster = $this->makeCluster();
        self::assertNull($client->score($cluster));
    }

    /**
     * @group network
     */
    public function test2xxResponseReturnsArray(): void
    {
        // httpbin.org/status/200 returns a 200 response with body "OK"
        // but our score() method expects JSON with safety/anomaly fields
        // so it will return null due to malformed JSON — that's fine.
        // The point is that it doesn't throw and it doesn't return
        // garbage similarity scores from error pages.
        $client = new MlClient('https://httpbin.org/status/200', timeoutSec: 10);
        $cluster = $this->makeCluster();
        $result = $client->score($cluster);
        // We expect null because the response body is not valid JSON with safety/anomaly
        // but the important thing is it didn't return a garbage score from a 200 OK error page
        self::assertNull($result);
    }
}
