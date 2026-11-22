<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\AutoTuner;
use Phpdup\Cli\Command;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

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
        // No-context contract (once-S26): a direct caller that hands the
        // tuner no effective values gets the RAW tier table — every tier's
        // full intent, byte-identical rationale to the pre-once-S26 text.
        // Gap-filtering only starts once the CLI passes context below.
        $sug = (new AutoTuner())->pick(5_000, 50_000_000);
        $this->assertSame('medium', $sug->profile);
        $this->assertSame(8, $sug->overrides['min_block_size']);
        $this->assertSame(0.005, $sug->overrides['max_df']);
        $this->assertArrayNotHasKey('exact_only', $sug->overrides);
        $this->assertSame(
            'medium corpus (5000 files): tightening max_df=0.005 (n-gram uniqueness scales with corpus size) and min_block_size=8.',
            $sug->rationale,
        );
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

    public function testRationaleStringIsPopulated(): void
    {
        $sug = (new AutoTuner())->pick(100, 1_000);
        $this->assertNotSame('', $sug->rationale);
        $this->assertStringContainsString('tiny', $sug->rationale);
    }

    // ── once-S26 (php-dup-finder-once-030): read the effective config,
    //    never override user values, narrate no no-ops ────────────────────

    public function testMediumSilencesIntrinsicEightToEightNoOp(): void
    {
        // PD-CFG-12: the medium tier suggested min_block_size=8 against the
        // stock default of 8 — an "8→8" claim that was never a change. With
        // the effective values handed in, the no-op entry drops from the
        // overrides AND the rationale; only the real tightening survives.
        $sug = (new AutoTuner())->pick(
            5_000,
            0,
            ['min_block_size' => 8, 'min_cluster_impact' => 20, 'max_df' => 0.01],
        );
        $this->assertSame('medium', $sug->profile);
        $this->assertSame(['max_df' => 0.005], $sug->overrides, 'the 8→8 entry is gone from the suggestion');
        $this->assertStringNotContainsString('min_block_size', $sug->rationale, 'and from the narration: ' . $sug->rationale);
        $this->assertStringContainsString('tightening max_df=0.005 (n-gram uniqueness scales with corpus size).', $sug->rationale);
    }

    public function testExplicitMinBlockSizeIsNeverOverwritten(): void
    {
        // Acceptance "explicit min_block_size stays": a locked knob drops
        // from overrides entirely, appears as a truthful kept-clause, and
        // the tier never claims its own value for it.
        $sug = (new AutoTuner())->pick(
            50,
            0,
            ['min_block_size' => 12, 'min_cluster_impact' => 20, 'max_df' => 0.01],
            ['min_block_size'],
        );
        $this->assertSame('tiny', $sug->profile);
        $this->assertArrayNotHasKey('min_block_size', $sug->overrides, 'the tuner must not touch an explicitly-set knob');
        $this->assertSame(['min_cluster_impact' => 0, 'max_df' => 0.5], $sug->overrides);
        $this->assertStringContainsString('relaxing min_impact=0, max_df=0.5 so small/fixture trees report findings instead of empty output', $sug->rationale);
        $this->assertStringContainsString('min_block_size=12 kept (explicitly set)', $sug->rationale);
        $this->assertStringNotContainsString('min_block_size=4', $sug->rationale, 'the narration must not claim the value it was refused: ' . $sug->rationale);
    }

    public function testRawCliStringKnobCountsAsExplicit(): void
    {
        // parseCliOverrides stores uncoerced strings; the locked side must
        // treat ' 4 ' as explicit and show it trimmed, never as a float.
        $sug = (new AutoTuner())->pick(
            50,
            0,
            ['min_block_size' => ' 4 ', 'min_cluster_impact' => 20, 'max_df' => 0.01],
            ['min_block_size'],
        );
        $this->assertArrayNotHasKey('min_block_size', $sug->overrides);
        $this->assertStringContainsString('min_block_size=4 kept (explicitly set)', $sug->rationale);
    }

    public function testLockedLargeTierKeepsOnlyExactOnly(): void
    {
        // exact_only is not a config-file knob (validate() has no such key)
        // and rides the disclosed "forced on for very large trees" stance,
        // so it is the only survivor when both numeric tiers are locked.
        $sug = (new AutoTuner())->pick(
            50_000,
            0,
            ['min_block_size' => 10, 'min_cluster_impact' => 20, 'max_df' => 0.004],
            ['min_block_size', 'max_df'],
        );
        $this->assertSame('large', $sug->profile);
        $this->assertSame(['exact_only' => true], $sug->overrides);
        $this->assertStringContainsString('exact-only to keep memory in check', $sug->rationale);
        $this->assertStringContainsString('min_block_size=10 kept (explicitly set)', $sug->rationale);
        $this->assertStringContainsString('max_df=0.004 kept (explicitly set)', $sug->rationale);
    }

    public function testAllIntentLockedNarratesIdleNotTightening(): void
    {
        $sug = (new AutoTuner())->pick(
            5_000,
            0,
            ['min_block_size' => 5, 'max_df' => 0.005, 'min_cluster_impact' => 20],
            ['min_block_size', 'max_df'],
        );
        $this->assertSame([], $sug->overrides);
        $this->assertStringContainsString('nothing to tighten', $sug->rationale);
        $this->assertStringNotContainsString('tightening', $sug->rationale);
        $this->assertStringContainsString('min_block_size=5 kept (explicitly set), max_df=0.005 kept (explicitly set)', $sug->rationale);
    }

    public function testTuneForwardsEffectiveContextToTheBand(): void
    {
        // tune() is the counting seam; prove the context rides through it,
        // not just pick().
        $dir = $this->scratchTunerTree(5, 0);
        try {
            $sug = (new AutoTuner())->tune(
                [$dir],
                [],
                ['min_block_size' => 4, 'min_cluster_impact' => 20, 'max_df' => 0.01],
                ['min_block_size'],
            );
            $this->assertSame('tiny', $sug->profile);
            $this->assertSame(['min_cluster_impact' => 0, 'max_df' => 0.5], $sug->overrides);
            $this->assertStringContainsString('min_block_size=4 kept (explicitly set)', $sug->rationale);
        } finally {
            self::deleteTree($dir);
        }
    }

    public function testAutoTuneSeesConfigExcludesAndKeepsExplicitKnob(): void
    {
        // Command-level pin for the once-S26 legs (same discipline as
        // shell-S25's profile pin): a 197+4-file straddle around the tiny
        // band, where the 4-file skipped/ tree is excluded ONLY by the
        // root --config file. WITH the config the probe must read
        // "tiny corpus (197 files)"; the control run (no config) must read
        // "small corpus (201 files)" — non-vacuity: the excludes really
        // flip the band (PD-CFG-11's blind census is the mutant shape).
        // The config's explicit min_block_size=5 must never be rewritten
        // by the tiny tier's 4 (COPY-05's inversion was the old posture).
        $dir  = $this->scratchDir('s26-cfg');
        $corpus = $this->mintConfigStraddleTree($dir . '/corpus', 197, 4);
        $cfg    = $dir . '/phpdup.json';
        file_put_contents($cfg, json_encode([
            'exclude'        => ['skipped/**', '**/skipped/**'],
            'min_block_size' => 5,
        ], JSON_THROW_ON_ERROR));
        try {
            [$exit, $display] = $this->analyzeWithAutoTune($corpus, $cfg, $dir . '/tuned.json');
            $this->assertSame(0, $exit, 'the config-straddle run is a normal run: ' . $display);
            $this->assertStringContainsString('auto-tune: tiny corpus (197 files)', $display,
                'RED if resolveAutoTune drops the config exclude layer — the band must never count the skipped tree');
            $this->assertStringContainsString('min_block_size=5 kept (explicitly set)', $display,
                'the config-explicit knob is protected AND honestly narrated');
            $this->assertStringNotContainsString('min_block_size=4', $display,
                'the refused tiny-tier value must not appear in the user line');

            // Control arm: same tree, no config — 201 counted, band flips.
            [, $control] = $this->analyzeWithAutoTune($corpus, null, $dir . '/control.json');
            $this->assertStringContainsString('auto-tune: small corpus (201 files)', $control,
                'the straddle exists: without the config excludes skipped/ counts and the band flips');
        } finally {
            self::deleteTree($dir);
        }
    }

    public function testResolveAutoTuneMergeFillsGapsOnly(): void
    {
        // The seam contract, asserted directly: CLI/profile keys live in
        // $overrides, config-file keys are read from disk — the merged
        // result may only ADD what neither source named.
        $dir    = $this->scratchDir('s26-merge');
        $corpus = $this->mintConfigStraddleTree($dir . '/corpus', 197, 4);
        $cfg    = $dir . '/phpdup.json';
        file_put_contents($cfg, json_encode([
            'exclude' => ['skipped/**', '**/skipped/**'],
            'min_block_size' => 5,
        ], JSON_THROW_ON_ERROR));
        try {
            $cmd    = new Command();
            $input  = new ArrayInput(
                ['paths' => [$corpus], '--config' => $cfg, '--auto-tune' => true],
                $cmd->getDefinition(),
            );
            $resolve = new \ReflectionMethod($cmd, 'resolveAutoTune');

            $overrides = ['min_cluster_impact' => '25']; // as parseCliOverrides leaves a raw CLI string
            $exact     = $resolve->invokeArgs($cmd, [$input, new BufferedOutput(), [$corpus], &$overrides, null]);

            $this->assertFalse($exact, '197 files after config excludes is a tiny band — exact_only is only a large-tier lever');
            $this->assertSame(
                ['min_cluster_impact' => '25', 'max_df' => 0.5],
                $overrides,
                'the tiny tier filled max_df only: min_block_size is config-explicit, min_cluster_impact CLI-explicit',
            );
        } finally {
            self::deleteTree($dir);
        }
    }

    public function testAutoTuneBandsTheConfigPathsTreeNotTheCliArg(): void
    {
        // once-S26 fix-round-1 (r1 MEDIUM-2): pin the config-"paths" mirror
        // arm of resolveAutoTune (Command.php:711-720). It replaces the CLI
        // argument with the root config's "paths" for the band census —
        // keep-in-step with ConfigLoader::load()'s path resolution (:217):
        // a config-paths run scans the CONFIG tree, never the CLI argument,
        // so only the config tree's file count may set the band. Before
        // this pin the arm had ZERO coverage (reviewer mutant M6 neutered
        // it and the whole Unit suite stayed green while the probe banded
        // the wrong tree — the unwatched-mirror class shell-S25 r1 named).
        //
        // Straddle around the real tiny/small boundary (tinyMax=200): the
        // CLI argument is a 197-file tree (tiny — tier fills three knobs),
        // the config "paths" tree is 201 files (small — tier fills none).
        // Correct posture: the small band on 201 AND empty overrides. The
        // neutered posture lands on the never-scanned CLI tree: wrong band
        // word, wrong count, three bogus gap-fills.
        $dir     = $this->scratchDir('s26-paths');
        $cliArg  = $this->mintConfigStraddleTree($dir . '/cli-arg', 197, 0);   // 197 files -> tiny band
        $cfgTree = $this->mintConfigStraddleTree($dir . '/cfg-tree', 197, 4);  // 201 files -> small band
        $cfg     = $dir . '/phpdup.json';
        file_put_contents($cfg, json_encode(['paths' => [$cfgTree]], JSON_THROW_ON_ERROR));
        $cfgNoPaths = $dir . '/no-paths.json';
        file_put_contents($cfgNoPaths, '{}');

        try {
            $cmd     = new Command();
            $resolve = new \ReflectionMethod($cmd, 'resolveAutoTune');

            $input = new ArrayInput(
                ['paths' => [$cliArg], '--config' => $cfg, '--auto-tune' => true],
                $cmd->getDefinition(),
            );
            $out       = new BufferedOutput();
            $overrides = [];
            $resolve->invokeArgs($cmd, [$input, $out, [$cliArg], &$overrides, null]);
            $display = $out->fetch();

            $this->assertStringContainsString('auto-tune: small corpus (201 files)', $display,
                'RED if the config-"paths" mirror arm is neutered: the census must follow the tree the scan will actually read');
            $this->assertStringNotContainsString('197 files', $display,
                'the never-scanned CLI-argument tree must not set the band');
            $this->assertSame([], $overrides,
                'the small tier keeps defaults — any tiny-tier fill here means the probe read the wrong tree');

            // Control arm: same CLI input, config WITHOUT a "paths" key —
            // the mirror stands down and the probe falls back to the CLI
            // argument (non-vacuity: proves WHICH tree each posture counts).
            $input2 = new ArrayInput(
                ['paths' => [$cliArg], '--config' => $cfgNoPaths, '--auto-tune' => true],
                $cmd->getDefinition(),
            );
            $out2        = new BufferedOutput();
            $overrides2  = [];
            $resolve->invokeArgs($cmd, [$input2, $out2, [$cliArg], &$overrides2, null]);
            $display2 = $out2->fetch();

            $this->assertStringContainsString('auto-tune: tiny corpus (197 files)', $display2,
                'without a config "paths" key the probe counts the CLI argument as always');
            $this->assertArrayHasKey('max_df', $overrides2,
                'the tiny tier fills gaps on the CLI-arg tree in the control posture');
        } finally {
            self::deleteTree($dir);
        }
    }

    private function analyzeTester(): CommandTester
    {
        $app = new Application('phpdup', '0.1.0');
        $app->add(new Command());

        return new CommandTester($app->find('analyze'));
    }

    private function scratchDir(string $tag): string
    {
        $dir = sys_get_temp_dir() . '/phpdup-' . $tag . '-' . getmypid() . '-' . uniqid('', true);
        mkdir($dir, 0o777, true);

        return $dir;
    }

    /**
     * @return array{0:int,1:string} [exit, display]
     */
    private function analyzeWithAutoTune(string $corpus, ?string $configPath, string $jsonPath): array
    {
        $tester = $this->analyzeTester();
        $exit = $tester->execute(array_filter([
            'paths'       => [$corpus],
            '--config'    => $configPath,
            '--auto-tune' => true,
            '--json'      => $jsonPath,
            '--no-cache'  => true,
        ], static fn($v) => $v !== null));

        return [$exit, $tester->getDisplay()];
    }

    /**
     * Tiny-band straddle: $rootFiles plain files at the corpus root (counted
     * by every posture) plus a skipped/-style tree that ONLY the root
     * --config exclude removes — stock defaults and every bundled profile
     * keep it.
     */
    private function mintConfigStraddleTree(string $dir, int $rootFiles, int $skippedFiles): string
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        for ($i = 0; $i < $rootFiles; $i++) {
            file_put_contents($dir . "/s26_util_$i.php", "<?php\nfunction s26_util_$i(int \$n): int { return \$n + $i; }\n");
        }
        if ($skippedFiles > 0) {
            mkdir($dir . '/skipped', 0o777, true);
            for ($i = 0; $i < $skippedFiles; $i++) {
                file_put_contents($dir . "/skipped/s26_skip_$i.php", "<?php\nfunction s26_skip_$i(int \$n): int { return \$n + $i; }\n");
            }
        }

        return $dir;
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
}
