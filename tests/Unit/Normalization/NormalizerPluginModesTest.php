<?php

declare(strict_types=1);

namespace Phpdup\Tests\Unit\Normalization;

use PhpParser\Node;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Normalization\NormalizationPlugin;
use Phpdup\Normalization\Normalizer;
use Phpdup\Normalization\PluginRegistry;
use Phpdup\Parsing\AstParser;
use PHPUnit\Framework\TestCase;

/**
 * once-S24 discriminating pins (php-dup-finder-once-029).
 *
 * The NormalizationPlugin contract promises per-node invocation in
 * every mode, with self-gating on the $mode argument. Before
 * once-S24 the plugin loop sat behind the strict/default early
 * returns inside CanonicalizingVisitor::enterNode, so a registered
 * plugin never fired outside aggressive mode. The strict/default
 * arms below flip RED against that pre-fix shape (reverting the
 * relocation zeroes their visit counts); the aggressive arm is the
 * ordering-regression guard. This file is delivered as a report-row
 * pin (tests/ is outside once-S24's locks) — the lane report carries
 * the full source for replay.
 */
final class NormalizerPluginModesTest extends TestCase
{
    private const CODE = '<?php function sample($a) { return strlen("abc"); }';

    protected function setUp(): void
    {
        ModeWitnessPlugin::$seen = [];
        ModeWitnessPlugin::$nodes = 0;
        ModeWitnessPlugin::$stringValues = [];
        ModeWitnessPlugin::$funcCallNames = [];
    }

    public function test_plugin_visits_under_strict_mode(): void
    {
        $this->runMode('strict');

        self::assertGreaterThan(0, ModeWitnessPlugin::$nodes,
            'a registered plugin must visit nodes under strict mode (once-029: the loop used to sit behind the strict bail-out)');
        self::assertSame(['strict'], array_values(array_unique(ModeWitnessPlugin::$seen)),
            'visit() must receive the real mode name');
        // Strict runs only the identifier pass before the plugin, so
        // the literal keeps its source spelling at plugin time.
        self::assertContains('abc', ModeWitnessPlugin::$stringValues,
            'under strict the plugin sees literals before any literal canonicalisation (mode-ordered built-ins)');
    }

    public function test_plugin_visits_under_default_mode(): void
    {
        $this->runMode('default');

        self::assertGreaterThan(0, ModeWitnessPlugin::$nodes,
            'a registered plugin must visit nodes under default mode (once-029: the loop used to sit behind the default bail-out)');
        self::assertSame(['default'], array_values(array_unique(ModeWitnessPlugin::$seen)));
        // Default collapses string literals to '__STR' before the
        // plugin sees the node.
        self::assertContains('__STR', ModeWitnessPlugin::$stringValues,
            'under default the plugin sees post-canonicalisation literals');
    }

    public function test_plugin_visits_under_aggressive_mode(): void
    {
        $this->runMode('aggressive');

        self::assertGreaterThan(0, ModeWitnessPlugin::$nodes);
        self::assertSame(['aggressive'], array_values(array_unique(ModeWitnessPlugin::$seen)));
        // Aggressive additionally stamps call names — the plugin must
        // see that canonical form (the loop kept this exact position;
        // the arm guards the relocation against ordering regressions).
        self::assertContains('__CALL_strlen', ModeWitnessPlugin::$funcCallNames,
            'under aggressive the plugin sees name-canonicalised calls');
    }

    public function test_empty_registry_normalizes_identically_to_null_registry(): void
    {
        // The no-plugin traversal — what every golden run performs —
        // must be untouched by the relocation, in all three modes.
        foreach (['strict', 'default', 'aggressive'] as $mode) {
            self::assertSame(
                $this->canonicalHash($mode, null),
                $this->canonicalHash($mode, new PluginRegistry()),
                "empty registry must normalize identically to no registry under {$mode}",
            );
        }
    }

    private function runMode(string $mode): void
    {
        $registry = PluginRegistry::fromClassNames([ModeWitnessPlugin::class]);
        $this->canonicalHash($mode, $registry);
    }

    private function canonicalHash(string $mode, ?PluginRegistry $registry): string
    {
        $stmts = (new AstParser())->parseCode(self::CODE);
        $blocks = (new BlockExtractor(minSize: 1))->extract('test.php', $stmts);
        self::assertNotEmpty($blocks);
        (new Normalizer($mode, $registry))->normalize($blocks[0]);
        return (new SubtreeHasher())->hash($blocks[0]->canonical);
    }
}

/**
 * @internal test fixture — records every node handed to it plus the
 * mode argument, and samples the canonical state of the nodes the
 * mode-ordered built-ins should (not) have rewritten by plugin time.
 */
final class ModeWitnessPlugin implements NormalizationPlugin
{
    /** @var list<string> every mode argument seen, in visit order */
    public static array $seen = [];

    /** @var int visit count */
    public static int $nodes = 0;

    /** @var list<string> values of every String_ node at plugin time */
    public static array $stringValues = [];

    /** @var list<string> names of every FuncCall node at plugin time */
    public static array $funcCallNames = [];

    public function visit(Node $node, string $mode): void
    {
        self::$nodes++;
        self::$seen[] = $mode;
        if ($node instanceof Node\Scalar\String_) {
            self::$stringValues[] = (string) $node->value;
        }
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            self::$funcCallNames[] = $node->name->toString();
        }
    }
}
