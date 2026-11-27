<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Cli\ConfigLoader;

/**
 * Item 1.2 (E2) — the window_oversized knob's contract: default on,
 * loadable from the root config file ONLY (no CLI flag — the
 * max_ted_size precedent), validated as a strict boolean, refused with
 * its own loud per-directory message, and preserved through
 * per-directory override merging.
 */
final class ConfigWindowOversizedTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    private function tmpDir(): string
    {
        $dir = sys_get_temp_dir() . '/wov-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o775, true);
        $this->dirs[] = $dir;
        return $dir;
    }

    private function writeRootConfig(string $dir, string $json): string
    {
        file_put_contents($dir . '/phpdup.json', $json);
        return $dir . '/phpdup.json';
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            ) as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($dir);
        }
    }

    // ── defaults & loading ──────────────────────────────────────────────

    public function testDefaultIsTrue(): void
    {
        // The posture ships ON: corpora without oversized blocks never
        // reach the window path (byte-identity), so on-by-default buys
        // the fix where it matters and nothing elsewhere.
        $this->assertTrue(Config::defaults([])->windowOversized);
        $this->assertTrue((new ConfigLoader())->load(paths: ['src'], configFile: null)->windowOversized);
    }

    public function testRootConfigFileFalseLoads(): void
    {
        $dir    = $this->tmpDir();
        $config = $this->writeRootConfig($dir, '{"window_oversized": false}');
        $loaded = (new ConfigLoader())->load([$dir], $config, []);
        $this->assertFalse($loaded->windowOversized);
    }

    public function testOverridesArrayCarriesARealBool(): void
    {
        // No CLI flag exists for this knob (config-file only, like
        // max_ted_size); the overrides array is its programmatic entry
        // point and — as with lazy_ast — it carries REAL booleans: the
        // numeric-coercion leg never invents a string grammar here.
        $dir    = $this->tmpDir();
        $loaded = (new ConfigLoader())->load([$dir], null, ['window_oversized' => false]);
        $this->assertFalse($loaded->windowOversized);
    }

    // ── validation ──────────────────────────────────────────────────────

    public function testNonBooleanIsRefusedWithFieldName(): void
    {
        $dir    = $this->tmpDir();
        $config = $this->writeRootConfig($dir, '{"window_oversized": "yes"}');
        try {
            (new ConfigLoader())->load([$dir], $config, []);
            $this->fail('window_oversized must be a strict boolean');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('window_oversized must be a boolean', $e->getMessage());
        }
    }

    public function testIntegerOneIsNotABoolean(): void
    {
        // PSR-4 honesty: 1 is not true here — the family of strict
        // booleans (incremental, lazy_ast, …) refuses numeric coercion.
        $dir    = $this->tmpDir();
        $config = $this->writeRootConfig($dir, '{"window_oversized": 1}');
        $this->expectException(\RuntimeException::class);
        (new ConfigLoader())->load([$dir], $config, []);
    }

    // ── root-only posture ───────────────────────────────────────────────

    public function testPerDirectoryFileCarryingTheKeyFailsTheLoad(): void
    {
        $dir = $this->tmpDir();
        mkdir($dir . '/sub');
        file_put_contents($dir . '/sub/.phpdup.json', '{"window_oversized": false}');

        try {
            (new ConfigLoader())->load([$dir], null, []);
            self::fail('per-directory window_oversized must be refused');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith(
                'window_oversized is not a per-directory key (root phpdup.json only): ',
                $e->getMessage(),
            );
        }
    }

    // ── override merging ────────────────────────────────────────────────

    public function testWithOverridesPreservesThePosture(): void
    {
        // The per-directory merge path must never silently reset the
        // extraction stance (the same silent-misfeed class max_ted_size
        // documents — withOverrides is the only re-entry point).
        $base = new Config(paths: ['src'], exclude: [], windowOversized: false);
        $kept = $base->withOverrides(['min_block_size' => 12]);
        $this->assertFalse($kept->windowOversized);
        $this->assertSame(12, $kept->minBlockSize);

        $on = new Config(paths: ['src'], exclude: [], windowOversized: true);
        $this->assertTrue($on->withOverrides([])->windowOversized);
    }
}
