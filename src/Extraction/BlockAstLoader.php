<?php
declare(strict_types=1);

namespace Phpdup\Extraction;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Phpdup\Parsing\AstCache;
use Phpdup\Parsing\AstParser;

/**
 * Resolves a Block's original AST on demand.
 *
 * Memory optimisation for very large corpora: after fingerprinting we
 * can drop {@see Block::$ast} and the AntiUnifier reloads the original
 * subtree only for the small subset of blocks that end up in clusters.
 *
 * Resolution walks the file's parse-cached statement list looking for
 * a node whose kind, line-range, and (when applicable) declared name
 * match the block. The kind/range/name triple is unique within a file
 * for the kinds we extract.
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

    public function resolve(Block $block): Node
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
        $found = $this->find($stmts, $block);
        if ($found === null) {
            throw new \RuntimeException(sprintf(
                'BlockAstLoader: could not relocate block %s in %s', $block->qualifiedName(), $block->file
            ));
        }
        $block->ast = $found;
        return $found;
    }

    /**
     * @param list<Node\Stmt> $stmts
     */
    private function find(array $stmts, Block $block): ?Node
    {
        $found = null;
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($block, $found) extends NodeVisitorAbstract {
            public function __construct(private readonly Block $block, private ?Node &$found)
            {
            }
            public function enterNode(Node $node)
            {
                if ($this->found !== null) {
                    return null;
                }
                if ($node->getStartLine() !== $this->block->range->start) return null;
                if ($node->getEndLine()   !== $this->block->range->end)   return null;
                if (!self::matchesKind($node, $this->block->kind))        return null;
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
        });
        $traverser->traverse($stmts);
        return $found;
    }
}
