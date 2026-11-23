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
