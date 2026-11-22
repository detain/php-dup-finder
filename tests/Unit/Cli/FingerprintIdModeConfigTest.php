<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Cli\ConfigLoader;
use Phpdup\Fingerprint\FingerprintIdMode;
use Phpdup\Parsing\AstCache;
use Phpdup\Persistence\ClusterCache;
use Phpdup\Persistence\IndexStore;

/**
 * Item 1.1 (§5a) — the fingerprint_id_mode knob contract:
 * enumerated values, root-only admission, legacy default, and the
 * per-directory refusal that keeps bag species from mixing inside one
 * candidate index / warm cache. No CLI flag in v1.
 */
final class FingerprintIdModeConfigTest extends TestCase
{
    /** @var list<string> */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            ) as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($dir);
        }
        $this->tmpDirs = [];
    }

    private function tmpDir(): string
    {
        $dir = sys_get_temp_dir() . '/phpdup-fpm-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    public function testDefaultIsNumbered(): void
    {
        $config = (new ConfigLoader())->load([$this->tmpDir()], null);
        $this->assertSame('numbered', $config->fingerprintIdMode);
        $this->assertFalse($config->fingerprintIdsUnnumbered());
    }

    public function testConfigFileCarriesUnnumbered(): void
    {
        $dir = $this->tmpDir();
        $file = $dir . '/phpdup.json';
        file_put_contents($file, json_encode(['fingerprint_id_mode' => 'un-numbered']));
        $config = (new ConfigLoader())->load([$dir], $file);
        $this->assertSame('un-numbered', $config->fingerprintIdMode);
        $this->assertTrue($config->fingerprintIdsUnnumbered());
    }

    public function testProgrammaticOverridesCarryMode(): void
    {
        $config = (new ConfigLoader())->load([$this->tmpDir()], null, ['fingerprint_id_mode' => 'un-numbered']);
        $this->assertSame('un-numbered', $config->fingerprintIdMode);
    }

    public function testBogusModeRefusedAtValidation(): void
    {
        $dir = $this->tmpDir();
        $file = $dir . '/phpdup.json';
        file_put_contents($file, json_encode(['fingerprint_id_mode' => 'off']));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fingerprint_id_mode');
        (new ConfigLoader())->load([$dir], $file);
    }

    public function testRealnamesNotAdmittedYet(): void
    {
        // 1.1-follow-up mode: admitting 'realnames' before the
        // Normalizer-side work would alias it to un-numbered semantics.
        $this->expectException(\RuntimeException::class);
        (new ConfigLoader())->validate(['fingerprint_id_mode' => 'realnames']);
    }

    public function testConfigConstructorFailFast(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Config(paths: ['/x'], exclude: [], fingerprintIdMode: 'Numbered');
    }

    public function testPerDirectoryKeyRefusedLoudly(): void
    {
        $root = $this->tmpDir();
        $sub = $root . '/pkg';
        mkdir($sub);
        file_put_contents($root . '/root.php', "<?php function rootfn(\$a) { \$a->go(); }\n");
        // Valid per schema (same validator) but NOT a per-directory key —
        // discovery must refuse, never silently drop.
        file_put_contents($sub . '/.phpdup.json', json_encode(['fingerprint_id_mode' => 'un-numbered']));

        try {
            (new ConfigLoader())->load([$root], null);
            $this->fail('per-directory fingerprint_id_mode must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('root', $e->getMessage());
            $this->assertStringContainsString('.phpdup.json', $e->getMessage());
        }
    }

    public function testWithOverridesPreservesRootMode(): void
    {
        // A per-dir payload WITHOUT the key must not wipe the root mode
        // (Config::withOverrides copies it — the silent-reset trap).
        $root = new Config(paths: ['/x'], exclude: [], fingerprintIdMode: 'un-numbered');
        $effective = $root->withOverrides(['min_block_size' => 12]);
        $this->assertSame('un-numbered', $effective->fingerprintIdMode);
    }

    public function testBeltExcludesTheKey(): void
    {
        // shapeOverrides() must never admit fingerprint_id_mode — the
        // refusal above is the enforcement; belt set stays unchanged.
        $loader = new ConfigLoader();
        $ref = new \ReflectionMethod(ConfigLoader::class, 'shapeOverrides');
        $out = $ref->invoke($loader, ['fingerprint_id_mode' => 'un-numbered', 'min_block_size' => 9]);
        $this->assertArrayNotHasKey('fingerprint_id_mode', $out);
        $this->assertArrayHasKey('min_block_size', $out);
    }

    // ------------------------------------------------------------------
    // engine-S34b — the cache-identity roster (half B). Distinct axis
    // from the knob above: `fingerprint_id_mode` selects how stamps are
    // spelled WITHIN a fingerprint; FingerprintIdMode::CACHE_IDENTITY
    // names WHICH fingerprint generation persisted caches belong to.
    // ------------------------------------------------------------------

    public function testCacheIdentityTokenIsRostered(): void
    {
        // The literal is the roster: a regeneration of this assertion's
        // expected value IS the cache-invalidation event and must arrive
        // with a golden-disclosure + store-bump trail, never quietly.
        $this->assertSame('cachekey-v1-framing-nul', FingerprintIdMode::CACHE_IDENTITY);
        // Grammar of the token: roster-<gen>-<era words>, so future
        // bumps stay greppable and era-nameable the floatbytes /
        // namesentinel way.
        $this->assertMatchesRegularExpression(
            '/^cachekey-v\d+-[a-z0-9-]+$/',
            FingerprintIdMode::CACHE_IDENTITY
        );
    }

    public function testRootTokenFileMirrorsTheConstantByteExactly(): void
    {
        $file = dirname(__DIR__, 3) . '/' . FingerprintIdMode::ROOT_TOKEN_FILE;
        $this->assertFileExists($file, 'the root roster file is part of the cache-identity contract');
        $this->assertSame(
            FingerprintIdMode::rootTokenBytes(),
            (string) file_get_contents($file),
            'root token file must be the identity + one LF — no CRLF drift, no comment lines'
        );
    }

    public function testRosterDocblockCarriesTheNulFramingEraLineage(): void
    {
        // Rider (4) of the S34a fix round, kept as a test: the era
        // comment names the \x00 widening on the same line-of-succession
        // style as the store comments (floatbytes → namesentinel →
        // framing → framing-nul), because a blob written before fix1
        // serves stale structural hashes for every control-byte
        // literal and NO golden diff will ever reveal it.
        $doc = (string) (new \ReflectionClass(FingerprintIdMode::class))->getDocComment();
        foreach (['floatbytes', 'namesentinel', 'framing-nul', 'NUL', 'a674d90', 'e18d52e', '0/71'] as $needle) {
            $this->assertStringContainsString(
                $needle,
                $doc,
                "roster era lineage lost its '$needle' word"
            );
        }
    }

    public function testRosterTokenIsDistinctFromEveryPreFramingStoreToken(): void
    {
        // Collision belt: the roster must never accidentally equal a
        // store's own (possibly already-stale) version literal — stores
        // fold the roster INTO their prefix, they do not become it.
        $stores = [
            'IndexStore'  => (new \ReflectionClassConstant(IndexStore::class, 'PARSER_VERSION'))->getValue(),
            'ClusterCache' => (new \ReflectionClassConstant(ClusterCache::class, 'PARSER_VERSION'))->getValue(),
            'AstCache'    => (new \ReflectionClassConstant(AstCache::class, 'VERSION'))->getValue(),
        ];
        foreach ($stores as $name => $token) {
            $this->assertIsString($token);
            $this->assertNotSame(
                FingerprintIdMode::CACHE_IDENTITY,
                $token,
                "$name token collided with the roster — the bump is not wired by aliasing"
            );
        }
        // And the knob axis stays separable from the roster axis:
        $this->assertNotContains(FingerprintIdMode::CACHE_IDENTITY, Config::FINGERPRINT_ID_MODES);
    }
}
