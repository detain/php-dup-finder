<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Cli\ConfigLoader;
use Phpdup\Extraction\Block;
use Phpdup\Ml\MlPairClient;
use Phpdup\Parsing\AstParser;
use Phpdup\Util\LineRange;

/**
 * once-S54 (php-dup-finder-once-076 / once-083 / once-084).
 *
 * The ml_pair_url admission seam validates SHAPE, never DNS, and the
 * private address families move behind an explicit opt-in
 * (`ml_pair_allow_private`, CLI: --ml-pair-allow-private, default off
 * per the [OWNER] ruling). Pinned here:
 *
 * (a) the admission door performs zero resolver calls — structural
 *     token pin on its own source + the behavioral flip that an
 *     unresolvable name now CONSTRUCTS (the old fail-closed gate
 *     walked DNS at admission and threw);
 * (b) the default-policy refusal matrix through the Config seam, each
 *     message naming the refused space AND the opt-in spelling;
 * (c) the opt-in admits the private families while the reserved
 *     non-private spaces (0/8, 192.0.0/24, 198.18/15, multicast) stay
 *     refused either way; the NAT64 64:ff9b::/96 embedded-IPv4
 *     recursion (the once-083 bypass of the shared engine-S19a matrix)
 *     refuses under default policy and is classified with its
 *     embedded v4; inet_aton shorthands refuse in both modes;
 * (d) the ConfigLoader::validate()/load() legs, root-only-ness of the
 *     opt-in key (a planted subtree .phpdup.json is refused loudly);
 * (e) the dial door pairEndpointDecision() — fail-closed name walk,
 *     literal pass-through, opt-in loopback admit;
 * (f) end-to-end: a real loopback sidecar answers under the opt-in
 *     and is never dialled without it (child-server exit status is
 *     the no-connection proof — no accept race);
 * (g) the engine-S21 memo: exactly one dial decision per client
 *     instance even when the opt-in takes the literal fast path.
 */
final class MlPairUrlValidationTest extends TestCase
{
    // -----------------------------------------------------------------
    // (a) zero-resolver admission
    // -----------------------------------------------------------------

