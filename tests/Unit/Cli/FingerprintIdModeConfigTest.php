<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Cli\ConfigLoader;

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
}
