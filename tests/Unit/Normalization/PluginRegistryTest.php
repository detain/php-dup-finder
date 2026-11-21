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
        // Two source snippets that differ only by string literal value.
        // In default mode, built-in literal canonicalisation already
        // collapses both to '__STR'. The plugin appends '__P' to every
        // string value as a marker — both should still hash identically.
        $a = '<?php function f() { return "alpha"; }';
        $b = '<?php function g() { return "omega"; }';

        $registry = PluginRegistry::fromClassNames([UpperCaseStringsPlugin::class]);
        $this->assertSame(
            $this->canonicalHash($a, 'default', $registry),
            $this->canonicalHash($b, 'default', $registry),
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
