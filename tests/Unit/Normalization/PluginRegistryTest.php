<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Normalization;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Command;
use Phpdup\Cli\Config;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Normalization\NormalizationPlugin;
use Phpdup\Normalization\Normalizer;
use Phpdup\Normalization\PluginRegistry;
use Phpdup\Parsing\AstParser;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

final class PluginRegistryTest extends TestCase
{
    private string $markerDir;

    protected function setUp(): void
    {
        $this->markerDir = (string) sys_get_temp_dir() . '/phpdup-s28-' . uniqid('', true);
        mkdir($this->markerDir, 0o775, true);
        SideEffectingNonPlugin::$markerPath = null;
    }

    protected function tearDown(): void
    {
        SideEffectingNonPlugin::$markerPath = null;
        if (is_file($this->markerDir . '/marker')) {
            unlink($this->markerDir . '/marker');
        }
        if (is_dir($this->markerDir)) {
            rmdir($this->markerDir);
        }
    }

    public function testFromClassNamesInstantiatesPlugins(): void
    {
        $registry = PluginRegistry::fromClassNames([UpperCaseStringsPlugin::class]);
        $this->assertCount(1, $registry->plugins());
        $this->assertInstanceOf(NormalizationPlugin::class, $registry->plugins()[0]);
    }

    public function testFromClassNamesRejectsMissingClass(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/class not found/');
        PluginRegistry::fromClassNames(['Phpdup\\Tests\\__Nope']);
    }

    public function testFromClassNamesRejectsNonImplementer(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not implement NormalizationPlugin/');
        PluginRegistry::fromClassNames([\stdClass::class]);
    }

    // ── engine-S28 (php-dup-finder-engine-021 / -022) ─────────────────────

    public function testRejectsNonPluginWithoutRunningItsConstructor(): void
    {
        $marker = $this->markerDir . '/marker';
        SideEffectingNonPlugin::$markerPath = $marker;

        try {
            PluginRegistry::fromClassNames([SideEffectingNonPlugin::class]);
            $this->fail('expected a RuntimeException for a non-plugin entry');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('does not implement NormalizationPlugin', $e->getMessage());
            $this->assertStringContainsString('SideEffectingNonPlugin', $e->getMessage());
        }

        // The engine-021 cure: the interface gate rejects the entry BEFORE
        // construction, so the argumentless side-effecting constructor must
        // never have run.
        $this->assertFileDoesNotExist(
            $marker,
            'a non-plugin entry must be rejected before `new`, without constructor side effects',
        );

        // Positive control: the marker mechanism is live — direct
        // construction does write it, so the assertion above cannot be
        // vacuously true.
        new SideEffectingNonPlugin();
        $this->assertFileExists($marker);
    }

    public function testConstructorRequiringArgumentsFailsLoudlyNamingTheClass(): void
    {
        // engine-022: previously a bare ArgumentCountError (an Error, not a
        // RuntimeException) escaped, naming nothing about the config entry.
        // If the wrap is ever removed, the raw error falls through this
        // catch(\RuntimeException) and the test errors — mutation-proof.
        try {
            PluginRegistry::fromClassNames([NeedsArgumentsPlugin::class]);
            $this->fail('expected a wrapped RuntimeException, not a bare ArgumentCountError');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString(
                "Normalization plugin '" . NeedsArgumentsPlugin::class . "' could not be constructed",
                $e->getMessage(),
            );
            $this->assertInstanceOf(\ArgumentCountError::class, $e->getPrevious());
        }
    }

    public function testUserlandConstructorThrowIsWrappedPreservingPrevious(): void
    {
        try {
            PluginRegistry::fromClassNames([ThrowingConstructorPlugin::class]);
            $this->fail('expected the userland constructor throw to be wrapped');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString(
                "Normalization plugin '" . ThrowingConstructorPlugin::class . "' could not be constructed",
                $e->getMessage(),
            );
            $previous = $e->getPrevious();
            $this->assertInstanceOf(\LogicException::class, $previous);
            $this->assertSame('plugin constructor boom', $previous?->getMessage());
        }
    }

