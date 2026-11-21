<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Cli\ConfigLoader;

/**
 * Item 1.2 — the max_ted_size knob contract:
 * integer with a >= 1 floor, root-only admission (a pair spans two
 * directories, so a per-directory cap is ambiguous), legacy-neutral
 * default equal to the extraction ceiling (the gate is inert unless a
 * user lowers it, which is why the goldens never move), and the
 * per-directory refusal that keeps one cost policy from silently
 * mis-feeding a shared scoring pass. No CLI flag in v1 (config file
 * only, per the item brief).
 */
final class MaxTedSizeConfigTest extends TestCase
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
        $dir = sys_get_temp_dir() . '/phpdup-mts-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    public function testDefaultMatchesExtractionCeiling(): void
    {
        $config = (new ConfigLoader())->load([$this->tmpDir()], null);
        // Inertness contract: the cap defaults to max_block_size, so no
        // extractable block is gated and every verdict stays as before.
        $this->assertSame(800, $config->maxTedSize);
        $this->assertSame($config->maxBlockSize, $config->maxTedSize);
    }

    public function testConfigFileCarriesCap(): void
    {
        $dir = $this->tmpDir();
        $file = $dir . '/phpdup.json';
        file_put_contents($file, json_encode(['max_ted_size' => 250]));
        $config = (new ConfigLoader())->load([$dir], $file);
        $this->assertSame(250, $config->maxTedSize);
    }

    public function testProgrammaticOverridesCarryCap(): void
    {
        $config = (new ConfigLoader())->load([$this->tmpDir()], null, ['max_ted_size' => 120]);
        $this->assertSame(120, $config->maxTedSize);
    }

    public function testZeroOrNegativeRefusedAtValidation(): void
    {
        foreach (['max_ted_size' => 0,] as $key => $zero) {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage($key);
            (new ConfigLoader())->validate([$key => $zero]);
        }
    }

    public function testNegativeRefusedAtValidation(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ConfigLoader())->validate(['max_ted_size' => -5]);
    }

    public function testNonIntegerRefusedAtValidation(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ConfigLoader())->validate(['max_ted_size' => 'lots']);
    }

    public function testFloatRefusedAtValidation(): void
    {
        // candidate_budget's coercion posture: integers only. 0.5 would be
        // a silent no-op; the loader must reject it, not floor it.
        $this->expectException(\RuntimeException::class);
        (new ConfigLoader())->validate(['max_ted_size' => 1.5]);
    }

    public function testConstructorFailFast(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Config(paths: ['/x'], exclude: [], maxTedSize: 0);
    }

    public function testPerDirectoryKeyRefusedLoudly(): void
    {
        $root = $this->tmpDir();
        $sub = $root . '/pkg';
        mkdir($sub);
        file_put_contents($root . '/root.php', "<?php function rootfn(\$a) { \$a->go(); }\n");
        // Valid per schema (same validator) but NOT a per-directory key —
        // discovery must refuse, never silently drop. A pair's two members
        // can live under different prefixes, so a per-dir cap has no
        // single meaning: that's the silent-misfeed class this refuses.
        file_put_contents($sub . '/.phpdup.json', json_encode(['max_ted_size' => 250]));

        try {
            (new ConfigLoader())->load([$root], null);
            $this->fail('per-directory max_ted_size must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('root', $e->getMessage());
            $this->assertStringContainsString('.phpdup.json', $e->getMessage());
        }
    }

    public function testWithOverridesPreservesRootCap(): void
    {
        // A per-dir payload WITHOUT the key must not wipe the root cap
        // (Config::withOverrides copies it — the silent-reset trap).
        $root = new Config(paths: ['/x'], exclude: [], maxTedSize: 250);
        $effective = $root->withOverrides(['min_block_size' => 12]);
        $this->assertSame(250, $effective->maxTedSize);
    }

    public function testBeltExcludesTheKey(): void
    {
        // shapeOverrides() must never admit max_ted_size — the refusal
        // above is the enforcement; belt set stays unchanged (root-only
        // keys are never emitted, so set-equality covers them trivially).
        $loader = new ConfigLoader();
        $ref = new \ReflectionMethod(ConfigLoader::class, 'shapeOverrides');
        $ref->setAccessible(true);
        $out = $ref->invoke($loader, ['max_ted_size' => 250, 'min_block_size' => 9]);
        $this->assertArrayNotHasKey('max_ted_size', $out);
        $this->assertArrayHasKey('min_block_size', $out);
    }

    public function testNoCliFlagInV1(): void
    {
        // The item ships config-only by ruling: Command.php must never
        // learn a --max-ted-size option (grep-pin the absence).
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Cli/Command.php');
        $this->assertStringNotContainsString('max-ted-size', $src);
    }
}