    public function testAdmissionDoorSourceIsStructurallyResolverFree(): void
    {
        $method = new \ReflectionMethod(MlPairClient::class, 'pairUrlAdmissionError');
        $file   = (string)file_get_contents((string)$method->getFileName());
        self::assertNotFalse($file);
        $lines    = explode("\n", $file);
        $srcLines = array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);
        $src      = implode("\n", $srcLines);
        // Comment-blind pin: prose may NAME the forbidden calls; the
        // executable text must not contain them.
        $code = (string)preg_replace('#/\*.*?\*/#s', '', $src);
        $code = (string)preg_replace('#//[^\n]*#', '', $code);
        foreach (['dns_get_record', 'gethostbynamel', 'gateUrl', 'isAllowedUrl'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $code,
                "pairUrlAdmissionError must never touch '$forbidden' — admission resolves nothing (once-076)",
            );
        }
    }

    public function testUnresolvableNameAdmitsAtConfigConstructionProvingDeferral(): void
    {
        // Pre-S54 this threw: the shared gate resolved the name at
        // admission and fail-closed. Admitting it proves resolution is
        // deferred to the dial (and costs no resolver call here).
        $config = self::config(['mlPairUrl' => 'http://ml-pair-s54.invalid/score']);
        self::assertSame('http://ml-pair-s54.invalid/score', $config->mlPairUrl);
        self::assertFalse($config->mlPairAllowPrivate, 'opt-in defaults off ([OWNER] ruling)');
    }

    // -----------------------------------------------------------------
    // (b) default-policy refusals through the Config seam
    // -----------------------------------------------------------------

    /** @return array<string,array{0:string,1:string}> */
    public static function defaultPolicyRefusalProvider(): array
    {
        return [
            'loopback 127.0.0.1'      => ['http://127.0.0.1:8080', 'loopback 127.0.0.0/8'],
            'RFC1918 10.0.0.1'        => ['http://10.0.0.1', 'RFC 1918 private'],
            'RFC1918 172.20.5.6'      => ['http://172.20.5.6', 'RFC 1918 private'],
            'RFC1918 192.168.1.1'     => ['http://192.168.1.1', 'RFC 1918 private'],
            'CGNAT 100.64.0.1'        => ['http://100.64.0.1', 'CGNAT 100.64.0.0/10'],
            'CGNAT 100.100.7.7'       => ['http://100.100.7.7', 'CGNAT 100.64.0.0/10'],
            'link-local metadata'     => ['http://169.254.169.254', 'link-local 169.254.0.0/16'],
            '0.0.0.0 unspecified'     => ['http://0.0.0.0:8000', '0.0.0.0/8'],
            'v6 loopback [::1]'       => ['http://[::1]:8000', 'IPv6 loopback ::1'],
            'ULA [fd12:3456::1]'      => ['http://[fd12:3456::1]', 'unique-local fc00::/7'],
            'v6 link-local [fe80::1]' => ['http://[fe80::1]', 'link-local fe80::/10'],
            'mapped loopback'         => ['http://[::ffff:127.0.0.1]', 'IPv4-mapped ::ffff:0:0/96 wrapping loopback 127.0.0.0/8'],
            'mapped metadata'         => ['http://[::ffff:169.254.169.254]', 'IPv4-mapped ::ffff:0:0/96 wrapping link-local'],
            // once-083: the NAT64 well-known prefix smuggles 127.0.0.1
            // past the pre-existing shared matrix — this door refuses it.
            'NAT64 loopback'          => ['http://[64:ff9b::7f00:1]', 'NAT64 64:ff9b::/96 wrapping loopback 127.0.0.0/8'],
            'NAT64 RFC1918'           => ['http://[64:ff9b::a00:1]', 'NAT64 64:ff9b::/96 wrapping RFC 1918 private'],
            'compat loopback ::x'     => ['http://[::7f00:1]', 'loopback 127.0.0.0/8'],
            'name localhost'          => ['http://localhost:8000', 'localhost special-use'],
            'subdomain localhost'     => ['http://sidecar.localhost:9', 'localhost special-use'],
            'multicast v4'            => ['http://224.0.0.1', 'multicast/reserved 224.0.0.0/4'],
            'reserved 240'            => ['http://255.255.255.255', 'multicast/reserved 224.0.0.0/4'],
            'IETF 192.0.0/24'         => ['http://192.0.0.1', '192.0.0.0/24'],
            'benchmark 198.18/15'     => ['http://198.18.0.1', '198.18.0.0/15'],
            'shorthand decimal'       => ['http://2130706433', 'inet_aton'],
            'shorthand hex'           => ['http://0x7f000001', 'inet_aton'],
            'shorthand abbrev'        => ['http://127.1', 'inet_aton'],
            'shorthand octal'         => ['http://0177.0.0.1', 'inet_aton'],
            'file scheme'             => ['file:///etc/passwd', 'ml_pair_url must be an http(s) URL'],
            'gopher scheme'           => ['gopher://example.com/', 'ml_pair_url must be an http(s) URL'],
            'no host'                 => ['http:///score-pair', 'not a parseable URL'],
            'bracketed v4 mismatch'   => ['http://[127.0.0.1]:9', 'bracketed but not valid IPv6'],
            'userinfo hides loopback' => ['http://user:pass@127.0.0.1:9/', 'loopback 127.0.0.0/8'],
        ];
    }

    /**
     * @dataProvider defaultPolicyRefusalProvider
     */
    public function testConfigSeamRefusesUnderDefaultPolicy(string $url, string $needle): void
    {
        try {
            self::config(['mlPairUrl' => $url]);
            self::fail("Config must refuse ml_pair_url: $url");
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('ml_pair_url', $e->getMessage(), "message must name the key: $url");
            self::assertStringContainsString($needle, $e->getMessage(), "message must name the refused space for: $url");
            // once-084: the fix for "error only names 0.0.0.0" — every
            // policy refusal shows the operator the opt-in, both
            // spellings.
            if (str_contains($needle, 'wrapping') || str_contains($needle, 'private') || str_contains($needle, 'loopback')
                || str_contains($needle, 'CGNAT') || str_contains($needle, 'link-local') || str_contains($needle, 'special-use')
                || str_contains($needle, 'fc00')) {
                if (!str_contains($needle, '192.0.0.0/24') && !str_contains($needle, '198.18.0.0/15') && !str_contains($needle, '224.0.0.0/4')) {
                    self::assertStringContainsString('ml_pair_allow_private', $e->getMessage());
                    self::assertStringContainsString('--ml-pair-allow-private', $e->getMessage());
                }
            }
        }
    }

    /** @return array<string,array{0:string}> */
    public static function defaultPolicyAdmissionProvider(): array
    {
        return [
            // Parity with ConfigLoaderTest's existing accept pin.
            'TEST-NET-3 literal'    => ['https://203.0.113.7/api'],
            'public name deferred'  => ['http://ml-pair-s54.invalid/score'],
            'bracketed v6 public'   => ['http://[2001:db8::1]:8443/base'],
            'mapped public'         => ['http://[::ffff:8.8.8.8]'],
            'NAT64 to public'       => ['http://[64:ff9b::c000:0203]'],
            'explicit port name'    => ['https://example.org:8443'],
        ];
    }

    /** @dataProvider defaultPolicyAdmissionProvider */
    public function testConfigSeamAdmitsUnderDefaultPolicy(string $url): void
    {
        $config = self::config(['mlPairUrl' => $url]);
        self::assertSame($url, $config->mlPairUrl);
    }

    // -----------------------------------------------------------------
    // (c) opt-in posture
    // -----------------------------------------------------------------

    /** @return array<string,array{0:string}> */
    public static function optInAdmitsProvider(): array
    {
        return [
            'loopback'          => ['http://127.0.0.1:8080'],
            'RFC1918 10'        => ['http://10.0.0.1'],
            'RFC1918 172'       => ['http://172.20.5.6:9000'],
            'RFC1918 192.168'   => ['http://192.168.1.1'],
            'CGNAT'             => ['http://100.64.0.1'],
            'link-local'        => ['http://169.254.169.254'],
            'v6 loopback'       => ['http://[::1]:8000'],
            'ULA'               => ['http://[fd12:3456::1]'],
            'v6 link-local'     => ['http://[fe80::1]'],
            'mapped loopback'   => ['http://[::ffff:127.0.0.1]'],
            'NAT64 loopback'    => ['http://[64:ff9b::7f00:1]'],
            'localhost name'    => ['http://localhost:8000'],
        ];
    }

    /** @dataProvider optInAdmitsProvider */
    public function testOptInAdmitsPrivateFamilies(string $url): void
    {
        $config = self::config(['mlPairUrl' => $url, 'mlPairAllowPrivate' => true]);
        self::assertSame($url, $config->mlPairUrl);
        self::assertTrue($config->mlPairAllowPrivate);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function alwaysRefusedProvider(): array
    {
        return [
            '0.0.0.0/8'          => ['http://0.0.0.0:8000', '0.0.0.0/8'],
            '224/4 multicast'    => ['http://224.0.0.1', 'multicast/reserved'],
            '240/4+ reserved'    => ['http://240.0.0.1', 'multicast/reserved'],
            'v6 multicast'       => ['http://[ff02::1]', 'IPv6 multicast ff00::/8'],
            '192.0.0/24'         => ['http://192.0.0.1', '192.0.0.0/24'],
            '198.18/15'          => ['http://198.18.0.1', '198.18.0.0/15'],
            'NAT64 0/8 embed'    => ['http://[64:ff9b::1]', '0.0.0.0/8'],
            'mapped 0/8 embed'   => ['http://[::ffff:0.0.0.1]', '0.0.0.0/8'],
            'shorthand decimal'  => ['http://2130706433', 'inet_aton'],
            'shorthand octal'    => ['http://0177.0.0.1', 'inet_aton'],
        ];
    }

    /**
     * @dataProvider alwaysRefusedProvider
     */
    public function testReservedSpacesRefuseEvenWithOptIn(string $url, string $needle): void
    {
        try {
            self::config(['mlPairUrl' => $url, 'mlPairAllowPrivate' => true]);
            self::fail("opt-in must not admit: $url");
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('ml_pair_url', $e->getMessage());
            self::assertStringContainsString($needle, $e->getMessage());
            // The message stays useful under opt-in: it names the key
            // whose enablement did NOT unlock this space.
            if (!str_contains($needle, 'inet_aton')) {
                self::assertStringContainsString('ml_pair_allow_private', $e->getMessage());
            }
        }
    }

    // -----------------------------------------------------------------
    // (d) ConfigLoader validate()/load() legs
    // -----------------------------------------------------------------

    public function testValidateRejectsNonBooleanOptIn(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ml_pair_allow_private must be a boolean');
        (new ConfigLoader())->validate(['ml_pair_allow_private' => 'yes']);
    }

    public function testValidateRefusesPrivateUrlUnderDefaultPolicy(): void
    {
        try {
            (new ConfigLoader())->validate(['ml_pair_url' => 'http://127.0.0.1:8080']);
            self::fail('loopback ml_pair_url must refuse at validation');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('ml_pair_url', $e->getMessage());
            self::assertStringContainsString('loopback 127.0.0.0/8', $e->getMessage());
            self::assertStringContainsString('ml_pair_allow_private', $e->getMessage());
        }
    }

    public function testValidateAdmitsPrivateUrlWithOptInAndZeroDns(): void
    {
        // file:// keeps the substring-parity of the historical pin
        // while the loopback case proves the policy leg.
        (new ConfigLoader())->validate([
            'ml_pair_url'           => 'http://127.0.0.1:8080',
            'ml_pair_allow_private' => true,
        ]);
        $this->expectNotToPerformAssertions();
    }

    public function testValidateRejectsNonStringUrl(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ml_pair_url must be a string');
        (new ConfigLoader())->validate(['ml_pair_url' => 42]);
    }

    public function testLoadThreadsRootKeysIntoConfig(): void
    {
        $tmp = sys_get_temp_dir() . '/phpdup-s54-load-' . uniqid();
        mkdir($tmp);
        $configFile = $tmp . '/phpdup.json';
        file_put_contents($configFile, (string)json_encode([
            'ml_pair_url'           => 'http://127.0.0.1:9',
            'ml_pair_allow_private' => true,
        ]));
        try {
            $config = (new ConfigLoader())->load(paths: [$tmp], configFile: $configFile);
            self::assertSame('http://127.0.0.1:9', $config->mlPairUrl);
            self::assertTrue($config->mlPairAllowPrivate);
        } finally {
            @unlink($configFile);
            @rmdir($tmp);
        }
    }

    public function testRootOptInCarriesThroughOverridesToo(): void
    {
        $tmp = sys_get_temp_dir() . '/phpdup-s54-ovr-' . uniqid();
        mkdir($tmp);
        try {
            $config = (new ConfigLoader())->load(
                paths: [$tmp],
                configFile: null,
                overrides: ['ml_pair_url' => 'http://10.0.0.5:8080', 'ml_pair_allow_private' => true],
            );
            self::assertSame('http://10.0.0.5:8080', $config->mlPairUrl);
            self::assertTrue($config->mlPairAllowPrivate);
        } finally {
            @rmdir($tmp);
        }
    }

    /**
     * once-076's attack surface, closed at both ends: a planted
     * subtree file may not flip the SSRF opt-in — it is refused
     * LOUDLY (same stance family as max_ted_size et al.), never
     * silently dropped.
     */
    public function testPerDirectoryOptInKeyRefusedLoudly(): void
    {
        $tmp = sys_get_temp_dir() . '/phpdup-s54-perdir-' . uniqid();
        mkdir($tmp);
        try {
            file_put_contents($tmp . '/.phpdup.json', (string)json_encode(['ml_pair_allow_private' => true]));

            try {
                (new ConfigLoader())->load(paths: [$tmp], configFile: null);
                $this->fail('per-directory ml_pair_allow_private must throw');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('ml_pair_allow_private', $e->getMessage());
                $this->assertStringContainsString('not a per-directory key', $e->getMessage());
                $this->assertStringContainsString('.phpdup.json', $e->getMessage());
            }
        } finally {
            @unlink($tmp . '/.phpdup.json');
            @rmdir($tmp);
        }
    }

    /**
     * The once-076 exploit itself: a planted per-directory URL whose
     * validation used to fire a blocking resolver walk now passes
     * admission with zero DNS — and is still dropped from the shaped
     * overrides (ml_pair_url is not a per-directory consumer key).
     */
    public function testPerDirectoryUrlNoLongerTriggersAdmissionDns(): void
    {
        $tmp = sys_get_temp_dir() . '/phpdup-s54-perdir-dns-' . uniqid();
        mkdir($tmp);
        try {
            file_put_contents($tmp . '/.phpdup.json', (string)json_encode([
                'ml_pair_url' => 'http://exfil-s54.invalid.evil/',
            ]));
            $start = microtime(true);
            $config = (new ConfigLoader())->load(paths: [$tmp], configFile: null);
            self::assertSame('', $config->mlPairUrl, 'per-directory ml_pair_url stays inert');
            // A resolver walk of a live top-level .evil TLD could never
            // be this fast; admission resolved nothing. Generous bound,
            // still orders of magnitude below a hanging resolver.
            self::assertLessThan(2.0, microtime(true) - $start);
        } finally {
            @unlink($tmp . '/.phpdup.json');
            @rmdir($tmp);
        }
    }

    // -----------------------------------------------------------------
    // (e) the dial door
    // -----------------------------------------------------------------

    public function testDialDoorRefusesPrivateLiteralByDefault(): void
    {
        self::assertNull(MlPairClient::pairEndpointDecision('http://127.0.0.1:9/score-pair'));
        self::assertNull(MlPairClient::pairEndpointDecision('http://[64:ff9b::7f00:1]/score-pair'));
    }

    public function testDialDoorAdmitsPrivateLiteralUnderOptIn(): void
    {
        $decision = MlPairClient::pairEndpointDecision('http://127.0.0.1:9/score-pair', true);
        self::assertIsArray($decision);
        self::assertSame('127.0.0.1', $decision['host']);
        self::assertSame(9, $decision['port']);
        self::assertSame(['127.0.0.1'], $decision['addresses']);
    }

    public function testDialDoorResolvesLocalhostNameUnderOptIn(): void
    {
        // Hosts-file names ride the gethostbynamel fallback leg; a box
        // without a localhost entry cannot be judged for it.
        if (@gethostbynamel('localhost') === false && @dns_get_record('localhost', DNS_A | DNS_AAAA) === false) {
            $this->markTestSkipped('localhost not resolvable on this box');
        }
        $decision = MlPairClient::pairEndpointDecision('http://localhost:9/score-pair', true);
        if ($decision === null) {
            $this->markTestSkipped('no usable localhost records on this resolver');
        }
        self::assertSame('localhost', $decision['host']);
        foreach ($decision['addresses'] as $address) {
            $bytes = inet_pton($address);
            self::assertNotFalse($bytes);
            self::assertTrue($bytes === inet_pton('::1') || (strlen($bytes) === 4 && ord($bytes[0]) === 127));
        }
    }

    public function testDialDoorFailClosedOnZeroRecords(): void
    {
        // Documented policy: a name with no A/AAAA record and no
        // hosts-file entry is refused at the DIAL (never at admission).
        self::assertNull(MlPairClient::pairEndpointDecision('http://ml-pair-s54.invalid/score-pair'));
        self::assertNull(MlPairClient::pairEndpointDecision('http://ml-pair-s54.invalid/score-pair', true));
    }

    public function testDialDoorPreservesEngineS19DefaultNamePath(): void
    {
        // !allowPrivate names ride the shared gate VERBATIM when the
        // gate's own records all pass this door's matrix. A public
        // name that exists on the box keeps engine-S19's fail-closed
        // walk; an unreachable one returns null either way — the
        // point is the branch stays the shared gate, asserted
        // indirectly via a literal decision shape unchanged from
        // gateUrl (parity pin).
        $gate   = \Phpdup\Ml\MlClient::gateUrl('http://203.0.113.7/score-pair');
        $door   = MlPairClient::pairEndpointDecision('http://203.0.113.7/score-pair');
        self::assertIsArray($gate);
        self::assertSame($gate, $door);
    }

    // -----------------------------------------------------------------
    // (f) end-to-end loopback sidecar
    // -----------------------------------------------------------------

    public function testLoopbackSidecarAnswersUnderOptInOnly(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('forked HTTP server requires pcntl_fork/pcntl_waitpid');
        }

        [$pid, $port, $server] = $this->forkJsonServer('{"similarity":0.9,"confidence":0.7}');
        $a = $this->makeBlock();
        $b = $this->makeBlock();
        try {
            $opted = new MlPairClient("http://127.0.0.1:$port", timeoutSec: 3, allowPrivate: true);
            $verdict = $opted->score($a, $b);
            self::assertIsArray($verdict, 'the opted-in client must reach the loopback sidecar');
            self::assertSame(0.9, $verdict['similarity']);
            self::assertSame(0.7, $verdict['confidence']);
        } finally {
            pcntl_waitpid($pid, $status);
            fclose($server);
        }
        self::assertSame(0, pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1, 'server must have served the connection');

        // Default posture: the same URL is refused at the dial door
        // and the server-proves-it (second child exits 1 — zero
        // accepts — decisive without a select race).
        [$pid2, $port2, $server2] = $this->forkJsonServer('{"similarity":0.5,"confidence":0.5}');
        try {
            $default = new MlPairClient("http://127.0.0.1:$port2", timeoutSec: 1);
            self::assertNull($default->score($a, $b), 'default posture must never dial a loopback sidecar');
            self::assertNull($default->scoreBatch([[$a, $b]]));
        } finally {
            pcntl_waitpid($pid2, $status2);
            fclose($server2);
        }
        self::assertTrue(pcntl_wifexited($status2));
        self::assertSame(1, pcntl_wexitstatus($status2), 'no connection may reach the loopback listener');
    }

    // -----------------------------------------------------------------
    // (g) engine-S21 memo across the new door
    // -----------------------------------------------------------------

    public function testOptInLiteralPathStillMintsExactlyOneDialDecision(): void
    {
        $a = $this->makeBlock();
        $b = $this->makeBlock();
        // Port 1: connect refused instantly, nothing listens, hermetic.
        $client = new MlPairClient('http://127.0.0.1:1', timeoutSec: 1, allowPrivate: true);
        self::assertSame(0, self::gateCalls($client), 'construction performs no gate work');
        self::assertNull($client->score($a, $b));
        self::assertSame(1, self::gateCalls($client), 'serial path mints the decision once');
        self::assertNull($client->scoreBatch([[$a, $b]]));
        self::assertNull($client->score($a, $b));
        self::assertSame(1, self::gateCalls($client), 'literal fast path rides the same memo — no second walk');
    }

    // =================================================================
    // helpers
    // =================================================================

    /** @param array<string,mixed> $extra */
    private static function config(array $extra): Config
    {
        return new Config(...['paths' => ['src'], 'exclude' => []] + $extra);
    }

    private static function gateCalls(MlPairClient $client): int
    {
        return (int)(new \ReflectionProperty($client, 'urlGateCalls'))->getValue($client);
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
        (new \Phpdup\Normalization\Normalizer(mode: 'aggressive'))->normalize($block);
        return $block;
    }

    /**
     * One-shot loopback server answering a fixed JSON body (mirrors
     * MlClientTest/MlPairClientTest's forked-server discipline; test
     * files may not share code without a new unlocked fixture). The
     * child exits 0 after serving >=1 request, 1 after serving none
     * within the accept timeout — the parent reads that status as the
     * connection-proof, race-free.
     *
     * @return array{0: int, 1: int, 2: resource} [pid, port, listener]
     */
    private function forkJsonServer(string $jsonBody, int $ioTimeoutSec = 5): array
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            $this->markTestSkipped("loopback bind unavailable: $errstr");
        }
        $name = (string)stream_socket_get_name($server, false);
        $port = (int)substr($name, (int)strrpos($name, ':') + 1);
        self::assertGreaterThan(0, $port);

        $pid = pcntl_fork();
        if ($pid === -1) {
            fclose($server);
            $this->markTestSkipped('pcntl_fork failed');
        }
        if ($pid === 0) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            $conn = @stream_socket_accept($server, $ioTimeoutSec);
            if ($conn === false) {
                fclose($server);
                exit(1);
            }
            stream_set_timeout($conn, $ioTimeoutSec);
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
            $response = "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: "
                . strlen($jsonBody) . "\r\n\r\n" . $jsonBody;
            @fwrite($conn, $response);
            fclose($conn);
            fclose($server);
            exit(0);
        }
        return [$pid, $port, $server];
    }
}
