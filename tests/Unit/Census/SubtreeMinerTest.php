<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Census;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PHPUnit\Framework\TestCase;
use Phpdup\Census\SubtreeFold;
use Phpdup\Census\SubtreeMiner;

/**
 * Item 3.1 substrate — bottom-up enumeration: the 6..40 node window,
 * the whole-block rule, nearest-enumerated-ancestor edges and the
 * per-file pre-aggregation the mined row carries.
 *
 * Fixture shape: every statement is `Stmt\Expression(tree(s))` with a
 * closed-form node budget — tree(1)=Int_ (1 node), tree(2)=unary
 * minus (2 nodes), tree(t)=Plus(Int_, tree(t-2)). A statement over
 * tree(s) therefore has exactly s+1 nodes, and each spine node's size
 * (s, s-2, …) is known ahead of any assertion.
 */
final class SubtreeMinerTest extends TestCase
{
    /** @return Expr&Node */
    private static function tree(int $target): Expr
    {
        self::assertGreaterThanOrEqual(1, $target);
        if ($target === 1) {
            return new Scalar\Int_(1);
        }
        if ($target === 2) {
            return new Expr\UnaryMinus(new Scalar\Int_(1));
        }
        return new Expr\BinaryOp\Plus(new Scalar\Int_(1), self::tree($target - 2));
    }

    /** @return list<array{stmts: list<Node>}> */
    private static function lists(int ...$treeSizes): array
    {
        $stmts = [];
        foreach (array_values($treeSizes) as $i => $size) {
            $stmts[] = new Stmt\Expression(self::tree($size), ['startLine' => $i + 2]);
        }
        return [['stmts' => $stmts]];
    }

    /** exact-key => occurrence-count map of a payload. */
    private static function siteKeys(array $payload): array
    {
        $keys = [];
        foreach ($payload['sites'] as $row) {
            $keys[$row[0]] = $row[4];
        }
        return $keys;
    }

