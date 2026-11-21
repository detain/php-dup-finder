<?php
declare(strict_types=1);

namespace Phpdup\Extraction;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Phpdup\Parsing\AstCache;
use Phpdup\Parsing\AstParser;
use Phpdup\Util\LineRange;

/**
 * Resolves a Block's original AST on demand.
 *
 * Memory optimisation for very large corpora: after fingerprinting we
 * can drop {@see Block::$ast} and the AntiUnifier reloads the original
 * subtree only for the small subset of blocks that end up in clusters.
 *
 * Resolution first walks the file's parse-cached statement list for a
 * node whose kind, line-range, and (when applicable) declared name
 * match the block — the strict triple, exact whenever the range was
 * produced by this process's own scan.
 *
 * A range replayed from the cluster cache is only an ADVISORY hint:
 * content-preserving edits (comment or blank-line inserts above a
 * block) leave structuralHash — and therefore the v3/v4 corpus token —
 * untouched, so the cache legitimately hits while every cached line
 * number below the edit has drifted. On a strict miss the loader
 * re-anchors by structural identity (kind + declared name + enclosing
 * namespace/class), scoring candidates by span preservation and
 * proximity to the stale hint, and refreshes the block's range to the
 * located node either way. Genuine ambiguity still fails loudly:
 * guessing a signature from the wrong sibling would poison reports.
 */
final class BlockAstLoader
{
    /** @var array<string, list<Node\Stmt>> file → cached stmts (per-process) */
    private array $cache = [];

    public function __construct(
        private readonly AstCache $astCache,
        private readonly AstParser $parser,
    ) {
    }

    public function resolve(Block $block): Node|null
    {
        if (!$block->isAstUnloaded()) {
            return $block->ast;
        }
        $stmts = $this->cache[$block->file] ?? null;
        if ($stmts === null) {
            $stmts = $this->astCache->get($block->file);
            if ($stmts === null) {
                $stmts = $this->parser->parseFile($block->file) ?? [];
                $this->astCache->put($block->file, $stmts);
            }
            $this->cache[$block->file] = $stmts;
        }
        $found = $this->findStrict($stmts, $block);
        if ($found === null) {
            $found = $this->findReanchored($stmts, $block);
        }
        if ($found === null) {
            throw new \RuntimeException(sprintf(
                'BlockAstLoader: could not relocate block %s in %s (kind %s, hinted range %s)',
                $block->qualifiedName(), $block->file, $block->kind, (string) $block->range
            ));
        }
        // The located node is now the truth for this block: a warm-cache
        // replay may have handed us a stale hint, and downstream
        // consumers (refactor signatures, JSON member lines) must not
        // keep reporting the drifted position.
        $block->range = new LineRange($found->getStartLine(), $found->getEndLine());
        $block->ast = $found;
        return $found;
    }

