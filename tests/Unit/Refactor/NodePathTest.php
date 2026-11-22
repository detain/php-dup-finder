<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Refactor;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Util\NodePath;

/**
 * php-dup-finder-output-039: the unification walkers carry their path as
 * an immutable NodePath cons-list instead of rebuilding `[...$path, $sub]`
 * plus an implode per visit. The refactor is only legal if the KEY grammar
 * and the MATERIALIZED path lists stay byte-identical to the legacy spread
 * construction — asserted here over seeded random ASTs (the reference
 * walkers below are the legacy implementations, verbatim), and pinned for
 * the holePaths producer by AntiUnifierTest's exact providers.
 */
final class NodePathTest extends TestCase
{
    /**
     * For every visited slot of every seeded random tree: the legacy
     * spread-built path list and its implode key must equal NodePath's
     * toPathList()/key() exactly — order, types, and bytes.
     */
    public function testKeyAndPathAreByteIdenticalToLegacySpreadsAcrossRandomTrees(): void
    {
        $totalVisits = 0;
        for ($seed = 1; $seed <= 12; $seed++) {
            $tree = $this->randomTree($seed);

            $legacy = [];
            $this->legacyWalk($tree, [], $legacy);

            $modern = [];
            $this->nodePathWalk($tree, NodePath::root(), $modern);

            $this->assertNotEmpty($legacy);
            $this->assertGreaterThanOrEqual(40, count($legacy), 'non-vacuity: seed ' . $seed . ' visits too few slots');
            $this->assertSame($legacy, $modern, 'NodePath drifted from the legacy grammar on seed ' . $seed);
            $totalVisits += count($legacy);
        }
        // Belt for the belt: the corpus actually spans both segment kinds.
        $this->assertGreaterThanOrEqual(500, $totalVisits);
    }

    /**
     * key() and keyOfList(toPathList()) agree slot-for-slot — the
     * single-grammar guarantee consumers of Cluster::$holePaths rely on.
     */
    public function testKeyOfListMatchesWalkedKeysOnEverySlot(): void
    {
        for ($seed = 1; $seed <= 6; $seed++) {
            $this->assertKeyOfListMatches(NodePath::root(), $this->randomTree($seed));
        }
    }

    public function testRootKeyIsEmptyStringAndSegmentsJoinWithSlash(): void
    {
        $this->assertSame('', NodePath::root()->key());
        $this->assertSame([], NodePath::root()->toPathList());

        $this->assertSame('0', NodePath::root()->child(0)->key(), 'int segment stringifies alone');
        $this->assertSame('name', NodePath::root()->child('name')->key());

        $deep = NodePath::root()->child('stmts')->child(2)->child('expr')->child('name');
        $this->assertSame('stmts/2/expr/name', $deep->key());
        $this->assertSame(['stmts', 2, 'expr', 'name'], $deep->toPathList());
    }

    public function testToPathListPreservesSegmentTypes(): void
    {
        // The hole-path contract (once-039): names stay strings, indices
        // stay ints — assertSame catches any strval drift.
        $path = NodePath::root()->child('args')->child(1)->child('value');
        $this->assertSame(['args', 1, 'value'], $path->toPathList());
        $this->assertSame('args/1/value', NodePath::keyOfList(['args', 1, 'value']));
        $this->assertSame('args/1/value', $path->key());
    }

    public function testKeyIsMemoizedAndConsistentAcrossChildrenAndSiblings(): void
    {
        $base    = NodePath::root()->child('stmts');
        $first   = $base->child(0);
        $second  = $base->child(1);
        $nested  = $first->child('expr');

        $this->assertSame('stmts/0', $first->key());
        $this->assertSame('stmts/0', $first->key(), 're-read after sibling and child creation');
        $this->assertSame('stmts/1', $second->key(), 'sibling must not observe the child cache');
        $this->assertSame('stmts/0/expr', $nested->key());
        $this->assertSame('stmts/0', $first->key(), 'parent cache survives child descent');
    }

    public function testKeyOfListIsTheLegacyGrammarForMaterializedHolePaths(): void
    {
        // HoleCoverage/SignatureBuilder derive their hole-key set from
        // Cluster::$holePaths lists; the empty list (root hole) keys ''.
        $this->assertSame('', NodePath::keyOfList([]));
        $this->assertSame('stmts/0', NodePath::keyOfList(['stmts', 0]));
        $this->assertSame('2/3', NodePath::keyOfList([2, 3]), 'adjacent int segments keep strval grammar');
    }

    // ------------------------------------------------------------------

    private function assertKeyOfListMatches(NodePath $path, mixed $value): void
    {
        $this->assertSame(NodePath::keyOfList($path->toPathList()), $path->key());
        if ($value instanceof Node) {
            foreach ($value->getSubNodeNames() as $sub) {
                $this->assertKeyOfListMatches($path->child($sub), $value->$sub);
            }
            return;
        }
        if (is_array($value)) {
            foreach ($value as $idx => $item) {
                $this->assertKeyOfListMatches($path->child($idx), $item);
            }
        }
    }