    public function testConstructorRejectsInvertedWindow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SubtreeMiner(minNodes: 10, maxNodes: 6);
    }

    public function testWindowLowerEdge(): void
    {
        $miner = new SubtreeMiner();
        // tree(5) => statement has 6 nodes (IN window, first eligible
        // count), the tree itself is 5 (BELOW window).
        $payload = $miner->mineFile(self::lists(5, 1));
        $keys    = self::siteKeys($payload);
        self::assertArrayHasKey(
            SubtreeFold::hashExact(new Stmt\Expression(self::tree(5))),
            $keys,
            'a 6-node statement is enumerated'
        );
        self::assertArrayNotHasKey(SubtreeFold::hashExact(self::tree(5)), $keys, 'a 5-node subtree is not');
        self::assertSame(1, array_sum($keys), 'exactly one occurrence in the window');
    }

    public function testWindowUpperEdge(): void
    {
        $miner = new SubtreeMiner();
        // tree(40) statement: 41-node statement OUT, 40-node tree IN,
        // 38-node spine child IN.
        $forty = $miner->mineFile(self::lists(40, 1));
        $keys  = self::siteKeys($forty);
        self::assertArrayHasKey(SubtreeFold::hashExact(self::tree(40)), $keys, '40 is inside the window');
        self::assertArrayHasKey(SubtreeFold::hashExact(self::tree(38)), $keys, 'nested in-window child rides too');
        self::assertArrayNotHasKey(
            SubtreeFold::hashExact(new Stmt\Expression(self::tree(40))),
            $keys,
            'the 41-node statement is outside the window'
        );

        // tree(41) statement: 42-node statement OUT, 41-node tree OUT,
        // 39-node grandchild IN (no enumerated ancestor above it).
        $fortyOne = $miner->mineFile(self::lists(41, 1));
        $keys     = self::siteKeys($fortyOne);
        self::assertArrayNotHasKey(SubtreeFold::hashExact(self::tree(41)), $keys, '41 is outside the window');
        self::assertArrayHasKey(SubtreeFold::hashExact(self::tree(39)), $keys);
    }

    public function testWholeBlockRule(): void
    {
        $miner = new SubtreeMiner();
        $stmt  = new Stmt\Expression(self::tree(39), ['startLine' => 2]); // 40 nodes: in-window
        $keys  = self::siteKeys($miner->mineFile([['stmts' => [$stmt]]]));
        self::assertArrayNotHasKey(SubtreeFold::hashExact($stmt), $keys, 'the root of a single-statement list IS the block — skipped');
        self::assertArrayHasKey(SubtreeFold::hashExact(self::tree(39)), $keys, 'its nested subtree is still mined');

        // Same statement with a sibling becomes an ordinary candidate.
        $pair = self::siteKeys($miner->mineFile(self::lists(39, 1)));
        self::assertArrayHasKey(SubtreeFold::hashExact(new Stmt\Expression(self::tree(39))), $pair);
    }

    public function testStatementsAreCandidatesWhenInWindow(): void
    {
        $miner = new SubtreeMiner();
        $payload = $miner->mineFile(self::lists(5, 1));
        $row     = $payload['sites'][0];
        self::assertSame('Stmt_Expression', $row[3], 'statement root type is recorded');
    }

    public function testEdgesCarryNearestEnumeratedAncestorChain(): void
    {
        $miner = new SubtreeMiner();
        // lists(19): statement(20) enumerated; spine tree(19),tree(17)…
        // in-window down to tree(7). Each child's nearest enumerated
        // ancestor is the DIRECT parent node — never a grandparent, and
        // the top node (the statement) has no parent edge at all.
        $payload = $miner->mineFile(self::lists(19, 1));
        $byChild = [];
        foreach ($payload['edges'] as [$child, $parent, $count]) {
            self::assertSame(1, $count, 'each spine node occurs once');
            $byChild[$child] = $parent;
        }

        $stmtKey  = SubtreeFold::hashExact(new Stmt\Expression(self::tree(19)));
        $tree19   = SubtreeFold::hashExact(self::tree(19));
        $tree17   = SubtreeFold::hashExact(self::tree(17));
        self::assertArrayHasKey($tree17, $byChild);
        self::assertSame($tree19, $byChild[$tree17], 'tree(17) nests directly under tree(19)');
        self::assertSame($stmtKey, $byChild[$tree19], 'tree(19) nests directly under the statement');
        self::assertArrayNotHasKey($stmtKey, $byChild, 'the top enumerated node has no parent edge');
    }

    public function testRowCapLimitsSampleLines(): void
    {
        // Seven identical `$o = 1 + (1 + (-1));` lines (parsed, so every
        // node carries a real startLine): statement(9) + Assign(8) +
        // Plus(6) all sit in the window, each at count 7 — and each caps
        // its sample lines at rowCap.
        $parser = (new \PhpParser\ParserFactory())->createForHostVersion();
        $ast    = $parser->parse("<?php\n" . str_repeat("\$o = 1 + (1 + (-1));\n", 7));
        self::assertIsArray($ast);
        $miner   = new SubtreeMiner(rowCap: 4);
        $payload = $miner->mineFile([['stmts' => array_values($ast)]]);
        self::assertCount(3, $payload['sites']);
        foreach ($payload['sites'] as $row) {
            self::assertSame(7, $row[4]);
            self::assertCount(4, $row[5], 'samples are capped at emission');
        }
    }

    public function testPayloadOrderIsDeterministic(): void
    {
        $miner = new SubtreeMiner();
        $lists = self::lists(30, 42, 12, 30);
        $a     = json_encode($miner->mineFile($lists), JSON_THROW_ON_ERROR);
        $b     = json_encode($miner->mineFile($lists), JSON_THROW_ON_ERROR);
        self::assertSame($a, $b);

        $decoded = json_decode($a, true);
        $keys    = array_column($decoded['sites'], 0);
        $sorted  = $keys;
        sort($sorted);
        self::assertSame($sorted, $keys, 'site rows sorted by exact hash asc');
        $edgePairs = array_map(static fn(array $e): array => [$e[1], $e[0]], $decoded['edges']);
        $edgeSort  = $edgePairs;
        sort($edgeSort);
        self::assertSame($edgeSort, $edgePairs, 'edge rows sorted parent-then-child');
    }

    public function testEmptyListsMineToEmptyPayload(): void
    {
        $miner = new SubtreeMiner();
        self::assertSame(['sites' => [], 'edges' => []], $miner->mineFile([]));
        self::assertSame(['sites' => [], 'edges' => []], $miner->mineFile([['stmts' => []]]));
    }

    public function testCustomWindowRespectsArms(): void
    {
        $miner = new SubtreeMiner(minNodes: 3, maxNodes: 4);
        // lists(4): statement(5) above max; tree(4) in; tree(2) below min.
        $sizes = array_column($miner->mineFile(self::lists(4))['sites'], 2);
        self::assertSame([4], $sizes);
    }
}
