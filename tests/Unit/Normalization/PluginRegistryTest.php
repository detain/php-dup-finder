<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Normalization;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Normalization\NormalizationPlugin;
use Phpdup\Normalization\Normalizer;
use Phpdup\Normalization\PluginRegistry;
use Phpdup\Parsing\AstParser;

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
