<?php
declare(strict_types=1);

namespace Phpdup\Normalization;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Phpdup\Extraction\Block;
use Phpdup\Util\CanonicalNodePool;

/**
 * Rewrites a Block's AST into a canonical form for hashing/clustering.
 *
 * Three orthogonal canonicalizations, each toggled by mode:
 *
 *   1. Identifier canonicalization. Local variables and parameters are
 *      renamed to $__V0, $__V1, … in first-occurrence order. Two
 *      structurally identical functions whose only difference is local
 *      variable names produce identical canonical ASTs.
 *
 *   2. Literal canonicalization. Scalar literals (string/int/float) are
 *      replaced by typed sentinel values: "__STR", 0, 0.0. The original
 *      values are preserved on the *original* AST so anti-unification
 *      can recover them.
 *
 *   3. Name canonicalization (aggressive only). Function names, method
 *      names, property names, class-string keys, and class names in
 *      `new X(…)`/`X::foo(…)` are replaced by sentinel names like
 *      __CALL, __PROP, __KEY, __CLASS. This is the canonicalization
 *      that lets two SQL-builders or two CRUD handlers cluster.
 *
 * Modes:
 *   strict      — only #1
 *   default     — #1 + #2
 *   aggressive  — #1 + #2 + #3   (default for the tool)
 *
 * The original AST is not mutated; the canonical AST lives at
 * Block::canonical and is a deep clone.
 */
final class Normalizer
{
    public function __construct(
        private readonly string $mode = 'aggressive',
        private readonly ?PluginRegistry $plugins = null,
        private readonly bool $dbAware = false,
        private readonly ?DbOpRegistry $dbOpRegistry = null,
        private readonly bool $trinityCollapse = false,
        private readonly bool $lowMemory = false,
        private readonly ?CanonicalNodePool $nodePool = null,
    ) {
    }

    public function normalize(Block $block): void
    {
        $clone = self::deepClone($block->ast);

        // Pre-pass A — trinity-collapse (option 2).
        // Detects read → mutate → save and rewrites the three
        // statements as a single `__DB_UPSERT__("entity")` call. Runs
        // BEFORE DbOpCanonicalizer so it can pattern-match on the
        // original read/save call shapes; the synthetic upsert it
        // produces uses a `__DB_` prefix and is therefore preserved
        // by name canonicalisation.
        if ($this->trinityCollapse) {
            $registry = $this->dbOpRegistry ?? new DbOpRegistry();
            (new TrinityCollapser($registry))->apply($clone);
        }

        // Pre-pass B — rewrite recognised DB calls into canonical
        // `__DB_<OP>__(...)` synthetic FuncCall nodes BEFORE the
        // generic visitor runs so name canonicalisation sees the
        // synthetic op-name (which it preserves verbatim — the
        // `__DB_` prefix is treated as structural).
        if ($this->dbAware) {
            $dbCanon = new DbOpCanonicalizer($this->dbOpRegistry ?? new DbOpRegistry());
            $dbCanon->apply($clone);
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new CanonicalizingVisitor($this->mode, $this->plugins));
        $stmts = $traverser->traverse([$clone]);
        $block->canonical = $stmts[0];

        // Low-memory mode: walk the canonical AST and intern each leaf node
        // so identical subtrees across blocks deduplicate to shared instances.
        if ($this->lowMemory && $this->nodePool !== null) {
            $this->applyNodePool($block->canonical, $this->nodePool);
        }
    }

    /**
     * Deep-clone a PhpParser Node recursively without serialization overhead.
     *
     * PhpParser nodes are plain PHP objects with properties that may contain:
     * - Scalar values (string, int, float, bool)
     * - Arrays of child nodes
     * - Single child nodes
     * - null
     */
    public static function deepClone(Node $node): Node
    {
        return self::copyNode($node);
    }

    /**
     * Walk a canonical AST and intern each leaf node via $pool so that
     * structurally identical subtrees across blocks share the same object
     * references — reducing RSS on large corpora at the cost of some speed.
     * Composite nodes (nodes with children) are not interned since mutating
     * one would corrupt all sharers; only leaf nodes benefit from dedup.
     */
    private function applyNodePool(Node $node, CanonicalNodePool $pool): void
    {
        // Intern the current node; for leaf nodes this returns a pooled
        // instance (potentially the same object ref if already seen).
        $node = $pool->intern($node);

        // For composite nodes, recurse into children. For leaf nodes,
        // intern() returned $node unchanged and we skip the recursion.
        $reflection = new \ReflectionClass($node);
        foreach ($reflection->getProperties() as $property) {
            $property->setAccessible(true);
            if (!$property->isInitialized($node)) {
                continue;
            }
            $value = $property->getValue($node);
            if ($value instanceof Node) {
                $this->applyNodePool($value, $pool);
            } elseif (is_array($value)) {
                foreach ($value as $k => $v) {
                    if ($v instanceof Node) {
                        $this->applyNodePool($v, $pool);
                    }
                }
            }
        }
    }