    public function testPluginRunsAfterBuiltInPasses(): void
    {
        // once-S24 r1 F2: the old two-snippet hash-equality was VACUOUS —
        // it held even when the plugin never ran at all, because
        // canonicalizeLiterals collapses every String_ to '__STR' before
        // any consumer sees it (Normalizer.php:627-632), erasing the case
        // distinction the plugin could add. Pin the contract the name
        // claims instead: (a) the plugin's marker survives into the
        // canonical stream (hash differs from the no-plugin baseline —
        // the plugin really ran under 'default'), and (b) at plugin time
        // the literal was ALREADY '__STR' — the built-in pass ran first.
        $code = '<?php function f() { return "alpha"; }';
        MarkerAfterBuiltInPlugin::$sawStrings = [];

        $registry = PluginRegistry::fromClassNames([MarkerAfterBuiltInPlugin::class]);
        $withPlugin = $this->canonicalHash($code, 'default', $registry);
        $baseline = $this->canonicalHash($code, 'default', null);

        $this->assertNotSame(
            $baseline,
            $withPlugin,
            "the plugin's appended '__P' marker must reach the canonical stream under default mode — a never-running plugin leaves the hash exactly at the no-plugin baseline (the vacuous pre-fix pin)",
        );
        $this->assertSame(
            ['__STR'],
            MarkerAfterBuiltInPlugin::$sawStrings,
            'under default the built-in literal pass must collapse the literal to __STR before the plugin visit — built-ins first, plugin last',
        );
    }

    // ── once-S66 (php-dup-finder-once-075) — plugin screen + degrade ─────
    //
    // A throwing plugin ctor used to reach fromClassNames() inside every
    // worker (and the refactor-side BlockAstLoader reload): raw
    // RuntimeException → Symfony usage dump, rc 1, no report. The screen
    // runs ONCE parent-side; failing entries are excluded with one note
    // each and the run continues degraded. Every pin below was RED on the
    // unfixed tree (probe transcript in reviews/exec/once-S66.impl.md).

    public function testScreenClassNamesEmptyListHasNoFailures(): void
    {
        $this->assertSame([], PluginRegistry::screenClassNames([]));
    }

    public function testScreenClassNamesHealthyListProducesNoFailures(): void
    {
        $this->assertSame(
            [],
            PluginRegistry::screenClassNames([UpperCaseStringsPlugin::class]),
            'a good plugin must not draw a false exclusion note',
        );
    }

    public function testScreenClassNamesRecordsConstructorThrowReason(): void
    {
        $failures = PluginRegistry::screenClassNames([ThrowingConstructorPlugin::class]);

        $this->assertSame([ThrowingConstructorPlugin::class], array_keys($failures));
        $this->assertStringContainsString('could not be constructed', $failures[ThrowingConstructorPlugin::class]);
        $this->assertStringContainsString('plugin constructor boom', $failures[ThrowingConstructorPlugin::class]);
    }

    public function testScreenClassNamesRecordsMissingClassWithoutThrowing(): void
    {
        $failures = PluginRegistry::screenClassNames(['Phpdup\\Tests\\Unit\\Normalization\\NoSuchPluginS66']);

        $this->assertSame(['Phpdup\\Tests\\Unit\\Normalization\\NoSuchPluginS66'], array_keys($failures));
        $this->assertStringContainsString('class not found', $failures['Phpdup\\Tests\\Unit\\Normalization\\NoSuchPluginS66']);
    }

    public function testScreenGatesNonPluginBeforeConstruction(): void
    {
        // The engine-S28 no-side-effect gate must survive INTO the screen:
        // a non-plugin name is recorded as a failure WITHOUT ever reaching
        // `new`, so a hostile/argumentful ctor cannot leak load-time
        // effects at screening time.
        $marker = $this->markerDir . '/marker';
        SideEffectingNonPlugin::$markerPath = $marker;

        $failures = PluginRegistry::screenClassNames([SideEffectingNonPlugin::class]);

        $this->assertSame([SideEffectingNonPlugin::class], array_keys($failures));
        $this->assertStringContainsString('does not implement NormalizationPlugin', $failures[SideEffectingNonPlugin::class]);
        $this->assertFileDoesNotExist(
            $marker,
            'the screen must record a non-plugin from the hierarchy gate alone — never construct it',
        );

        // Positive control (S28's discipline): the marker mechanism is live.
        new SideEffectingNonPlugin();
        $this->assertFileExists($marker);
    }

    public function testScreenClassNamesMixedListKeysOnlyFailuresInOrder(): void
    {
        $failures = PluginRegistry::screenClassNames([
            UpperCaseStringsPlugin::class,
            ThrowingConstructorPlugin::class,
            'Phpdup\\Tests\\Unit\\Normalization\\NoSuchPluginS66',
        ]);

        $this->assertSame(
            [ThrowingConstructorPlugin::class, 'Phpdup\\Tests\\Unit\\Normalization\\NoSuchPluginS66'],
            array_keys($failures),
            'healthy entries are absent from the failure map; failing ones keep config order',
        );
    }

