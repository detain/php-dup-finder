<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Util;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PHPUnit\Framework\TestCase;
use Phpdup\Parsing\AstParser;
use Phpdup\Util\AstSerializer;

/**
 * Tests for AstSerializer node-count invariants.
 *
 * Verifies:
 * - subtreeSize(node) = 1 + sum(children subtreeSize) for all nodes
 * - nodeCount() is idempotent (same result on repeated calls)
 */
final class AstSerializerTest extends TestCase
{
    private static AstParser $parser;

    public static function setUpBeforeClass(): void
    {
        self::$parser = new AstParser();
    }

    /**
     * Recursively computes the expected subtree size of a node using the
     * bottom-up definition: size = 1 + sum(child sizes).
     */
    private static function expectedSubtreeSize(Node $node): int
    {
        $size = 1;
        foreach ($node->getSubNodeNames() as $sub) {
            $val = $node->$sub;
            if ($val instanceof Node) {
                $size += self::expectedSubtreeSize($val);
            } elseif (is_array($val)) {
                foreach ($val as $v) {
                    if ($v instanceof Node) {
                        $size += self::expectedSubtreeSize($v);
                    }
                }
            }
        }
        return $size;
    }

    /**
     * Collects all nodes in the tree (pre-order) so we can verify the
     * invariant for every node individually.
     *
     * @return list<Node>
     */
    private static function collectAllNodes(Node $node): array
    {
        $nodes = [$node];
        foreach ($node->getSubNodeNames() as $sub) {
            $val = $node->$sub;
            if ($val instanceof Node) {
                foreach (self::collectAllNodes($val) as $n) {
                    $nodes[] = $n;
                }
            } elseif (is_array($val)) {
                foreach ($val as $v) {
                    if ($v instanceof Node) {
                        foreach (self::collectAllNodes($v) as $n) {
                            $nodes[] = $n;
                        }
                    }
                }
            }
        }
        return $nodes;
    }

    public function testFloatLiteralsSerializeAsCanonicalIeeeBytes(): void
    {
        // phpver-fix: float literals used to ride the ini- and version-
        // dependent decimal cast ('f:1.5', and 'f:1' vs 'f:1.0' style
        // drift), moving every structuralHash that covered a float. The
        // token is now the big-endian IEEE-754 hex — permanently pinned
        // so any future re-decimalization fails loudly.
        $stmts = self::$parser->parseCode('<?php function f() { return 1.5; }');
        $this->assertNotNull($stmts);
        $floatTokens = array_values(array_filter(
            AstSerializer::tokens($stmts[0]),
            static fn(string $t): bool => str_contains($t, 'f:F'),
        ));
        $this->assertSame(['Scalar_Float_|f:F3FF8000000000000'], $floatTokens);

        // Whole-valued floats lose their decimal entirely: 1.0 is byte 3FF0…
        $whole = self::$parser->parseCode('<?php function g() { return 1.0; }');
        $this->assertNotNull($whole);
        $this->assertSame(
            ['Scalar_Float_|f:F3FF0000000000000'],
            array_values(array_filter(
                AstSerializer::tokens($whole[0]),
                static fn(string $t): bool => str_contains($t, 'f:F'),
            )),
        );
    }

    public function testNodeCountInvariant(): void
    {
        // Parse a small but non-trivial PHP snippet containing:
        // - a function definition with a return statement
        // - an assignment expression
        $code = '<?php function foo() { return 42; } $x = "hello";';
        $stmts = self::$parser->parseCode($code);
        $root = $stmts[0];

        $allNodes = self::collectAllNodes($root);

        // For each node, verify: AstSerializer::nodeCount(node) == expectedSubtreeSize(node)
        foreach ($allNodes as $node) {
            $actualCount = AstSerializer::nodeCount($node);
            $expectedCount = self::expectedSubtreeSize($node);
            $this->assertSame(
                $expectedCount,
                $actualCount,
                sprintf(
                    'NodeCount invariant violated for %s: expected %d, got %d',
                    $node::class,
                    $expectedCount,
                    $actualCount
                )
            );
        }
    }

    public function testNodeCountConsistency(): void
    {
        // Parse a function with multiple statements
        $code = '<?php function bar() { $x = 1; return $x + 2; }';
        $stmts = self::$parser->parseCode($code);
        $root = $stmts[0];

        // Call nodeCount 5 times and verify stable result
        $counts = [];
        for ($i = 0; $i < 5; $i++) {
            $counts[] = AstSerializer::nodeCount($root);
        }

        $uniqueCounts = array_unique($counts);
        $this->assertCount(1, $uniqueCounts, sprintf(
            'nodeCount() returned inconsistent results: %s',
            implode(', ', array_map('strval', $counts))
        ));
    }

    public function testNodeCountSurvivesPathologicalNestingDepth(): void
    {
        // engine-004: nodeCount() used to recurse per descendant node, so a
        // legal-but-pathological nesting depth (the parser itself survives
        // it) blew the worker stack mid-count. The iterative count must
        // return the exact total without coming near the call-stack limit.
        $levels = 2000;
        $current = new Scalar\Int_(1);
        $built = 1;
        for ($i = 0; $i < $levels; ++$i) {
            $current = new Expr\Array_([new Expr\ArrayItem($current)]);
            $built += 2; // Array_ + ArrayItem
        }
        $root = new Node\Stmt\Expression($current);

        self::assertSame($built + 1, AstSerializer::nodeCount($root));
    }
}
