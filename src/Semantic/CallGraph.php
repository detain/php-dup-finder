<?php
declare(strict_types=1);

namespace Phpdup\Semantic;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Phpdup\Extraction\Block;

/**
 * Lightweight per-block call signature.
 *
 * Walks each block once and collects the qualified names of every
 * MethodCall / StaticCall / FuncCall it contains, plus a frequency
 * count per name. The resulting signature feeds two consumers:
 *
 *   - {@see \Phpdup\Similarity\CallGraphSimilarity} — Jaccard over
 *     signatures, used as a Type-3 fallback when AST similarity is
 *     close to but below the threshold.
 *   - {@see \Phpdup\Architecture\Analyzers\SolidAnalyzer} — already
 *     uses an inline call walk; this class consolidates it.
 *
 * The graph itself (block-id → callees) is built lazily from the
 * corpus AST when a caller invokes {@see build()}; small heap
 * footprint because we only store names, not full call sites.
 */
final class CallGraph
{
    /** @var array<string, array<string,int>> block id → callee name → count */
    private array $signatures = [];

    /**
     * @param iterable<Block> $blocks
     */
    public function build(iterable $blocks): void
    {
        $finder = new NodeFinder();
        foreach ($blocks as $b) {
            if ($b->ast === null) continue;
            $sig = [];
            foreach ($finder->find([$b->ast], static function (Node $n) {
                return $n instanceof Node\Expr\MethodCall
                    || $n instanceof Node\Expr\StaticCall
                    || $n instanceof Node\Expr\FuncCall;
            }) as $call) {
                $name = self::nameOf($call);
                if ($name === null) continue;
                $sig[$name] = ($sig[$name] ?? 0) + 1;
            }
            $this->signatures[$b->id] = $sig;
        }
    }

    /** @return array<string,int>|null */
    public function signatureFor(string $blockId): ?array
    {
        return $this->signatures[$blockId] ?? null;
    }

    private static function nameOf(Node $call): ?string
    {
        if ($call instanceof Node\Expr\MethodCall && $call->name instanceof Node\Identifier) {
            return $call->name->name;
        }
        if ($call instanceof Node\Expr\StaticCall && $call->name instanceof Node\Identifier) {
            $cls = $call->class instanceof Node\Name ? $call->class->toString() : '?';
            return $cls . '::' . $call->name->name;
        }
        if ($call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name) {
            return $call->name->toString();
        }
        return null;
    }
}
