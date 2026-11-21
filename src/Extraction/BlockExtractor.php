<?php
declare(strict_types=1);

namespace Phpdup\Extraction;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Phpdup\Parsing\CacheBlob;
use Phpdup\Util\AstSerializer;
use Phpdup\Util\LineRange;
use SplObjectStorage;

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
 *
 * Item 1.3 adds a thirteenth kind, 'script': a folded run of top-level
 * statements (requires/includes/auth-guards/bootstrap sequences — the
 * copy-paste shape that lives OUTSIDE any function in classic procedural
 * PHP). 'script' is OPT-IN ONLY: it never joins the implicit
 * accept-all list (empty allowedKinds), because top-level fold blocks
 * must be a deliberate choice — they change what "a block" means for a
 * file, and the apply/extraction machinery cannot relocate them yet.
 */
final class BlockExtractor
{
    public const ALL_KINDS = [
        'function', 'method', 'closure', 'arrow',
        'if', 'for', 'foreach', 'while', 'do',
        'try', 'switch', 'match',
        // Item 1.3: opt-in top-level script blocks — NOT part of the
        // implicit accept-all set (see class docblock and extract()).
        'script',
    ];

    /**
     * @param list<string> $allowedKinds Empty list = accept all twelve
     *        traversable kinds — the 1.3 'script' fold kind is never
     *        implicit, it must be named (drift-pinned by BlockExtractorTest
     *        and ScriptKindTest).
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
        // engine-004 (deep-AST family): php-parser's NodeTraverser recurses
        // one frame per AST level, so a nesting depth the (iterative) parser
        // itself survives — e.g. a 10k-deep array literal — blows the C
        // stack here with a segfault, not a catchable Error: pre-fix, an
        // over-deep statement killed the ENTIRE run and yielded zero
        // blocks for the whole file. Whole top-level statements past the
        // shared CacheBlob depth budget are pruned before traversal. The
        // measured cost is confined to pathological input: a tiny kind
        // node buried at thousands of levels of nesting (e.g. the
        // innermost arrow in a 10k-deep fn-chain) stays invisible, while
        // every other statement of the file keeps full extraction
        // semantics — strictly more detection than the segfault baseline,
        // and the pipeline now survives to analyse the rest of the corpus.
        $traversable = [];
        foreach ($stmts as $stmt) {
            if (CacheBlob::exceedsDepth([$stmt])) {
                continue;
            }
            $traversable[] = $stmt;
        }

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
        $traverser->traverse($traversable);

        // Item 1.3: 'script' is opt-IN ONLY — the empty allow-list means
        // "all traversable kinds", never "also fold top-level statements".
        // Folded blocks therefore append after the visitor's blocks in a
        // fixed, deterministic order (per-scope, first-line ordered).
        if (in_array('script', $this->allowedKinds, true)) {
            foreach (self::groupTopLevel($stmts) as $group) {
                if ($group['stmts'] === []) {
                    continue; // groupTopLevel never emits empty buckets; belt for the offset below
                }
                $first = $group['stmts'][0];
                $last  = $group['stmts'][\count($group['stmts']) - 1];
                $start = $first->getStartLine();
                $end   = $last->getEndLine();
                if ($start <= 0 || $end <= 0) {
                    continue; // same line-guard the visitor applies
                }
                // A synthetic Block node wraps the folded statement run so
                // the block carries a real Node as canonical/ast — the
                // probe fact from the research filing: pipeline and caches
                // accept Stmt\Block wrappers unchanged.
                $wrapper = new Stmt\Block(array_values($group['stmts']), [
                    'startLine' => $start,
                    'endLine'   => $end,
                ]);
                $size = AstSerializer::nodeCount($wrapper);
                // Uniform size posture: scripts are gated by BOTH floors —
                // the minSize filter (they are not declarations) and the
                // maxSize ceiling (a whole-file fold must not smuggle past
                // the cost ceiling the traversable kinds obey).
                if ($size < $this->minSize || $size > $this->maxSize) {
                    continue;
                }
                $block = new Block(
                    file: $file,
                    range: new LineRange($start, $end),
                    kind: 'script',
                    namespace: $group['namespace'],
                    class: null,
                    name: null,
                    ast: $wrapper,
                );
                $block->size = $size;
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /**
     * The 'script' kind's fold map: bucket the DECLARATIVE-free top-level
     * statements of a file into per-scope runs. Declarations (classes,
     * interfaces, traits, enums, functions), namespace/use/declare
     * bookkeeping, inline HTML and no-ops are NOT folded — they already
     * have a traversable kind or are structure, not logic. A top-level
     * `const` DECLARATION is excluded for the same reason; an
     * Expression-wrapped `require`/`echo`/call is exactly what IS folded.
     *
     * Bracketed namespaces fold in their own scope (two namespaces never
     * mix into one block — mirrors BlockVisitor's namespace tracking);
     * file-root statements fold under namespace=null.
     *
     * @param list<Node\Stmt> $stmts
     * @return list<array{namespace: ?string, stmts: list<Node\Stmt>}>
     */
    public static function groupTopLevel(array $stmts): array
    {
        $groups = [];
        $foldable = static function (Node\Stmt $s): bool {
            return !($s instanceof Stmt\ClassLike)      // class/interface/trait/enum
                && !($s instanceof Stmt\Function_)
                && !($s instanceof Stmt\Use_)           // covers GroupUse (extends Use_)
                && !($s instanceof Stmt\Declare_)
                && !($s instanceof Stmt\InlineHTML)
                && !($s instanceof Stmt\Nop)
                && !($s instanceof Stmt\Const_)
                && !($s instanceof Stmt\Namespace_)     // folded via its own bucket
                && !($s instanceof Stmt\HaltCompiler);
        };

        $root = [];
        foreach ($stmts as $s) {
            if ($s instanceof Stmt\Namespace_) {
                $inner = [];
                foreach ($s->stmts as $nsStmt) {
                    if ($foldable($nsStmt)) {
                        $inner[] = $nsStmt;
                    }
                }
                $groups[] = ['namespace' => $s->name?->toString(), 'stmts' => $inner];
                continue;
            }
            if ($foldable($s)) {
                $root[] = $s;
            }
        }
        if ($root !== []) {
            array_unshift($groups, ['namespace' => null, 'stmts' => $root]);
        }
        // Empty-scope buckets (bracketed namespace with only declarations)
        // carry nothing to fold — drop them.
        return array_values(array_filter($groups, static fn (array $g): bool => $g['stmts'] !== []));
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
    /** @var SplObjectStorage<Node, int> */
    private SplObjectStorage $nodeCounts;

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
        $this->nodeCounts = new SplObjectStorage();
    }

    #[\Override]
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
        ($this->sink)($block);
        return null;
    }

    #[\Override]
    public function leaveNode(Node $node)
    {
        if ($node instanceof Stmt\Class_ || $node instanceof Stmt\Interface_ || $node instanceof Stmt\Trait_ || $node instanceof Stmt\Enum_) {
            array_pop($this->classStack);
        }
        if ($node instanceof Stmt\Namespace_) {
            $this->namespace = null;
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

    /**
     * Returns the number of descendant nodes in $node.
     *
     * SSOT: delegates to AstSerializer::nodeCount() — the single canonical
     * implementation.  Results are cached in an SplObjectStorage keyed by
     * node identity, so repeated calls on the same node object return
     * instantly.  The cache lives on the BlockVisitor instance, which is
     * created fresh for each extract() call, so it is also per-extraction.
     */
    private function nodeCount(Node $node): int
    {
        if ($this->nodeCounts->offsetExists($node)) {
            return $this->nodeCounts[$node];
        }
        $count = AstSerializer::nodeCount($node);
        $this->nodeCounts[$node] = $count;
        return $count;
    }
}
