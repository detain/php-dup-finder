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
        $this->assertGreaterThan(0, $sug->bytes);
        $this->assertContains($sug->profile, ['tiny', 'small', 'medium', 'large']);
    }

    public function testRationaleStringIsPopulated(): void
    {
        $sug = (new AutoTuner())->pick(100, 1_000);
        $this->assertNotSame('', $sug->rationale);
        $this->assertStringContainsString('tiny', $sug->rationale);
    }
}