    /**
     * Exact kind + line-range + name match (the original resolution).
     *
     * @param list<Node\Stmt> $stmts
     */
    private function findStrict(array $stmts, Block $block): ?Node
    {
        $found = null;
        $kindMatch = static fn (Node $node, string $kind): bool => self::matchesKind($node, $kind);
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($block, $found, $kindMatch) extends NodeVisitorAbstract {
            public function __construct(
                private readonly Block $block,
                private ?Node &$found,
                private readonly \Closure $kindMatch,
            ) {
            }
            #[\Override]
            public function enterNode(Node $node)
            {
                if ($this->found !== null) {
                    return null;
                }
                if ($node->getStartLine() !== $this->block->range->start) return null;
                if ($node->getEndLine()   !== $this->block->range->end)   return null;
                if (!($this->kindMatch)($node, $this->block->kind))        return null;
                if ($this->block->name !== null) {
                    $name = match (true) {
                        $node instanceof Node\Stmt\Function_   => $node->name->toString(),
                        $node instanceof Node\Stmt\ClassMethod => $node->name->toString(),
                        default                                => null,
                    };
                    if ($name !== $this->block->name) return null;
                }
                $this->found = $node;
                return null;
            }
        });
        $traverser->traverse($stmts);
        return $found;
    }

    /**
     * Range-insensitive re-anchor — the warm-cache cure (review M-1).
     *
     * A cluster-cache hit replays members whose line ranges predate the
     * current file bytes. Structural identity still pins the block:
     * kind, declared name (methods/functions), enclosing namespace and
     * class. Among survivors the one whose span the edit preserved and
     * whose distance to the stale hint is smallest wins; a tie or an
     * empty pool returns null so the caller keeps its loud failure.
     *
     * @param list<Node\Stmt> $stmts
     */
    private function findReanchored(array $stmts, Block $block): ?Node
    {
        $kindMatch = static fn (Node $node, string $kind): bool => self::matchesKind($node, $kind);
        $traverser = new NodeTraverser();
        $visitor = new class($block, $kindMatch) extends NodeVisitorAbstract {
            /** @var list<array{node: Node, score: array{int,int,int}}> */
            public array $candidates = [];

            private ?string $namespace = null;

            /** @var list<string> */
            private array $classStack = [];

            public function __construct(
                private readonly Block $block,
                private readonly \Closure $kindMatch,
            ) {
            }

            #[\Override]
            public function enterNode(Node $node)
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->namespace = $node->name?->toString();
                    return null;
                }
                if ($node instanceof Node\Stmt\ClassLike) {
                    $this->classStack[] = $node->name?->toString() ?? '<anonymous-class>';
                    return null;
                }
                $this->consider($node);
                return null;
            }

            #[\Override]
            public function leaveNode(Node $node)
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->namespace = null;
                } elseif ($node instanceof Node\Stmt\ClassLike) {
                    array_pop($this->classStack);
                }
                return null;
            }

            private function consider(Node $node): void
            {
                if (!($this->kindMatch)($node, $this->block->kind)) {
                    return;
                }
                $currentClass = $this->classStack === [] ? null : end($this->classStack);
                if ($currentClass !== $this->block->class) {
                    return;
                }
                if ($this->namespace !== $this->block->namespace) {
                    return;
                }
                if ($this->block->name !== null) {
                    $name = match (true) {
                        $node instanceof Node\Stmt\Function_   => $node->name->toString(),
                        $node instanceof Node\Stmt\ClassMethod => $node->name->toString(),
                        default                                => null,
                    };
                    if ($name !== $this->block->name) {
                        return;
                    }
                }
                $hintSpan = $this->block->range->end - $this->block->range->start;
                $nodeSpan = $node->getEndLine() - $node->getStartLine();
                $dStart   = $node->getStartLine() - $this->block->range->start;
                $dEnd     = $node->getEndLine() - $this->block->range->end;
                $this->candidates[] = [
                    'node' => $node,
                    // Lexicographic score, best first:
                    //  1. span preserved (inserts ABOVE keep line count);
                    //  2. smallest total distance to the stale hint;
                    //  3. non-negative shift — insertions are the promised
                    //     drift direction (docs/CLI.md: comment/blank-line
                    //     shifts), so a candidate below-or-at the hint
                    //     beats a mirror above it; deletions that shift
                    //     blocks upward lose this lever only when a tie
                    //     exists, and a tie loses to the loud failure.
                    'score' => [
                        $nodeSpan === $hintSpan ? 0 : 1,
                        abs($dStart) + abs($dEnd),
                        $dStart >= 0 ? 0 : 1,
                    ],
                ];
            }
        };
        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);

        $pool = $visitor->candidates;
        if ($pool === []) {
            return null;
        }
        if (count($pool) === 1) {
            return $pool[0]['node'];
        }
        usort($pool, static fn (array $a, array $b): int => $a['score'] <=> $b['score']);
        if ($pool[0]['score'] === $pool[1]['score']) {
            return null; // genuinely ambiguous — fail loud upstream
        }
        return $pool[0]['node'];
    }

    private static function matchesKind(Node $node, string $kind): bool
    {
        return match ($kind) {
            'function'  => $node instanceof Node\Stmt\Function_,
            'method'    => $node instanceof Node\Stmt\ClassMethod,
            'closure'   => $node instanceof Node\Expr\Closure,
            'arrow'     => $node instanceof Node\Expr\ArrowFunction,
            'if'        => $node instanceof Node\Stmt\If_,
            'for'       => $node instanceof Node\Stmt\For_,
            'foreach'   => $node instanceof Node\Stmt\Foreach_,
            'while'     => $node instanceof Node\Stmt\While_,
            'do'        => $node instanceof Node\Stmt\Do_,
            'try'       => $node instanceof Node\Stmt\TryCatch,
            'switch'    => $node instanceof Node\Stmt\Switch_,
            'match'     => $node instanceof Node\Expr\Match_,
            default     => false,
        };
    }
}
