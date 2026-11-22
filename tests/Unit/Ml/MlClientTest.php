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

    // =====================================================================
    // engine-S19b — transport pinning (php-dup-finder-engine-012 hole c)
    // and response size caps (php-dup-finder-engine-015).
    //
    // Seam tests drive the private transports directly with hand-built
    // gate decisions: the gate itself refuses loopback (engine-S19a), so
    // a positive pinned dial against a local listener cannot go through
    // score() without touching real DNS. ml.invalid (RFC 6761) never
    // resolves — a successful response from the loopback listener is
    // reachable ONLY through the pin.
    // =====================================================================

    public function testGateUrlDecisionsMirrorAllowedUrlMatrix(): void
    {
        $isBlocked = new \ReflectionMethod(MlClient::class, 'isBlockedAddress');
        foreach (self::urlProvider() as [$url, $expected]) {
            $decision = MlClient::gateUrl($url);
            self::assertSame($expected, $decision !== null, "gateUrl decision: $url");
            if ($decision === null) {
                continue;
            }
            // Everything the gate hands a transport must itself be
            // dial-safe: non-empty, canonical-parsable, cleared space.
            self::assertNotSame([], $decision['addresses'], "pinned addresses: $url");
            self::assertIsInt($decision['port']);
            self::assertGreaterThan(0, $decision['port']);
            foreach ($decision['addresses'] as $address) {
                self::assertNotFalse(inet_pton($address), "pinnable address text: $address");
                self::assertFalse($isBlocked->invoke(null, $address), "cleared address: $address");
            }
        }
    }

    public function testGateUrlCarriesHostPortAndLiteralAddress(): void
    {
        $decision = MlClient::gateUrl('http://8.8.8.8/score');
        self::assertNotNull($decision);
        self::assertSame('8.8.8.8', $decision['host']);
        self::assertSame(80, $decision['port']);
        self::assertSame(['8.8.8.8'], $decision['addresses']);

        $decision = MlClient::gateUrl('https://[2001:DB8::1]/score');
        self::assertNotNull($decision);
        self::assertSame('2001:db8::1', $decision['host']);
        self::assertSame(443, $decision['port']);
        self::assertSame(['2001:db8::1'], $decision['addresses']);

        $decision = MlClient::gateUrl('http://203.0.113.7:8123/score');
        self::assertNotNull($decision);
        self::assertSame(8123, $decision['port']);
    }

    public function testCurlPinEntriesMatrix(): void
    {
        self::assertSame(
            ['ml.example.test:8123:203.0.113.7,2001:db8::1'],
            MlClient::curlPinEntries([
                'host'      => 'ml.example.test',
                'port'      => 8123,
                'addresses' => ['203.0.113.7', '2001:db8::1'],
            ]),
        );
        // Canonical literals are dialed directly — nothing to pin.
        self::assertSame(
            [],
            MlClient::curlPinEntries(['host' => '203.0.113.7', 'port' => 80, 'addresses' => ['203.0.113.7']]),
        );
        // Fail-closed refusals for shapes the gate never emits.
        self::assertNull(MlClient::curlPinEntries(['host' => 'ml.example.test', 'port' => 80, 'addresses' => []]));
        self::assertNull(MlClient::curlPinEntries(['host' => "evil\r\nx", 'port' => 80, 'addresses' => ['203.0.113.7']]));
        self::assertNull(MlClient::curlPinEntries(['host' => '', 'port' => 80, 'addresses' => ['203.0.113.7']]));
    }

    public function testPinnedStreamRequestMatrix(): void
    {
        // Named http host: dial the first checked address, keep authority.
        $pin = MlClient::pinnedStreamRequest('http://ml.example.test:8123/score?x=1&y=2', [
            'host' => 'ml.example.test', 'port' => 8123, 'addresses' => ['203.0.113.7', '203.0.113.8'],
        ]);
        self::assertNotNull($pin);
        self::assertSame('http://203.0.113.7:8123/score?x=1&y=2', $pin['url']);
        self::assertSame("Content-Type: application/json\r\nHost: ml.example.test:8123\r\n", $pin['header']);
        self::assertNull($pin['peer_name']);

        // Named https host: pinned dial keeps certificate identity; v6 target brackets.
        $pin = MlClient::pinnedStreamRequest('https://ml.example.test/score', [
            'host' => 'ml.example.test', 'port' => 443, 'addresses' => ['2001:db8::1'],
        ]);
        self::assertNotNull($pin);
        self::assertSame('https://[2001:db8::1]:443/score', $pin['url']);
        self::assertSame('ml.example.test', $pin['peer_name']);

        // Path-less named URL defaults to '/'.
        $pin = MlClient::pinnedStreamRequest('http://ml.example.test', [
            'host' => 'ml.example.test', 'port' => 80, 'addresses' => ['203.0.113.7'],
        ]);
        self::assertNotNull($pin);
        self::assertSame('http://203.0.113.7:80/', $pin['url']);

        // Literal hosts pass through byte-identically (pre-S19b header).
        $pin = MlClient::pinnedStreamRequest('http://203.0.113.7:9000/score', [
            'host' => '203.0.113.7', 'port' => 9000, 'addresses' => ['203.0.113.7'],
        ]);
        self::assertNotNull($pin);
        self::assertSame('http://203.0.113.7:9000/score', $pin['url']);
        self::assertSame("Content-Type: application/json\r\n", $pin['header']);
        self::assertNull($pin['peer_name']);

        // Refusals: empty candidate list / header-unsafe host text.
        self::assertNull(MlClient::pinnedStreamRequest('http://ml.example.test/score', [
            'host' => 'ml.example.test', 'port' => 80, 'addresses' => [],
        ]));
        self::assertNull(MlClient::pinnedStreamRequest('http://ml.example.test/score', [
            'host' => "evil\r\nx", 'port' => 80, 'addresses' => ['203.0.113.7'],
        ]));
    }

    public function testPinnedCurlTransportConnectsCheckedIpWithoutResolver(): void
    {
        [$pid, $port, $server] = $this->forkHttpServer(
            static fn(string $request): string => self::echoResponseFor($request)
        );
        try {
            $client   = new MlClient('http://ml.invalid', timeoutSec: 3);
            $decision = ['host' => 'ml.invalid', 'port' => $port, 'addresses' => ['127.0.0.1']];
            $resp     = $this->invokeCurlSeam($client, "http://ml.invalid:$port/score", '{}', $decision);
            self::assertIsString($resp, 'pinned curl dial of the checked address failed');
            self::assertStringContainsString('POST /score', $resp);
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
            $client   = new MlClient('http://ml.invalid', timeoutSec: 3);
            $decision = ['host' => 'ml.invalid', 'port' => $port, 'addresses' => ['127.0.0.1']];
            $method   = new \ReflectionMethod($client, 'postJsonStream');
            $resp     = $method->invoke($client, "http://ml.invalid:$port/score", '{}', $decision);
            self::assertIsString($resp, 'pinned stream rewrite to the checked address failed');
            self::assertStringContainsString('POST /score', $resp);
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
            $client   = new MlClient('http://127.0.0.1', timeoutSec: 5);
            $decision = ['host' => '127.0.0.1', 'port' => $port, 'addresses' => ['127.0.0.1']];
            $mark     = memory_get_usage();
            $resp     = $this->invokeCurlSeam($client, "http://127.0.0.1:$port/score", '{}', $decision);
            $delta    = memory_get_usage() - $mark;
            self::assertNull($resp, 'over-cap body must be refused');
            // Pre-S19b this branch returned the whole 4 MB through
            // RETURNTRANSFER; the write-callback cap never buffers past
            // MAX_RESPONSE_BYTES.
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
            $client   = new MlClient('http://127.0.0.1', timeoutSec: 5);
            $decision = ['host' => '127.0.0.1', 'port' => $port, 'addresses' => ['127.0.0.1']];
            $mark     = memory_get_usage();
            $method   = new \ReflectionMethod($client, 'postJsonStream');
            $resp     = $method->invoke($client, "http://127.0.0.1:$port/score", '{}', $decision);
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
            $client   = new MlClient('http://127.0.0.1', timeoutSec: 2);
            $decision = ['host' => '127.0.0.1', 'port' => $port, 'addresses' => []];
            self::assertNull($this->invokeCurlSeam($client, "http://127.0.0.1:$port/score", '{}', $decision));
            $stream = new \ReflectionMethod($client, 'postJsonStream');
            self::assertNull($stream->invoke($client, "http://127.0.0.1:$port/score", '{}', $decision));
            // Fail-closed means no dial was even attempted: the live
            // listener below would have answered both seams eagerly.
            $this->assertNoConnectionArrived($server);
        } finally {
            fclose($server);
            $this->reapForkedHttpServer($pid);
        }
    }

    public function testSeamsRefuseNon2xxResponses(): void
    {
        $response = "HTTP/1.1 503 Service Unavailable\r\nContent-Length: 6\r\n\r\nnope!!";
        [$pid, $port, $server] = $this->forkHttpServer(
            static fn(string $request): string => $response,
            5,
            2
        );
        try {
            $client   = new MlClient('http://127.0.0.1', timeoutSec: 3);
            $decision = ['host' => '127.0.0.1', 'port' => $port, 'addresses' => ['127.0.0.1']];
            self::assertNull($this->invokeCurlSeam($client, "http://127.0.0.1:$port/score", '{}', $decision));
            $stream = new \ReflectionMethod($client, 'postJsonStream');
            self::assertNull($stream->invoke($client, "http://127.0.0.1:$port/score", '{}', $decision));
        } finally {
            fclose($server);
            $this->reapForkedHttpServer($pid);
        }
    }

    public function testLiteralHostSeamsStillRoundTrip(): void
    {
        $body     = '{"safety":0.5,"anomaly":0.25}';
        $response = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
        [$pid, $port, $server] = $this->forkHttpServer(
            static fn(string $request): string => $response,
            5,
            2
        );
        try {
            $client   = new MlClient('http://127.0.0.1', timeoutSec: 3);
            $decision = ['host' => '127.0.0.1', 'port' => $port, 'addresses' => ['127.0.0.1']];
            self::assertSame($body, $this->invokeCurlSeam($client, "http://127.0.0.1:$port/score", '{}', $decision));
            $stream = new \ReflectionMethod($client, 'postJsonStream');
            self::assertSame($body, $stream->invoke($client, "http://127.0.0.1:$port/score", '{}', $decision));
        } finally {
            fclose($server);
            $this->reapForkedHttpServer($pid);
        }
    }

    /**
     * @param array{host: string, port: int, addresses: list<string>} $decision
     */
    private function invokeCurlSeam(MlClient $client, string $url, string $body, array $decision): ?string
    {
        $method = new \ReflectionMethod($client, 'postJsonCurl');
        return $method->invoke($client, $url, $body, $decision);
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
     * One-shot loopback HTTP server in a forked child. $responseFor gets
     * the raw request text and returns the full response to write once.
     * $serveCount lets a test dial the same seam repeatedly (a literal
     * round-trip through curl AND stream needs two accepted connections).
     * The parent KEEPS the listener handle so tests can assert "no
     * connection arrived" with stream_select; callers must fclose it and
     * reap the child when done.
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
            // Child: serve up to $serveCount requests, then exit() past
            // PHPUnit shutdown — drop inherited output buffers so
            // nothing flushes twice.
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
     * Bounded reap: the child finishes by serving or by accept-timeout,
     * both well inside the deadline. Anything else is a hung transport,
     * not a hung machine — kill and fail loudly.
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
     * Negative control (same shape as engine-S19a's PoC pins): nothing
     * is in the listener's accept queue, so no dial was attempted.
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

    // =====================================================================
    // engine-S20 — shared remote-number domain fence + /score wiring
    // (php-dup-finder-engine-013). The fence lives on MlClient so both
    // clients parse through ONE function (MlPairClient's own verdict
    // units live in MlPairClientTest); these are its direct matrix plus
    // the safety/anomaly wiring in this class's parse seam.
    // =====================================================================

    /** @return array<string,array{0:mixed,1:float|null}> */
    public static function coerceUnitFloatProvider(): array
    {
        return [
            // In domain — parse to trusted floats.
            'exact 0.0'            => [0.0, 0.0],
            'exact 1.0'            => [1.0, 1.0],
            'int 0'                => [0, 0.0],
            'int 1'                => [1, 1.0],
            'mid float'            => [0.42, 0.42],
            'numeric string'       => ['0.9', 0.9],
            'exponent string'      => ['1e-1', 0.1],
            'int-ish string'       => ['1', 1.0],
            // Non-finite — the FUZZ-03 class. json_decode('1e999')
            // yields exactly this float, and is_numeric() accepts
            // INF/NAN floats, so only the finite check refuses them.
            'INF from 1e999'       => [(float)'1e999', null],
            'negative INF'         => [-INF, null],
            'NAN'                  => [NAN, null],
            // Out of domain.
            'above 1.0'            => [1.5, null],
            'below 0.0'            => [-0.0001, null],
            'negative INF value'   => [-1e999, null],
            // Non-numeric types the old cast laundered.
            'bool true'            => [true, null],
            'bool false'           => [false, null],
            'null'                 => [null, null],
            'garbage string'       => ['garbage', null],
            'NaN string'           => ['NaN', null],
            'hex string'           => ['0x0F', null],
            'list verdict'         => [[0.9], null],
            'assoc verdict'        => [['similarity' => 0.9], null],
            'empty array'          => [[], null],
        ];
    }

    /** @dataProvider coerceUnitFloatProvider */
    public function testCoerceUnitFloatParsesDomainRefusesRest(mixed $value, ?float $expected): void
    {
        self::assertSame($expected, MlClient::coerceUnitFloat($value));
    }

    /**
     * /score bodies whose safety/anomaly verdict must refuse instead of
     * laundering through the old bare (float) casts.
     *
     * @return array<string,array{0:string}>
     */
    public static function refusedSafetyBodyProvider(): array
    {
        return [
            'safety INF via 1e999'  => ['{"safety":1e999,"anomaly":0.1}'],
            'safety array'          => ['{"safety":[0.71],"anomaly":0.1}'],
            'safety bool'           => ['{"safety":true,"anomaly":0.1}'],
            'safety garbage string' => ['{"safety":"garbage","anomaly":0.1}'],
            'safety above 1'        => ['{"safety":1.2,"anomaly":0.1}'],
            'anomaly INF'           => ['{"safety":0.71,"anomaly":1e999}'],
            'anomaly NaN string'    => ['{"safety":0.71,"anomaly":"NaN"}'],
            'anomaly negative'      => ['{"safety":0.71,"anomaly":-0.5}'],
            'anomaly list'          => ['{"safety":0.71,"anomaly":[0.12]}'],
            'safety missing'        => ['{"anomaly":0.1}'],
            'anomaly missing'       => ['{"safety":0.71}'],
            'scalar body'           => ['0.71'],
            'not json'              => ['nope'],
        ];
    }

    /** @dataProvider refusedSafetyBodyProvider */
    public function testMalformedSafetyVerdictRefusedNotLaundered(string $body): void
    {
        $client = new MlClient('http://ml.invalid');
        $parse  = new \ReflectionMethod($client, 'parseSafetyVerdict');
        self::assertNull($parse->invoke($client, $body), "safety verdict must refuse: $body");
    }

    public function testInDomainSafetyVerdictsParseIntoTrustedFloats(): void
    {
        $client = new MlClient('http://ml.invalid');
        $parse  = new \ReflectionMethod($client, 'parseSafetyVerdict');
        self::assertSame(
            ['safety' => 0.71, 'anomaly' => 0.12],
            $parse->invoke($client, '{"safety":0.71,"anomaly":0.12}'),
        );
        self::assertSame(
            ['safety' => 0.0, 'anomaly' => 1.0],
            $parse->invoke($client, '{"safety":0,"anomaly":1}'),
            'closed interval: exact 0 and 1 are legal verdicts',
        );
        self::assertSame(
            ['safety' => 0.71, 'anomaly' => 0.5],
            $parse->invoke($client, '{"safety":"0.71","anomaly":"0.5"}'),
            'numeric strings parse per the is_numeric domain test',
        );
    }
}