    public function testScreenRejectsMalformedEntriesAsAdmissionFailure(): void
    {
        // Non-string entries stay THROWING (config-boundary admission, the
        // same door fromClassNames guards bare) — they are not degradeable
        // plugin failures.
        foreach ([[''], [42]] as $case) {
            try {
                PluginRegistry::screenClassNames($case);
                $this->fail('expected a RuntimeException for malformed entry ' . json_encode($case));
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('non-empty strings', $e->getMessage());
            }
        }
    }

    public function testExclusionNotesLandOnScanNotesChannel(): void
    {
        // once-S37 skip-note channel, plugin half: notes handed to the
        // stage append to PipelineState::$scanNotes AFTER the
        // ScanningStage snapshot, order preserved, exactly once per note.
        $dir = (string) sys_get_temp_dir() . '/phpdup-s66-ch-' . uniqid('', true);
        mkdir($dir . '/src', 0o775, true);
        file_put_contents($dir . '/src/a.php', "<?php\nfunction s66chan() { return 1; }\n");

        try {
            $config = new Config(
                paths: [$dir . '/src'],
                exclude: Config::defaults([])->exclude,
            );
            $state = new PipelineState($config);
            $state->files = [$dir . '/src/a.php'];
            $state->scanNotes = ['pre-existing scanner snapshot note'];

            $stage = new PreprocessStage(
                useCache: false,
                showStats: false,
                listener: null,
                maxMemoryMb: 0,
                pluginExclusionNotes: [
                    "normalization plugin 'Boom\\Plugin' excluded (run degraded): could not be constructed: plugin constructor boom",
                    "normalization plugin 'Gone\\Plugin' excluded (run degraded): class not found",
                ],
            );
            $stage->run($state, new BufferedOutput());

            $this->assertSame([
                'pre-existing scanner snapshot note',
                "normalization plugin 'Boom\\Plugin' excluded (run degraded): could not be constructed: plugin constructor boom",
                "normalization plugin 'Gone\\Plugin' excluded (run degraded): class not found",
            ], $state->scanNotes);
        } finally {
            $this->removeDirTree($dir);
        }
    }

    public function testThrowingPluginDegradesEndToEndSingleWarningNoUsageDump(): void
    {
        // Full acceptance: throwing-ctor plugin → single `phpdup:` warning,
        // plugin excluded, report still produced (with a signature —
        // proving the refactor-side BlockAstLoader path ran on the repaired
        // list, engine-S28's gate never re-reached by a dead name), and NO
        // Symfony usage dump. --workers 1 pins the serial-parent wire; the
        // forked path shares the identical screened config (upstream copy).
        $dir = (string) sys_get_temp_dir() . '/phpdup-s66-e2e-' . uniqid('', true);
        mkdir($dir . '/src', 0o775, true);
        mkdir($dir . '/cache', 0o775, true);
        $dup = "<?php\nfunction probe_alpha(\$v) {\n\t\$a = 1;\n\t\$b = \$a + \$v;\n\t\$c = \$b * 2;\n\t\$d = \$c - 4;\n\t\$e = \$d / 2;\n\treturn \$e;\n}\n";
        file_put_contents($dir . '/src/a.php', $dup);
        file_put_contents($dir . '/src/b.php', str_replace('probe_alpha', 'probe_beta', $dup));
        file_put_contents($dir . '/src/c.php', "<?php\nfunction probe_gamma(\$v) {\n\treturn 9 * \$v - 17;\n}\n");
        $cfg = $dir . '/cfg.json';
        file_put_contents($cfg, json_encode([
            'cache_dir'           => $dir . '/cache',
            'min_block_size'      => 4,
            'min_cluster_impact'  => 0,
            'normalization'       => ['plugins' => [
                UpperCaseStringsPlugin::class,
                ThrowingConstructorPlugin::class,
            ]],
        ], JSON_PRETTY_PRINT) . "\n");
        $json = $dir . '/r.json';

        try {
            $app    = new Application('phpdup');
            $app->add(new Command());
            $tester = new CommandTester($app->find('analyze'));

            $exit = $tester->execute([
                'paths'    => [$dir . '/src'],
                '--config' => $cfg,
                '--workers' => '1',
                '--json'   => $json,
            ]);

            $display = $tester->getDisplay();
            $this->assertSame(0, $exit, 'degraded-but-produced run is honestly exit 0; display was: ' . $display);

            $this->assertSame(
                1,
                substr_count($display, ThrowingConstructorPlugin::class),
                "exactly ONE warning line naming the excluded plugin — never per-file/per-worker spam; display was:\n" . $display,
            );
            $this->assertStringContainsString(
                "phpdup: warning: normalization plugin '" . ThrowingConstructorPlugin::class . "' excluded (run degraded): could not be constructed: plugin constructor boom",
                $display,
            );

            // The defect class: Symfony's failure render — exception block +
            // full flag dump line. Must be entirely absent.
            $this->assertStringNotContainsString('In PluginRegistry.php line', $display, 'no raw registry crash');
            $this->assertStringNotContainsString('analyze [-c|--config CONFIG]', $display, 'no usage dump');

            // Report still produced, on the surviving plugin list, through
            // RefactorStage (signature) to the JSON carrier.
            $this->assertFileExists($json, 'the degraded run still writes the report');
            $report = json_decode((string) file_get_contents($json), true);
            $this->assertIsArray($report);
            $this->assertNotEmpty($report['clusters'] ?? [], 'the clone pair still clusters');
            $this->assertMatchesRegularExpression(
                '/\S/',
                (string) ($report['clusters'][0]['signature'] ?? ''),
                'RefactorStage ran to signature build — the refactor-side path never saw the excluded name',
            );
        } finally {
            $this->removeDirTree($dir);
        }
    }

