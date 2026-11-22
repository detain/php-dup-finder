<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\AutoTuner;

final class AutoTunerTest extends TestCase
{
    public function testTinyCorpusGetsRelaxedThresholds(): void
    {
        $sug = (new AutoTuner())->pick(50, 100_000);
        $this->assertSame('tiny', $sug->profile);
        $this->assertSame(4, $sug->overrides['min_block_size']);
        $this->assertSame(0, $sug->overrides['min_cluster_impact']);
        $this->assertSame(0.5, $sug->overrides['max_df']);
        $this->assertArrayNotHasKey('exact_only', $sug->overrides);
    }

    public function testSmallCorpusKeepsDefaults(): void
    {
        $sug = (new AutoTuner())->pick(500, 5_000_000);
        $this->assertSame('small', $sug->profile);
        $this->assertSame([], $sug->overrides);
    }

    public function testMediumCorpusGetsTighterMaxDf(): void
    {
        $sug = (new AutoTuner())->pick(5_000, 50_000_000);
        $this->assertSame('medium', $sug->profile);
        $this->assertSame(8, $sug->overrides['min_block_size']);
        $this->assertSame(0.005, $sug->overrides['max_df']);
        $this->assertArrayNotHasKey('exact_only', $sug->overrides);
    }

    public function testLargeCorpusForcesExactOnly(): void
    {
        $sug = (new AutoTuner())->pick(50_000, 500_000_000);
        $this->assertSame('large', $sug->profile);
        $this->assertTrue($sug->overrides['exact_only']);
        $this->assertSame(12, $sug->overrides['min_block_size']);
        $this->assertSame(0.002, $sug->overrides['max_df']);
    }

    public function testTuneWalksFilesystemAndCountsFiles(): void
    {
        $fixtureDir = __DIR__ . '/../../Fixtures';
        $sug = (new AutoTuner())->tune([$fixtureDir], []);
        $this->assertGreaterThan(0, $sug->files);
        // shell-S25 (php-dup-finder-shell-043): the probe is counting-only —
        // no per-file stat() on top of the traversal, so bytes is never
        // sampled from tune(). Was: assertGreaterThan(0, $sug->bytes).
        $this->assertSame(0, $sug->bytes, 'stat budget: a counting-only probe reports no bytes');
        $this->assertContains($sug->profile, ['tiny', 'small', 'medium', 'large']);
    }

    public function testProbeHonorsProfileExcludesAndFlipsExactOnly(): void
    {
        // Laravel-style corpus: 14 app files + a 4-file vendor/ clone under
        // a medium/large straddle (mediumMax=16). Counted WITHOUT the
        // excluded tree the probe lands on "medium" (tier scoring intact);
        // counted WITH it, 18 files read "large" and flip exact_only —
        // the silent near-dup kill shell-043 describes.
        $dir = $this->scratchTunerTree(14, 4);
        try {
            $tuner = new AutoTuner(smallMax: 12, mediumMax: 16, tinyMax: 4);

            // Control arm: no excludes — the vendor clone inflates the band.
            $inflated = $tuner->tune([$dir], []);
            $this->assertSame('large', $inflated->profile, 'without profile excludes the straddle reads large: ' . $inflated->files . ' files counted');
            $this->assertSame(18, $inflated->files);
            $this->assertArrayHasKey('exact_only', $inflated->overrides);

            // Fixed arm: the profile's vendor globs ride the probe.
            $sug = $tuner->tune([$dir], ['vendor/**', '**/vendor/**']);
            $this->assertSame('medium', $sug->profile, 'profile-excluded trees stay out of the band: ' . $sug->files . ' files counted');
            $this->assertSame(14, $sug->files);
            $this->assertArrayNotHasKey('exact_only', $sug->overrides, 'exact_only flips back off once the probe sees the excludes');
        } finally {
            self::deleteTree($dir);
        }
    }

    private function scratchTunerTree(int $appFiles, int $vendorFiles): string
    {
        $dir = sys_get_temp_dir() . '/phpdup-tuner-' . getmypid() . '-' . uniqid('', true);
        mkdir($dir, 0o777, true);
        for ($i = 0; $i < $appFiles; $i++) {
            file_put_contents($dir . "/app_$i.php", "<?php\nfunction app_util_$i(int \$n): int { return \$n + $i; }\n");
        }
        if ($vendorFiles > 0) {
            mkdir($dir . '/vendor', 0o777, true);
            for ($i = 0; $i < $vendorFiles; $i++) {
                file_put_contents($dir . "/vendor/pkg_$i.php", "<?php\nfunction vendor_util_$i(int \$n): int { return \$n + $i; }\n");
            }
        }

        return $dir;
    }

    private static function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    public function testRationaleStringIsPopulated(): void
    {
        $sug = (new AutoTuner())->pick(100, 1_000);
        $this->assertNotSame('', $sug->rationale);
        $this->assertStringContainsString('tiny', $sug->rationale);
    }
}
