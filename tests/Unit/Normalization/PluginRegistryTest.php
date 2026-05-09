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