    // ── once-S66 fix1 (r1 M-1) — degraded-run cache identity ──────────────
    //
    // The b5fe197 +233 pins cover the screen arms, the notes channel and
    // the e2e degrade, but nothing asserts that a degraded run SERVES and
    // RECORDS under the effective-roster store key. The safety argument is
    // real (r1 §(b) craft proof: repair precedes PipelineState
    // construction; IndexStore's per-file config_key binds ->normalization
    // plugins at PreprocessStage:424) — yet a future refactor that splits
    // the recording key from the repaired roster would silently contaminate
    // a warm shared cache while every pre-existing pin stayed green. This
    // pin closes that gap tests-only.

    public function testDegradedRunRecordsUnderEffectiveRosterCacheKey(): void
    {
        $dir = (string) sys_get_temp_dir() . '/phpdup-s66f1-' . uniqid('', true);
        mkdir($dir . '/src', 0o775, true);
        mkdir($dir . '/cache-shared', 0o775, true);
        mkdir($dir . '/cache-pluginless', 0o775, true);
        $dup = "<?php\nfunction probe_alpha(\$v) {\n\t\$a = 1;\n\t\$b = \$a + \$v;\n\t\$c = \$b * 2;\n\t\$d = \$c - 4;\n\t\$e = \$d / 2;\n\treturn \$e;\n}\n";
        file_put_contents($dir . '/src/a.php', $dup);
        file_put_contents($dir . '/src/b.php', str_replace('probe_alpha', 'probe_beta', $dup));
        file_put_contents($dir . '/src/c.php', "<?php\nfunction probe_gamma(\$v) {\n\treturn 9 * \$v - 17;\n}\n");

        try {
            // Run 1: the degraded [G,B] roster against the shared cache dir.
            // The screen must reduce it to [G] BEFORE the store key is
            // derived, so every .idx header written lands under the roster-
            // [G] config_key.
            $cfgDegraded = $this->writeS66F1Config(
                $dir,
                'cfg-degraded.json',
                $dir . '/cache-shared',
                [UpperCaseStringsPlugin::class, ThrowingConstructorPlugin::class],
            );
            [$exit1, $display1] = $this->runS66F1Analyze($cfgDegraded, $dir);
            $this->assertSame(0, $exit1, 'degraded run must exit 0; display was: ' . $display1);
            $this->assertSame(
                1,
                substr_count($display1, ThrowingConstructorPlugin::class),
                'run 1 must really be the degraded one (exactly one exclusion warning); display was: ' . $display1,
            );
            $afterDegraded = $this->s66F1ConfigKeyHeaders($dir . '/cache-shared');
            $this->assertNotEmpty(
                $afterDegraded,
                'the degraded run must actually write per-file .idx headers — an empty census would make every comparison below vacuous',
            );

            // Run 2: clean control on the EFFECTIVE roster [G], same shared
            // cache dir. Correctness leaves the store untouched by a
            // re-key: the control hits the degraded run's entries (or
            // rewrites them byte-equal), so the census cannot move.
            $cfgControl = $this->writeS66F1Config(
                $dir,
                'cfg-control.json',
                $dir . '/cache-shared',
                [UpperCaseStringsPlugin::class],
            );
            [$exit2, $display2] = $this->runS66F1Analyze($cfgControl, $dir);
            $this->assertSame(0, $exit2, 'clean roster-[G] control must exit 0; display was: ' . $display2);
            $this->assertStringNotContainsString(
                'phpdup: warning:',
                $display2,
                'the roster-[G] control runs clean — no exclusion, no warning; display was: ' . $display2,
            );
            $afterControl = $this->s66F1ConfigKeyHeaders($dir . '/cache-shared');

            // The pin (r1 M-1 discriminating bar): if the degraded run ever
            // records under the UNSCREENED [G,B] roster key, this control
            // misses on its own key and rewrites the headers — the two
            // censuses then differ and the assert goes RED. Demonstrated by
            // neuter M-A (split key/roster; transcript in
            // reviews/exec/once-S66.impl.md Fix-round-1).
            $this->assertSame(
                $afterDegraded,
                $afterControl,
                'a degraded [G,B] run must serve/record under the effective-roster [G] config_key — the shared store may not be re-keyed by the clean [G] control run',
            );

            // Vacuity fence: the plugin-free roster must mint a DIFFERENT
            // key for the very same files. Without this leg a roster-blind
            // (constant or empty) config_key would satisfy the equality
            // above vacuously.
            $cfgPluginless = $this->writeS66F1Config($dir, 'cfg-pluginless.json', $dir . '/cache-pluginless', []);
            [$exit3, $display3] = $this->runS66F1Analyze($cfgPluginless, $dir);
            $this->assertSame(0, $exit3, 'plugin-free control must exit 0; display was: ' . $display3);
            $pluginless = $this->s66F1ConfigKeyHeaders($dir . '/cache-pluginless');
            $this->assertSame(
                array_keys($afterDegraded),
                array_keys($pluginless),
                'the plugin-free census must cover exactly the same per-file stores (same corpus)',
            );
            foreach ($afterDegraded as $idxName => $key) {
                $this->assertNotSame(
                    $key,
                    $pluginless[$idxName],
                    "the per-file config_key must MOVE with the normalization roster (store '$idxName' carries the same key with and without plugins — the binding is roster-blind and the [G] equality above is vacuous)",
                );
            }
        } finally {
            $this->removeDirTree($dir);
        }
    }

