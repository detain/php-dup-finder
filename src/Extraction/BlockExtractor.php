<?php
declare(strict_types=1);

namespace Phpdup\Extraction;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Phpdup\Util\LineRange;

/**
 * Walks a file's AST and produces Block instances for the comparable
 * subtrees defined in §5.1 of ARCHITECTURE.md:
 *
 *   functions, methods, closures, if/elseif chains, for/foreach/while,
 *   try/catch, switch.
 *
 * Two filters are applied:
 *
 *   - minSize: nodes smaller than this are dropped (kills boilerplate).
 *   - maxSize: nodes larger than this are dropped (kills the long-tail
 *     functions where bounded TED would be costly).
 *
 * Method/function declarations always pass the minSize filter — those
 * are top-level abstractions worth comparing even if small.
 */
final class BlockExtractor
{
    public const ALL_KINDS = [
        'function', 'method', 'closure', 'arrow',
        'if', 'for', 'foreach', 'while', 'do',
        'try', 'switch', 'match',
    ];

    /**
     * @param list<string> $allowedKinds Empty list = accept all kinds.
     */
    public function __construct(
        private readonly int $minSize = 8,
        private readonly int $maxSize = 800,
        private readonly array $allowedKinds = [],
    ) {
    }

    /**
     * @param list<Node\Stmt> $stmts
     * @return list<Block>
     */
    public function extract(string $file, array $stmts): array
    {
        $blocks = [];
        $traverser = new NodeTraverser();
        $visitor = new BlockVisitor(
            file: $file,
            minSize: $this->minSize,
            maxSize: $this->maxSize,
            allowedKinds: $this->allowedKinds,
            sink: function (Block $b) use (&$blocks): void { $blocks[] = $b; },
        );
        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);
        return $blocks;
    }
}

/** @internal */
final class BlockVisitor extends NodeVisitorAbstract
{
    private ?string $namespace = null;
    /** @var list<string> */
    private array $classStack = [];
    /** @var \Closure(Block):void */
    private \Closure $sink;

    /**
     * @param list<string> $allowedKinds
     */
    public function __construct(
        private readonly string $file,
        private readonly int $minSize,
        private readonly int $maxSize,
        private readonly array $allowedKinds,
        \Closure $sink,
    ) {
        $this->sink = $sink;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof Stmt\Namespace_) {
            $this->namespace = $node->name?->toString();
        }
        if ($node instanceof Stmt\Class_ || $node instanceof Stmt\Interface_ || $node instanceof Stmt\Trait_ || $node instanceof Stmt\Enum_) {
            $this->classStack[] = $node->name?->toString() ?? '<anonymous>';
        }

        $kind = $this->classifyKind($node);
        if ($kind === null) {
            return null;
        }
        if ($this->allowedKinds !== [] && !in_array($kind, $this->allowedKinds, true)) {
            return null;
        }

        $size = $this->nodeCount($node);

        $isTopLevel = in_array($kind, ['function', 'method', 'closure'], true);
        if (!$isTopLevel && $size < $this->minSize) {
            return null;
        }
        if ($size > $this->maxSize) {
            return null;
        }

        $name = null;
        if ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod) {
            $name = $node->name->toString();
        }

        $start = $node->getStartLine();
        $end = $node->getEndLine();
        if ($start <= 0 || $end <= 0) {
            return null;
        }

        $block = new Block(
            file: $this->file,
            range: new LineRange($start, $end),
            kind: $kind,
            namespace: $this->namespace,
            class: end($this->classStack) ?: null,
            name: $name,
            ast: $node,
        );
        $block->size = $size;
        $block->rangeHash = $this->computeRangeHash($start, $end);
        ($this->sink)($block);
        return null;
    }

    /**
     * SHA-1 of the literal source bytes between $start and $end
     * (inclusive). Used by incremental block extraction to reuse
     * canonical/fingerprint state across runs when source bytes
     * are unchanged.
     */
    private function computeRangeHash(int $start, int $end): ?string
    {
        if (!is_file($this->file)) return null;
        $lines = @file($this->file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) return null;
        $slice = array_slice($lines, max(0, $start - 1), max(1, $end - $start + 1));
        return sha1(implode("\n", $slice));
    }

    public function leaveNode(Node $node)
    {
        if ($node instanceof Stmt\Class_ || $node instanceof Stmt\Interface_ || $node instanceof Stmt\Trait_ || $node instanceof Stmt\Enum_) {
            array_pop($this->classStack);
        }
        return null;
    }

    private function classifyKind(Node $node): ?string
    {
        if ($node instanceof Stmt\Function_)    return 'function';
        if ($node instanceof Stmt\ClassMethod)  return 'method';
        if ($node instanceof Node\Expr\Closure) return 'closure';
        if ($node instanceof Node\Expr\ArrowFunction) return 'arrow';
        if ($node instanceof Stmt\If_)          return 'if';
        if ($node instanceof Stmt\For_)         return 'for';
        if ($node instanceof Stmt\Foreach_)     return 'foreach';
        if ($node instanceof Stmt\While_)       return 'while';
        if ($node instanceof Stmt\Do_)          return 'do';
        if ($node instanceof Stmt\TryCatch)     return 'try';
        if ($node instanceof Stmt\Switch_)      return 'switch';
        if ($node instanceof Node\Expr\Match_)  return 'match';
        return null;
    }

    private function nodeCount(Node $node): int
    {
        $count = 0;
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new class($count) extends NodeVisitorAbstract {
            public function __construct(private int &$count) {}
            public function enterNode(Node $n) { $this->count++; return null; }
        });
        $traverser->traverse([$node]);
        return $count;
    }
}
