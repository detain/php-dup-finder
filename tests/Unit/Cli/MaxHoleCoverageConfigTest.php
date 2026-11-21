<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Cli\ConfigLoader;

/**
 * Item 5.4c — the max_hole_coverage knob contract: a float within 0..1,
 * root-only admission (coverage is a verdict on a whole cluster's template,
 * and a cluster's members can straddle per-directory prefixes — the same
 * silent-misfeed class max_ted_size refuses), and a DEFAULT of exactly 1.0
 * so the re-express annotation never fires out of the box (no hole fraction
 * is strictly above 1, which is why the goldens never move on the default
 * path). Unlike max_ted_size this ships WITH a CLI flag — the flag-absence
 * grep-pin below is inverted to a presence pin.
 */
final class MaxHoleCoverageConfigTest extends TestCase
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
        $dir = sys_get_temp_dir() . '/phpdup-mhc-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    public function testDefaultIsInertCeiling(): void
    {
        $config = (new ConfigLoader())->load([$this->tmpDir()], null);
        // Inertness contract: 1.0 can never be exceeded (coverage <= 1), so
        // the re-express annotation is opt-in and default runs are unchanged.
        $this->assertSame(1.0, $config->maxHoleCoverage);
    }

    public function testConfigFileCarriesThreshold(): void
    {
        $dir = $this->tmpDir();
        $file = $dir . '/phpdup.json';
        file_put_contents($file, json_encode(['max_hole_coverage' => 0.4]));
        $config = (new ConfigLoader())->load([$dir], $file);
        $this->assertSame(0.4, $config->maxHoleCoverage);
    }

    public function testProgrammaticOverridesCarryThreshold(): void
    {
        $config = (new ConfigLoader())->load([$this->tmpDir()], null, ['max_hole_coverage' => 0.75]);
        $this->assertSame(0.75, $config->maxHoleCoverage);
    }

    public function testRawStringOverrideCoerces(): void
    {
        // The CLI hands the raw token through unvalidated (laundering
        // guard): coerceNumerics must accept a numeric string and reject
        // prose in the same seat the loader already polices for floats.
        $config = (new ConfigLoader())->load([$this->tmpDir()], null, ['max_hole_coverage' => '0.5']);
        $this->assertSame(0.5, $config->maxHoleCoverage);
    }

    public function testGarbageStringOverrideRefused(): void
    {
        // coerceNumerics posture (NumericFlagCoercionTest): a raw word on
        // the CLI token stream is refused as InvalidArgumentException —
        // Command surfaces it as exit 2, never launders it into a default.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('max_hole_coverage must be a number');
        (new ConfigLoader())->load([$this->tmpDir()], null, ['max_hole_coverage' => 'hot']);
    }

    public function testAboveOneRefusedAtValidation(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('max_hole_coverage');
        (new ConfigLoader())->validate(['max_hole_coverage' => 1.5]);
    }

    public function testNegativeRefusedAtValidation(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ConfigLoader())->validate(['max_hole_coverage' => -0.5]);
    }

    public function testNonNumericRefusedAtValidation(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ConfigLoader())->validate(['max_hole_coverage' => 'lots']);
    }

    public function testZeroIsAdmitted(): void
    {
        // 0 is the maximal-honesty setting: EVERY hole-bearing cluster is
        // annotated. The range is 0..1 inclusive at the low end on purpose.
        $config = (new ConfigLoader())->load([$this->tmpDir()], null, ['max_hole_coverage' => 0.0]);
        $this->assertSame(0.0, $config->maxHoleCoverage);
    }

    public function testConstructorFailFast(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Config(paths: ['/x'], exclude: [], maxHoleCoverage: 1.5);
    }

    public function testConstructorFailFastNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Config(paths: ['/x'], exclude: [], maxHoleCoverage: -0.1);
    }

    public function testPerDirectoryKeyRefusedLoudly(): void
    {
        $root = $this->tmpDir();
        $sub = $root . '/pkg';
        mkdir($sub);
        file_put_contents($root . '/root.php', "<?php function rootfn(\$a) { \$a->go(); }\n");
        // Valid at the root, refused in a .phpdup.json: a cluster's members
        // can live under different prefixes, so a per-dir coverage bar has
        // no single meaning — same refusal family as max_ted_size.
        file_put_contents($sub . '/.phpdup.json', json_encode(['max_hole_coverage' => 0.4]));

        try {
            (new ConfigLoader())->load([$root], null);
            $this->fail('per-directory max_hole_coverage must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('root', $e->getMessage());
            $this->assertStringContainsString('.phpdup.json', $e->getMessage());
        }
    }

    public function testWithOverridesPreservesRootThreshold(): void
    {
        // A per-dir payload WITHOUT the key must not wipe the root bar.
        $root = new Config(paths: ['/x'], exclude: [], maxHoleCoverage: 0.4);
        $effective = $root->withOverrides(['min_block_size' => 12]);
        $this->assertSame(0.4, $effective->maxHoleCoverage);
    }

    public function testBeltExcludesTheKey(): void
    {
        // shapeOverrides() must never admit it — the refusal above is the
        // enforcement; belt set stays unchanged (root-only keys are never
        // emitted, so set-equality covers them trivially).
        $loader = new ConfigLoader();
        $ref = new \ReflectionMethod(ConfigLoader::class, 'shapeOverrides');
        $out = $ref->invoke($loader, ['max_hole_coverage' => 0.4, 'min_block_size' => 9]);
        $this->assertArrayNotHasKey('max_hole_coverage', $out);
        $this->assertArrayHasKey('min_block_size', $out);
    }

    public function testCliFlagShips(): void
    {
        // Inverted grep-pin vs max_ted_size: the flag-train must LEARN this
        // option — flag + help row + overrides dict entry in Command.php.
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Cli/Command.php');
        $this->assertStringContainsString("addOption('max-hole-coverage'", $src);
        $this->assertStringContainsString("\$input->getOption('max-hole-coverage')", $src);
    }

    public function testNumericKeysRowExists(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Cli/ConfigLoader.php');
        $this->assertMatchesRegularExpression(
            "/'max_hole_coverage'\\s+=>\\s+\\['float',\\s*0\\.0,\\s*1\\.0\\]/",
            $src,
        );
    }
}