    /**
     * Recursively copy a node and all its child nodes.
     *
     * @internal
     */
    private static function copyNode(Node $node): Node
    {
        $clone = (clone $node);

        $reflection = new \ReflectionClass($node);
        foreach ($reflection->getProperties() as $property) {
            $property->setAccessible(true);

            if (!$property->isInitialized($clone)) {
                continue;
            }

            $value = $property->getValue($clone);

            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                $property->setValue($clone, self::copyArray($value));
            } elseif ($value instanceof Node) {
                $property->setValue($clone, self::copyNode($value));
            }
        }

        return $clone;
    }

    /**
     * Deep-copy an array, cloning any Node instances encountered.
     *
     * @param array<mixed> $array
     * @return array<mixed>
     */
    private static function copyArray(array $array): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            if ($value instanceof Node) {
                $result[$key] = self::copyNode($value);
            } elseif (is_array($value)) {
                $result[$key] = self::copyArray($value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}

/** @internal */
final class CanonicalizingVisitor extends NodeVisitorAbstract
{
    /** @var array<string,string> */
    private array $varRenames = [];
    private int $varCounter = 0;

    /** @var array<string,string> */
    private array $callRenames = [];
    private int $callCounter = 0;

    /** @var array<string,string> */
    private array $propRenames = [];
    private int $propCounter = 0;

    /** @var array<string,string> */
    private array $classRenames = [];
    private int $classCounter = 0;

    public function __construct(
        private readonly string $mode,
        private readonly ?PluginRegistry $plugins = null,
    ) {
    }

    public function leaveNode(Node $node): ?Node
    {
        // Transform Match_ and Switch_ into a shared __MATCH__ synthetic
        // FuncCall so they serialize to identical token streams and can
        // cluster together. Return the replacement node to the traverser.
        if ($node instanceof Node\Expr\Match_) {
            return $this->normalizeMatchAsSwitch($node);
        }
        if ($node instanceof Node\Stmt\Switch_) {
            // Switch_ is a Stmt but normalizeSwitchAsMatch returns an Expr.
            // Wrap it in Return so the traverser accepts the replacement AND
            // the result has the same structure (Return_(expr=FuncCall))
            // as the Match_ normalization which also produces Return_(FuncCall).
            return new Node\Stmt\Return_($this->normalizeSwitchAsMatch($node));
        }
        return null;
    }

    public function enterNode(Node $node)
    {
        $this->canonicalizeVariables($node);
        if ($this->mode === 'strict') {
            return null;
        }
        $this->canonicalizeLiterals($node);
        $this->canonicalizeNamedArgs($node);
        if ($this->mode !== 'aggressive') {
            return null;
        }
        $this->canonicalizeNames($node);
        $this->canonicalizeAttributes($node);

        // User-defined passes run last so they see the canonical form
        // produced by the built-in passes.
        if ($this->plugins !== null) {
            foreach ($this->plugins->plugins() as $plugin) {
                $plugin->visit($node, $this->mode);
            }
        }
        return null;
    }

    /**
     * Match_ and Switch_ surface canonicalisation.
     *
     * `match (x) { 1 => foo(), 2 => bar(), default => baz() }` and the
     * equivalent `switch (x) { case 1: foo(); break; case 2: bar(); break;
     * default: baz(); break; }` carry different node types but mean the
     * same thing. Both are rewritten to a synthetic `__MATCH__(subject,
     * cond1, body1, cond2, body2, ...)` FuncCall so they produce identical
     * serialized tokens and can cluster.
     */
    private function normalizeMatchAsSwitch(Node\Expr\Match_ $node): Node\Expr\FuncCall
    {
        $args = [new Node\Arg($node->cond)]; // subject expression

        foreach ($node->arms as $arm) {
            if ($arm->conds === null) {
                // default arm — use 'default' string as sentinel key
                $args[] = new Node\Arg(new Node\Scalar\String_('default'));
            } else {
                // single or multi-cond arm — OR-chain multiple conditions
                $count = count($arm->conds);
                if ($count === 1) {
                    $args[] = new Node\Arg($arm->conds[0]);
                } else {
                    $condArg = $arm->conds[0];
                    for ($i = 1; $i < $count; $i++) {
                        $condArg = new Node\Expr\BinaryOp\BooleanOr($condArg, $arm->conds[$i]);
                    }
                    $args[] = new Node\Arg($condArg);
                }
            }

            $args[] = new Node\Arg($arm->body);
        }

        return new Node\Expr\FuncCall(
            new Node\Name('__MATCH__'),
            $args,
        );
    }

    /**
     * Canonicalise Switch_ into the same __MATCH__ synthetic FuncCall
     * shape that normalizeMatchAsSwitch produces for Match_.
     */
    private function normalizeSwitchAsMatch(Node\Stmt\Switch_ $node): Node\Expr\FuncCall
    {
        $args = [new Node\Arg($node->cond)]; // subject expression

        foreach ($node->cases as $case) {
            if ($case->cond === null) {
                // default case — use 'default' string as sentinel key
                $args[] = new Node\Arg(new Node\Scalar\String_('default'));
            } else {
                $args[] = new Node\Arg($case->cond);
            }

            // For case body: use the last statement if it's a Return,
            // otherwise wrap all statements in a closure.
            if (count($case->stmts) === 1 && $case->stmts[0] instanceof Node\Stmt\Return_) {
                $body = $case->stmts[0]->expr;
            } else {
                $body = new Node\Expr\Closure(['stmts' => $case->stmts]);
            }

            $args[] = new Node\Arg($body);
        }

        return new Node\Expr\FuncCall(
            new Node\Name('__MATCH__'),
            $args,
        );
    }

    /**
     * Sort named arguments into a canonical (lexicographical) order.
     *
     * `foo(name: 1, age: 2)` and `foo(age: 2, name: 1)` are
     * semantically equivalent. The token serializer would treat them
     * as different. Reorder named args to a stable order so they
     * cluster.
     *
     * Positional args (no `name`) keep their original order — they're
     * order-significant.
     */
    private function canonicalizeNamedArgs(Node $node): void
    {
        if (!property_exists($node, 'args') || !is_array($node->args ?? null)) {
            return;
        }
        $positional = [];
        $named      = [];
        foreach ($node->args as $arg) {
            if ($arg instanceof Node\Arg && $arg->name instanceof Node\Identifier) {
                $named[] = $arg;
            } else {
                $positional[] = $arg;
            }
        }
        if (count($named) <= 1) {
            return;
        }
        usort($named, static function (Node\Arg $a, Node\Arg $b): int {
            $an = $a->name instanceof Node\Identifier ? $a->name->name : '';
            $bn = $b->name instanceof Node\Identifier ? $b->name->name : '';
            return strcmp($an, $bn);
        });
        $node->args = array_merge($positional, $named);
    }

    /**
     * In `aggressive` mode, drop attribute decorations entirely so two
     * methods that differ only by `#[...]` annotations cluster
     * together. In `default` mode they remain on the canonical AST so
     * structurally identical methods with different attribute payloads
     * stay separate.
     */
    private function canonicalizeAttributes(Node $node): void
    {
        if (property_exists($node, 'attrGroups') && is_array($node->attrGroups ?? null)) {
            /** @phpstan-ignore-next-line — every node carrying attrGroups accepts an array */
            $node->attrGroups = [];
        }
    }

    private function canonicalizeVariables(Node $node): void
    {
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            if ($node->name === 'this') {
                return; // keep $this — semantically distinct
            }
            $node->name = $this->getOrAssign($this->varRenames, $node->name, '__V', $this->varCounter);
            return;
        }
        if ($node instanceof Node\Param && $node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
            if ($node->var->name !== 'this') {
                $node->var->name = $this->getOrAssign($this->varRenames, $node->var->name, '__V', $this->varCounter);
            }
            return;
        }
        // Function and method declaration names are container labels, not
        // body content — collapse so two equally-shaped functions with
        // different names produce identical canonical hashes.
        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod) {
            $node->name = new Node\Identifier('__FN');
        }
    }

    private function canonicalizeLiterals(Node $node): void
    {
        if ($node instanceof Node\Scalar\String_) {
            $node->value = '__STR';
            return;
        }
        if ($node instanceof Node\Scalar\Int_) {
            $node->value = 0;
            return;
        }
        if ($node instanceof Node\Scalar\Float_) {
            $node->value = 0.0;
            return;
        }
        if ($node instanceof Node\Scalar\InterpolatedString) {
            foreach ($node->parts as $p) {
                if ($p instanceof Node\InterpolatedStringPart) {
                    $p->value = '__STR';
                }
            }
        }
    }

    private function canonicalizeNames(Node $node): void
    {
        // method/function call names
        if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) {
            $node->name = new Node\Identifier($this->getOrAssign($this->callRenames, $node->name->name, '__CALL', $this->callCounter));
            return;
        }
        if ($node instanceof Node\Expr\NullsafeMethodCall && $node->name instanceof Node\Identifier) {
            $node->name = new Node\Identifier($this->getOrAssign($this->callRenames, $node->name->name, '__CALL', $this->callCounter));
            return;
        }
        if ($node instanceof Node\Expr\StaticCall && $node->name instanceof Node\Identifier) {
            $node->name = new Node\Identifier($this->getOrAssign($this->callRenames, $node->name->name, '__CALL', $this->callCounter));
            // also fold the class name
            if ($node->class instanceof Node\Name) {
                $node->class = new Node\Name($this->getOrAssign($this->classRenames, $node->class->toString(), '__CLASS', $this->classCounter));
            }
            return;
        }
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $name = $node->name->toString();
            // keep core functions that change structural meaning of clone
            if (!self::isStructuralFunction($name)) {
                $node->name = new Node\Name($this->getOrAssign($this->callRenames, $name, '__CALL', $this->callCounter));
            }
            return;
        }
        // property access
        if ($node instanceof Node\Expr\PropertyFetch && $node->name instanceof Node\Identifier) {
            $node->name = new Node\Identifier($this->getOrAssign($this->propRenames, $node->name->name, '__PROP', $this->propCounter));
            return;
        }
        if ($node instanceof Node\Expr\NullsafePropertyFetch && $node->name instanceof Node\Identifier) {
            $node->name = new Node\Identifier($this->getOrAssign($this->propRenames, $node->name->name, '__PROP', $this->propCounter));
            return;
        }
        if ($node instanceof Node\Expr\StaticPropertyFetch && $node->name instanceof Node\VarLikeIdentifier) {
            $node->name = new Node\VarLikeIdentifier($this->getOrAssign($this->propRenames, $node->name->name, '__PROP', $this->propCounter));
            return;
        }
        // class constant fetch and `new X`
        if ($node instanceof Node\Expr\ClassConstFetch && $node->class instanceof Node\Name) {
            $node->class = new Node\Name($this->getOrAssign($this->classRenames, $node->class->toString(), '__CLASS', $this->classCounter));
            return;
        }
        if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
            $node->class = new Node\Name($this->getOrAssign($this->classRenames, $node->class->toString(), '__CLASS', $this->classCounter));
            return;
        }
        if ($node instanceof Node\Expr\Instanceof_ && $node->class instanceof Node\Name) {
            $node->class = new Node\Name($this->getOrAssign($this->classRenames, $node->class->toString(), '__CLASS', $this->classCounter));
            return;
        }
    }

    /**
     * @param array<string,string> $map
     */
    private function getOrAssign(array &$map, string $original, string $prefix, int &$counter): string
    {
        if (!isset($map[$original])) {
            $map[$original] = $prefix . $counter++;
        }
        return $map[$original];
    }

    /**
     * Functions whose semantics change clustering — keep their names.
     *
     * Synthetic `__DB_<OP>__` calls produced by
     * {@see DbOpCanonicalizer} are treated structurally so the op
     * name survives the aggressive name pass; without this, two
     * canonicalised DB ops would collapse to `__CALL0` and lose the
     * very signal we just synthesised.
     */
    private static function isStructuralFunction(string $name): bool
    {
        static $keep = [
            'isset' => true, 'empty' => true, 'unset' => true,
            'count' => true, 'is_array' => true, 'is_null' => true,
            'is_string' => true, 'is_int' => true, 'is_numeric' => true,
            'array_map' => true, 'array_filter' => true, 'array_reduce' => true,
        ];
        if (isset($keep[strtolower($name)])) {
            return true;
        }
        // Synthetic DB-op tokens (see DbOpCanonicalizer).
        return str_starts_with($name, '__DB_');
    }
}