    /**
     * @param list<array{0: string, 1: list<int|string>}> $sink
     *        legacy walk: keys and materialized lists, exactly as the
     *        pre-output-039 walkers produced them.
     */
    private function legacyWalk(mixed $value, array $path, array &$sink): void
    {
        $sink[] = [implode('/', array_map('strval', $path)), $path];
        if ($value instanceof Node) {
            foreach ($value->getSubNodeNames() as $sub) {
                $this->legacyWalk($value->$sub, [...$path, $sub], $sink);
            }
            return;
        }
        if (is_array($value)) {
            foreach ($value as $idx => $item) {
                $this->legacyWalk($item, [...$path, $idx], $sink);
            }
        }
    }

    /** @param list<array{0: string, 1: list<int|string>}> $sink */
    private function nodePathWalk(mixed $value, NodePath $path, array &$sink): void
    {
        $sink[] = [$path->key(), $path->toPathList()];
        if ($value instanceof Node) {
            foreach ($value->getSubNodeNames() as $sub) {
                $this->nodePathWalk($value->$sub, $path->child($sub), $sink);
            }
            return;
        }
        if (is_array($value)) {
            foreach ($value as $idx => $item) {
                $this->nodePathWalk($item, $path->child($idx), $sink);
            }
        }
    }

    /** Deterministic seeded random AST covering nodes, lists, and leaves. */
    private function randomTree(int $seed): Node
    {
        $state = $seed * 2654435761 % 4294967296;
        $next  = static function (int $mod) use (&$state): int {
            $state = ($state * 1103515245 + 12345) % 2147483648;
            return $state % $mod;
        };
        return new Node\Stmt\Function_('probe' . $seed, [
            'stmts' => $this->randomStmtList($next, 1, 8 + $next(4)),
        ]);
    }

    /** @param callable(int): int $next @return list<Node\Stmt> */
    private function randomStmtList(callable $next, int $depth, ?int $forcedCount = null): array
    {
        $out = [];
        for ($i = 0, $n = $forcedCount ?? $next(4); $i < $n; $i++) {
            $node  = $this->randomNode($next, $depth);
            $out[] = $node instanceof Node\Stmt ? $node : new Node\Stmt\Expression($node);
        }
        return $out;
    }

    /** @param callable(int): int $next */
    private function randomNode(callable $next, int $depth): Node
    {
        if ($depth >= 3) {
            return $this->randomComposite($next, $depth);
        }
        $branch = $next(6);
        if ($branch === 4) {
            return new Node\Stmt\If_(new Node\Expr\BinaryOp\Equal(new Node\Expr\Variable('c'), $this->randomLeaf($next)), [
                'stmts' => $this->randomStmtList($next, $depth + 1),
            ]);
        }
        if ($branch === 5) {
            return new Node\Stmt\Foreach_(
                new Node\Expr\Variable('items'),
                new Node\Expr\Variable('item'),
                ['stmts' => $this->randomStmtList($next, $depth + 1)]
            );
        }
        return $this->randomComposite($next, $depth);
    }

    /**
     * Composite node pool — every entry carries a guaranteed payload of
     * subnode slots (names, args, leaves), so each seeded tree visits a
     * large non-vacuous number of paths.
     *
     * @param callable(int): int $next
     */
    private function randomComposite(callable $next, int $depth): Node
    {
        return match ($next(4)) {
            0 => new Node\Stmt\Expression(
                new Node\Expr\Assign(
                    new Node\Expr\ArrayDimFetch(new Node\Expr\Variable('v' . $depth), $this->randomLeaf($next)),
                    $this->randomLeaf($next)
                )
            ),
            1 => new Node\Expr\Array_([
                new Node\Expr\ArrayItem($this->randomLeaf($next), $this->randomLeaf($next)),
                new Node\Expr\ArrayItem(new Node\Expr\Variable('z' . $next(4))),
            ]),
            2 => new Node\Expr\MethodCall(
                new Node\Expr\Variable('o'),
                new Node\Identifier('m' . $next(3)),
                [$this->randomLeaf($next), new Node\Arg($this->randomLeaf($next))]
            ),
            default => new Node\Expr\FuncCall(new Node\Name('f' . $next(2)), $this->randomArgs($next)),
        };
    }

    /** @param callable(int): int $next */
    private function randomLeaf(callable $next): Node
    {
        return match ($next(4)) {
            0 => new Node\Scalar\Int_(1000 + $next(99)),
            1 => new Node\Scalar\String_('s' . $next(7)),
            2 => new Node\Scalar\Float_(0.5 + $next(5)),
            default => new Node\Expr\ConstFetch(new Node\Name('true')),
        };
    }

    /** @param callable(int): int $next @return list<Node> */
    private function randomArgs(callable $next): array
    {
        $out = [];
        for ($i = 0, $n = $next(3); $i < $n; $i++) {
            $out[] = new Node\Arg($this->randomLeaf($next));
        }
        return $out;
    }
}
