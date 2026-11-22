<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Ml;

use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\Block;
use Phpdup\Ml\MlClient;
use Phpdup\Ml\MlPairClient;
use Phpdup\Ml\PairFeatures;
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

    // =====================================================================
    // engine-S20 — remote numeric laundering + scoreBatch alignment
    // (php-dup-finder-engine-013 / php-dup-finder-engine-014).
    //
    // The decode seams (private parseVerdict / parseBatchVerdicts, the
    // exact functions score()/scoreBatch() consume responses through)
    // are driven directly with bodies a hostile or buggy sidecar can
    // send. Seam-level rather than end-to-end for the same structural
    // reason as the S19b transport seams above: the gate refuses every
    // hermetic dial target, so only the seam sees a response body.
    // FUZZ-03's live observation — an INF-scored pair emitted as a
    // cluster while the report layer's min(1.0, …) masked the number —
    // is pinned here as a permanent refusal unit.
    // =====================================================================

    /**
     * /score-pair bodies whose verdict must REFUSE (null) instead of
     * laundering through the old bare (float) casts.
     *
     * @return array<string,array{0:string}>
     */
    public static function refusedPairVerdictProvider(): array
    {
        return [
            'similarity INF via 1e999'  => ['{"similarity":1e999,"confidence":0.5}'],
            'similarity -INF'           => ['{"similarity":-1e999,"confidence":0.5}'],
            'similarity NaN string'     => ['{"similarity":"NaN","confidence":0.5}'],
            'similarity garbage string' => ['{"similarity":"garbage","confidence":0.5}'],
            'similarity array'          => ['{"similarity":[0.9],"confidence":0.5}'],
            'similarity bool true'      => ['{"similarity":true,"confidence":0.5}'],
            'similarity above 1'        => ['{"similarity":1.5,"confidence":0.5}'],
            'similarity below 0'        => ['{"similarity":-0.0001,"confidence":0.5}'],
            'confidence INF'            => ['{"similarity":0.9,"confidence":1e999}'],
            'confidence array'          => ['{"similarity":0.9,"confidence":["x"]}'],
            'confidence NaN string'     => ['{"similarity":0.9,"confidence":"NaN"}'],
            'confidence missing'        => ['{"similarity":0.9}'],
            'similarity missing'        => ['{"confidence":0.5}'],
            'similarity null'           => ['{"similarity":null,"confidence":0.5}'],
            'scalar body'               => ['0.9'],
            'list body'                 => ['[0.9,0.5]'],
            'not json'                  => ['<html>500</html>'],
        ];
    }

    /** @dataProvider refusedPairVerdictProvider */
    public function testMalformedPairVerdictRefusedNotLaundered(string $body): void
    {
        $client = new MlPairClient('http://ml.invalid');
        $parse  = new \ReflectionMethod($client, 'parseVerdict');
        self::assertNull($parse->invoke($client, $body), "verdict must refuse: $body");
    }

    public function testInDomainPairVerdictsParseIntoTrustedFloats(): void
    {
        $client = new MlPairClient('http://ml.invalid');
        $parse  = new \ReflectionMethod($client, 'parseVerdict');
        self::assertSame(
            ['similarity' => 1.0, 'confidence' => 0.0],
            $parse->invoke($client, '{"similarity":1,"confidence":0}'),
            'closed interval: exact 0 and 1 are legal verdicts',
        );
        self::assertSame(
            ['similarity' => 0.9, 'confidence' => 0.78],
            $parse->invoke($client, '{"similarity":"0.9","confidence":0.78}'),
            'numeric strings parse per the is_numeric domain test (engine-013 fix shape)',
        );
    }

    /**
     * engine-014: every misaligned batch shape voids the WHOLE response
     * (null → the consumer's per-pair retry path), never a shorter,
     * padded or reordered list the positional consumer could misread.
     */
    public function testBatchMisalignmentVoidsResponseForPerPairRetry(): void
    {
        $client = new MlPairClient('http://ml.invalid');
        $parse  = new \ReflectionMethod($client, 'parseBatchVerdicts');
        // Shortened: 2 well-formed entries for 3 requested pairs.
        self::assertNull(
            $parse->invoke($client, '{"results":[[0.9,0.5],[0.8,0.4]]}', 3),
            'short results must void the batch, not silently pad the tail',
        );
        // Extra: 3 entries for 2 requested pairs.
        self::assertNull(
            $parse->invoke($client, '{"results":[[0.9,0.5],[0.8,0.4],[0.7,0.3]]}', 2),
            'extra results must void the batch',
        );
        // Gapped/reordered keys that decode to an associative array:
        // count matches but the list shape is contractual.
        self::assertNull(
            $parse->invoke($client, '{"results":{"0":[0.9,0.5],"2":[0.7,0.3]}}', 2),
            'non-sequential result keys must void the batch',
        );
        // Results absent / wrong type.
        self::assertNull($parse->invoke($client, '{}', 2));
        self::assertNull($parse->invoke($client, '{"results":3}', 2));
        self::assertNull($parse->invoke($client, 'nope', 2));
    }

    public function testAlignedBatchDecodesEveryShapeWithDomainChecks(): void
    {
        $client = new MlPairClient('http://ml.invalid');
        $parse  = new \ReflectionMethod($client, 'parseBatchVerdicts');
        $body   = '{"results":['
            . '[0.9,0.5],'                               // positional pair — kept
            . '{"similarity":0.8,"confidence":0.4},'     // named pair — kept
            . '[1e999,0.5],'                             // INF laundering — slot nulls
            . '[0.8,"garbage"],'                         // garbage confidence — slot nulls
            . '[0.7],'                                   // fewer than 2 fields — slot nulls
            . 'null'                                     // non-array entry — slot nulls
            . ']}';
        $verdicts = $parse->invoke($client, $body, 6);
        self::assertIsArray($verdicts, 'aligned response must decode, not void');
        self::assertCount(6, $verdicts, 'list stays positionally aligned');
        self::assertSame(['similarity' => 0.9, 'confidence' => 0.5], $verdicts[0]);
        self::assertSame(['similarity' => 0.8, 'confidence' => 0.4], $verdicts[1]);
        self::assertNull($verdicts[2], 'INF must refuse, never launder to an edge-minting score');
        self::assertNull($verdicts[3], 'non-numeric confidence must refuse');
        self::assertNull($verdicts[4]);
        self::assertNull($verdicts[5]);
    }

    public function testBatchNullSlotKeepsValidNeighbours(): void
    {
        // The count fence protects ALIGNMENT only — one malformed
        // entry must not discard the valid verdicts around it. A null
        // slot is NOT a retry trigger: PairScoreWorker consumes
        // $batchResults[$idx] ?? null inside an aligned batch, so a
        // null slot simply means that pair receives no ML-tier edge;
        // the per-pair score() fallback fires only when the WHOLE
        // response voids.
        $client = new MlPairClient('http://ml.invalid');
        $parse  = new \ReflectionMethod($client, 'parseBatchVerdicts');
        $verdicts = $parse->invoke($client, '{"results":[[true,0.5],[0.6,0.2]]}', 2);
        self::assertIsArray($verdicts);
        self::assertNull($verdicts[0], 'bool similarity refuses');
        self::assertSame(['similarity' => 0.6, 'confidence' => 0.2], $verdicts[1]);
    }

    // =====================================================================
    // once-S48 — wire-shape docblock truth (php-dup-finder-once-052).
    //
    // PairFeatures::WIRE_KEYS is now the single source of truth for the
    // combined vector BOTH endpoints send. These pins assert the real
    // extractor output and the real /score-batch body against that
    // const, so the docblocks cannot contradict the wire again; the
    // engine-S20 refusal units above stay byte-untouched.
    // =====================================================================

    public function testCombinedVectorIsExactlyTheWireKeysConst(): void
    {
        $vector = (new PairFeatures())->extract($this->makeBlock(), $this->makeBlock());

        self::assertCount(
            11,
            PairFeatures::WIRE_KEYS,
            'the combined pair wire is the exact 11-field vector the wire-contract docblock states',
        );
        // Fields-count equality: the vector is the envelope marker plus
        // WIRE_KEYS — nothing more, nothing less.
        self::assertCount(count(PairFeatures::WIRE_KEYS) + 1, $vector);
        self::assertSame(
            array_merge([PairFeatures::FEATURE_VERSION_KEY], PairFeatures::WIRE_KEYS),
            array_keys($vector),
            'extract() must emit feature_version + PairFeatures::WIRE_KEYS in exactly that order',
        );
        self::assertSame(
            PairFeatures::FEATURE_VERSION,
            $vector[PairFeatures::FEATURE_VERSION_KEY],
            'the envelope marker carries the current feature shape version',
        );
    }

    public function testBatchRequestCarriesOneCombinedVectorPerPair(): void
    {
        // The /score-batch body is the shape the docblock now states:
        // {"pairs": [ <combined vector>, … ]} in request order — NOT the
        // nested [features_a, features_b] tuples the old prose promised.
        $a = $this->makeBlock();
        $b = $this->makeBlock();
        $c = $this->makeBlock();

        $client = new MlPairClient('http://ml.invalid');
        $encode = new \ReflectionMethod($client, 'encodeBatchBody');
        $body   = $encode->invoke($client, [[$a, $b], [$b, $c]]);
        self::assertIsString($body);

        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);
        self::assertSame(['pairs'], array_keys($decoded), 'batch envelope is exactly the pairs list');
        $pairs = $decoded['pairs'];
        self::assertIsArray($pairs);
        self::assertTrue(array_is_list($pairs), 'pairs must be a sequential list');
        self::assertCount(2, $pairs, 'one entry per requested pair, in request order');

        $expected = array_merge([PairFeatures::FEATURE_VERSION_KEY], PairFeatures::WIRE_KEYS);
        foreach ($pairs as $i => $entry) {
            self::assertIsArray($entry);
            self::assertSame($expected, array_keys($entry), "pairs[$i] must be the combined 11-field vector");
        }
    }

    public function testShortOrListJunkEntriesDegradeToNullSlotsWithoutMintingVerdicts(): void
    {
        // Acceptance leg "short/list responses degrade without creating
        // edges": inside an ALIGNED response every unusable entry
        // shape — scalar, list, empty, short, out-of-domain number —
        // decodes to a null slot, never a defaulted verdict (the
        // consumer mints an ML edge only from a non-null verdict, so a
        // null slot means no edge) and never a voided batch.
        $client = new MlPairClient('http://ml.invalid');
        $parse  = new \ReflectionMethod($client, 'parseBatchVerdicts');
        $body   = '{"results":['
            . '"0.9",'                                        // scalar entry
            . '3,'                                            // int entry
            . '[],'                                           // empty list
            . '[0.7],'                                        // short list: 1 of 2 fields
            . '{"similarity":"garbage","confidence":0.4}'      // out-of-domain number
            . ']}';
        $verdicts = $parse->invoke($client, $body, 5);

        self::assertIsArray($verdicts, 'an aligned response decodes even when every entry is unusable');
        self::assertCount(5, $verdicts, 'alignment (and thus positions) survives entry-level refusals');
        foreach ($verdicts as $i => $verdict) {
            self::assertNull($verdict, "unusable entry $i must degrade to a null slot, not a verdict");
        }
    }

    // =====================================================================
    // engine-S21 — per-instance economics (php-dup-finder-engine-032).
    //
    // The serial pair path must stop paying a resolver walk and a fresh
    // curl handle/TCP(+TLS) handshake per pair: ONE memoized gate
    // decision per client (counting seam on $urlGateCalls) and ONE
    // keep-alive handle per client (object-identity seam on
    // $curlHandle), fork-guarded via $curlHandlePid. The public API and
    // the engine-S20 null-slot semantics pinned above stay byte-
    // untouched — these are the "counting seam" acceptance legs.
    // =====================================================================

    public function testUrlDecisionGatedOncePerClientInstanceIncludingRefusals(): void
    {
        $a = $this->makeBlock();
        $b = $this->makeBlock();
        // 127.0.0.1 is refused by the gate itself (engine-S19a) — the
        // whole test stays hermetic: nothing ever dials.
        $client = new MlPairClient('http://127.0.0.1:1', timeoutSec: 1);
        self::assertSame(0, self::gateCalls($client), 'construction performs no gate work');

        self::assertNull($client->score($a, $b));
        // engine-S21 r1 MEDIUM-1: read the seam after a score()-only
        // prefix so the SERIAL path itself must mint the count here.
        // The final assertion alone could be satisfied by a drift where
        // score() re-gates inline (the F-6 duplication class this step
        // exists to prevent) while scoreBatch's memo still lands the
        // total at 1 — the serial path would silently pay its own
        // resolver walk per pair, undetected.
        self::assertSame(1, self::gateCalls($client), 'the serial /score-pair path itself rides the counting seam');

        self::assertNull($client->scoreBatch([[$a, $b]]));
        self::assertNull($client->score($a, $b));
        self::assertNull($client->scoreBatch([[$a, $b], [$b, $a]]));

        self::assertSame(
            1,
            self::gateCalls($client),
            'with the serial decision minted (assertion above), every /score-batch leg rides that same memo — still ONE gate call across both endpoints, refusals cached too (engine-032)',
        );
    }

    public function testDisabledOrEmptyCallsNeverConsumeTheGate(): void
    {
        $client = new MlPairClient('http://127.0.0.1:1', timeoutSec: 1);
        self::assertNull($client->scoreBatch([]));
        self::assertSame(0, self::gateCalls($client), 'an empty batch returns before the gate');

        $empty = new MlPairClient('', timeoutSec: 1);
        self::assertNull($empty->score($this->makeBlock(), $this->makeBlock()));
        self::assertNull($empty->scoreBatch([[$this->makeBlock(), $this->makeBlock()]]));
        self::assertSame(0, self::gateCalls($empty), 'a disabled client resolves no decision at all');
    }

    public function testAllowedAuthorityGatesOnceAcrossEndpointsAndRetries(): void
    {
        // 203.0.113.10 is TEST-NET-3: public space the gate ACCEPTS
        // (no DNS — canonical literal), while the dial to port :1 fails
        // within timeoutSec. The assertion is about the gate count, and
        // each of the three bounded connects costs at most 1 s.
        $a = $this->makeBlock();
        $b = $this->makeBlock();
        $client = new MlPairClient('http://203.0.113.10:1', timeoutSec: 1);
        self::assertNull($client->score($a, $b));
        // engine-S21 r1 MEDIUM-1: same score()-only-prefix read as the
        // refusal pin — the serial path alone must mint the seam count
        // before the batch leg rides it.
        self::assertSame(1, self::gateCalls($client), 'the serial /score-pair path itself rides the counting seam');
        self::assertNull($client->scoreBatch([[$a, $b]]));
        self::assertNull($client->score($a, $b));
        self::assertSame(
            1,
            self::gateCalls($client),
            'positive-side memo: the batch leg and the retry ride the serial decision minted above — no extra gate calls, retries included',
        );
    }

    public function testCurlHandleReusedAcrossCallsOneInitNoLeak(): void
    {
        [$pid, $port, $server] = $this->forkHttpServer(
            static fn(string $request): string => self::echoResponseFor($request),
            5,
            2
        );
        try {
            $client   = new MlPairClient('http://ml.invalid', timeoutSec: 3);
            $decision = ['host' => 'ml.invalid', 'port' => $port, 'addresses' => ['127.0.0.1']];
            $method   = new \ReflectionMethod($client, 'postJsonCurl');
            $first    = $method->invoke($client, "http://ml.invalid:$port/score-pair", '{}', $decision);
            $handleA  = self::curlHandle($client);
            $second   = $method->invoke($client, "http://ml.invalid:$port/score-batch", '{}', $decision);
            $handleB  = self::curlHandle($client);

            // The mirror-seam posture from engine-S19b stays: this
            // drives the same private transport score()/scoreBatch()
            // use, with a forged gate decision (the loopback listener
            // the pin dials is refused by the real gate by design).
            self::assertIsString($first);
            self::assertIsString($second);
            self::assertStringContainsString('POST /score-pair', $first);
            self::assertStringContainsString('POST /score-batch', $second);
            self::assertSame(
                spl_object_id($handleA),
                spl_object_id($handleB),
                'multiple scores ride ONE handle — no per-call curl_init accumulation (engine-032)',
            );
        } finally {
            fclose($server);
            $this->reapForkedHttpServer($pid);
        }
    }

    public function testKeepAliveHandleFoldsPerPairHandshakes(): void
    {
        // The crux of engine-032: while the sidecar holds the
        // connection open, the SECOND transfer on the same handle must
        // create ZERO new connections (CURLINFO_NUM_CONNECTS) — the
        // fresh TCP handshake-per-pair is gone, and with it the TLS one
        // on https sidecars (same handle-cache mechanism).
        [$pid, $port, $server] = $this->forkKeepAliveServer(2);
        try {
            $client   = new MlPairClient('http://ml.invalid', timeoutSec: 3);
            $decision = ['host' => 'ml.invalid', 'port' => $port, 'addresses' => ['127.0.0.1']];
            $method   = new \ReflectionMethod($client, 'postJsonCurl');

            $first = $method->invoke($client, "http://ml.invalid:$port/score-pair", '{}', $decision);
            self::assertIsString($first);
            self::assertStringContainsString('req=1', $first);

            $second = $method->invoke($client, "http://ml.invalid:$port/score-batch", '{}', $decision);
            self::assertIsString($second, 'second dial on the reused handle failed');
            self::assertStringContainsString('req=2', $second, 'sidecar must see request 2 on the SAME connection');

            self::assertSame(
                0,
                (int) curl_getinfo(self::curlHandle($client), CURLINFO_NUM_CONNECTS),
                'curl created no new connection for the second transfer — keep-alive reused the first',
            );
        } finally {
            fclose($server);
            $this->reapForkedHttpServer($pid);
        }
    }

    public function testInheritedHandleIsNeverDrivenAcrossProcessBoundary(): void
    {
        [$pid, $port, $server] = $this->forkHttpServer(
            static fn(string $request): string => self::echoResponseFor($request),
            5,
            2
        );
        try {
            $client   = new MlPairClient('http://ml.invalid', timeoutSec: 3);
            $decision = ['host' => 'ml.invalid', 'port' => $port, 'addresses' => ['127.0.0.1']];
            $method   = new \ReflectionMethod($client, 'postJsonCurl');
            self::assertIsString($method->invoke($client, "http://ml.invalid:$port/score-pair", '{}', $decision));
            $born     = self::curlHandle($client);
            $pidProp  = new \ReflectionProperty($client, 'curlHandlePid');

            // Simulate the post-fork state deterministically (a real
            // fork here would only add scheduling noise): an inherited
            // client carries the FORKER's pid; no live pid is ever -1.
            $pidProp->setValue($client, -1);

            self::assertIsString($method->invoke($client, "http://ml.invalid:$port/score-batch", '{}', $decision));
            $reborn = self::curlHandle($client);

            self::assertNotSame(
                spl_object_id($born),
                spl_object_id($reborn),
                'a handle created in another process is discarded, never driven here',
            );
            self::assertSame(
                (int) getmypid(),
                (int) $pidProp->getValue($client),
                'the new process re-initialised the handle and re-stamped ownership',
            );
        } finally {
            fclose($server);
            $this->reapForkedHttpServer($pid);
        }
    }

    private static function gateCalls(MlPairClient $client): int
    {
        return (new \ReflectionProperty($client, 'urlGateCalls'))->getValue($client);
    }

    private static function curlHandle(MlPairClient $client): \CurlHandle
    {
        $handle = (new \ReflectionProperty($client, 'curlHandle'))->getValue($client);
        self::assertInstanceOf(\CurlHandle::class, $handle);
        return $handle;
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

    /**
     * Keep-alive variant of forkHttpServer (engine-S21): ONE accepted
     * connection serves $requestsToServe requests sequentially — the
     * per-connection read loop never closes it between responses, and
     * each body is exactly Content-Length framed, so a client that
     * reuses its curl handle is answered req=2 on the SAME socket. A
     * client that dials a fresh connection instead is never accepted
     * at all (the child does not loop back to accept).
     *
     * @return array{0: int, 1: int, 2: resource} [pid, port, listener]
     */
    private function forkKeepAliveServer(int $requestsToServe, int $ioTimeoutSec = 5): array
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
            $conn = @stream_socket_accept($server, $ioTimeoutSec);
            $served = 0;
            if ($conn !== false) {
                stream_set_timeout($conn, $ioTimeoutSec);
                for ($i = 1; $i <= $requestsToServe; $i++) {
                    $request = '';
                    while (!str_contains($request, "\r\n\r\n")) {
                        $chunk = fread($conn, 8192);
                        if ($chunk === false || $chunk === '' || (stream_get_meta_data($conn)['timed_out'] ?? false)) {
                            break 2;
                        }
                        $request .= $chunk;
                    }
                    if (preg_match('/^Content-Length: (\d+)$/mi', $request, $m) === 1) {
                        $remaining = (int)$m[1];
                        while ($remaining > 0) {
                            $chunk = fread($conn, min(8192, $remaining));
                            if ($chunk === false || $chunk === '' || (stream_get_meta_data($conn)['timed_out'] ?? false)) {
                                break 2;
                            }
                            $remaining -= strlen($chunk);
                        }
                    }
                    $line  = strtok($request, "\r\n");
                    $body  = "req=$i\n" . ($line === false ? '' : $line);
                    @fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: "
                        . strlen($body) . "\r\n\r\n" . $body);
                    $served++;
                }
                fclose($conn);
            }
            fclose($server);
            exit($served === $requestsToServe ? 0 : 1);
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