    // ── once-S66 fix1 (r1 L-1 fold) — reflection-copy seam guard ──────────

    public function testEveryConfigCtorParamHasSameNamedPublicProperty(): void
    {
        // Command::withNormalizationPlugins() (the once-S66 repair seam)
        // rebuilds Config by reflection, reading `$config->{$name}` for
        // EVERY ctor param. The seam silently trusts each param to be a
        // public promoted property (true, 50/50, at b5fe197 — r1 §L-1). A
        // future non-promoted, renamed or private param would surface as a
        // raw TypeError OUTSIDE the screened arms — exactly the crash class
        // this step exists to close. One loop, so the drift fails in CI
        // instead of in the field.
        $reflection = new \ReflectionClass(Config::class);
        $ctor = $reflection->getConstructor();
        $this->assertNotNull($ctor, 'Config must keep a constructor for the reflection-copy seam');

        $publicProperties = [];
        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $publicProperties[] = $property->getName();
        }

        foreach ($ctor->getParameters() as $param) {
            $this->assertContains(
                $param->getName(),
                $publicProperties,
                "Config ctor param \${$param->getName()} has no same-named public property — Command::withNormalizationPlugins()'s reflection copy would throw a raw TypeError when it degrades a run (r1 L-1 guard)",
            );
        }
    }

    /** @param list<string> $plugins */
    private function writeS66F1Config(string $dir, string $name, string $cacheDir, array $plugins): string
    {
        $payload = [
            'cache_dir'          => $cacheDir,
            'min_block_size'     => 4,
            'min_cluster_impact' => 0,
        ];
        if ($plugins !== []) {
            $payload['normalization'] = ['plugins' => $plugins];
        }
        $path = $dir . '/' . $name;
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT) . "\n");

        return $path;
    }

    /** @return array{int, string} exit code + display of one serial-wire analyze run */
    private function runS66F1Analyze(string $configPath, string $dir): array
    {
        $app    = new Application('phpdup');
        $app->add(new Command());
        $tester = new CommandTester($app->find('analyze'));
        $exit   = $tester->execute([
            'paths'     => [$dir . '/src'],
            '--config'  => $configPath,
            '--workers' => '1',
        ]);

        return [$exit, $tester->getDisplay()];
    }

    /**
     * Census of the per-file .idx config_key headers in a cache dir — the
     * bounded header read mirrors IndexStore's own gate (per-file stores
     * only: clusters.idx shares its key across rosters by design, plugin
     * separation there rides the corpus-hash gate, r1 §(b)).
     *
     * @return array<string, string> store basename => 40-hex config_key
     */
    private function s66F1ConfigKeyHeaders(string $cacheDir): array
    {
        $keys = [];
        foreach (scandir($cacheDir) ?: [] as $entry) {
            if ($entry === 'clusters.idx' || !str_ends_with($entry, '.idx')) {
                continue;
            }
            $head = (string) file_get_contents($cacheDir . '/' . $entry, false, null, 0, 512);
            if (!preg_match('/^config_key:([0-9a-f]{40})$/m', $head, $m)) {
                $this->fail("per-file index store '$entry' carries no config_key: header in its first 512 bytes");
            }
            $keys[$entry] = $m[1];
        }
        ksort($keys);

        return $keys;
    }

    private function removeDirTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirTree($path);
                @rmdir($path);
                continue;
            }
            @unlink($path);
        }
        @rmdir($dir);
    }

    private function canonicalHash(string $code, string $mode, ?PluginRegistry $reg): string
    {
        $parser = new AstParser();
        $stmts = $parser->parseCode($code);
        $extractor = new BlockExtractor(minSize: 1);
        $blocks = $extractor->extract('test.php', $stmts);
        $this->assertNotEmpty($blocks);
        (new Normalizer($mode, $reg))->normalize($blocks[0]);
        return (new SubtreeHasher())->hash($blocks[0]->canonical);
    }
}

/** @internal test fixture */
final class UpperCaseStringsPlugin implements NormalizationPlugin
{
    public function visit(Node $node, string $mode): void
    {
        if ($node instanceof Node\Scalar\String_) {
            $node->value = strtoupper($node->value);
        }
    }
}

/**
 * @internal test fixture — once-S24 r1 F2 discriminator: records every
 * String_ value it is handed (proving WHICH state the built-ins left it
 * in) and appends a '__P' marker that survives to the canonical hash
 * (proving the plugin ran at all — case-only mutation is invisible once
 * literal canonicalisation has collapsed the value).
 */
final class MarkerAfterBuiltInPlugin implements NormalizationPlugin
{
    /** @var list<string> values of every String_ node at plugin time */
    public static array $sawStrings = [];

    public function visit(Node $node, string $mode): void
    {
        if ($node instanceof Node\Scalar\String_) {
            self::$sawStrings[] = (string) $node->value;
            $node->value .= '__P';
        }
    }
}

/**
 * @internal test fixture — engine-021 sentinel: an autoloadable,
 * argumentless class that is NOT a plugin and records every
 * construction in a marker file. If PluginRegistry ever constructs
 * before gating, the marker appears and the pin fails.
 */
final class SideEffectingNonPlugin
{
    public static ?string $markerPath = null;

    public function __construct()
    {
        if (self::$markerPath !== null) {
            file_put_contents(self::$markerPath, 'constructed');
        }
    }
}

/**
 * @internal test fixture — engine-022: a real plugin whose constructor
 * requires arguments; constructing it bare raises ArgumentCountError,
 * which the registry must wrap and label.
 */
final class NeedsArgumentsPlugin implements NormalizationPlugin
{
    public function __construct(private readonly string $prefix)
    {
    }

    public function visit(Node $node, string $mode): void
    {
    }
}

/**
 * @internal test fixture — engine-022: a plugin that fails inside its
 * constructor; the userland throw must surface wrapped, with the
 * original preserved as previous.
 */
final class ThrowingConstructorPlugin implements NormalizationPlugin
{
    public function __construct()
    {
        throw new \LogicException('plugin constructor boom');
    }

    public function visit(Node $node, string $mode): void
    {
    }
}
