<?php
declare(strict_types=1);

namespace Phpdup\Normalization;

use PhpParser\Node;
use PhpParser\NodeFinder;
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
 *      variable names produce identical canonical ASTs. Declarations
 *      and uses run through one handler (the traverser visits a Param's
 *      Variable child, so a Param-side rename would take a second
 *      slot — engine-055), and a token is never dealt to a name some
 *      user variable of the block literally carries ($__V0 cannot
 *      alias the generated space).
 *
 *   2. Literal canonicalization. Scalar literals (string/int/float) are
 *      replaced by typed sentinel values: "__STR", 0, 0.0. The original
 *      values are preserved on the *original* AST so anti-unification
 *      can recover them.
 *
 *   3. Name canonicalization (aggressive only). Function names, method
 *      names, property names, and class names in `new X(…)`/`X::foo(…)`
 *      are replaced by NAME-STAMPED sentinels like `__CALL_send`,
 *      `__PROP_total`, `__CLASS_Repo`. The stamp is injective on the
 *      original name (not a first-occurrence ordinal), so shared
 *      framework vocabularies — two `->where()->update()` SQL-builders
 *      — still canonicalize to byte-identical streams, while a pair
 *      that only agrees after permuting names across branch roles
 *      cannot (engine-002: the ordinal scheme re-dealt isomorphic maps
 *      for branch-role-swapped code and lied "EXACT" about it).
 *      Cross-vocabulary shape twins drop from the exact tier to the
 *      near-duplicate tiers, where Util\IdentifierIds folds both
 *      ordinal and stamped tokens in lockstep, preserving their
 *      structural similarity.
 *
 * Modes:
 *   strict      — only #1. Declaration names, match/switch shapes,
 *                 literals and everything else stay exactly as written.
 *                 (engine-036 closed two leaks that broke this promise:
 *                 an ungated Match_/Switch_ fold into a shared
 *                 __MATCH__ sentinel, and a __FN declaration wipe that
 *                 ran ahead of the strict bail-out.)
 *   default     — #1 + #2, plus the match/switch fold (a cross-parity
 *                 near-dup heuristic, never active under strict)
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
        $traverser->addVisitor(new CanonicalizingVisitor(
            $this->mode,
            $this->plugins,
            self::collectUserVariableNames($clone),
        ));
        $stmts = $traverser->traverse([$clone]);
        $block->canonical = $stmts[0];

        // Low-memory mode: interning pass over the canonical AST. The
        // root is reassigned because a leaf-shaped root would intern to
        // an earlier pooled instance; composite roots (every extracted
        // block) come back identity-equal.
        if ($this->lowMemory && $this->nodePool !== null) {
            $block->canonical = $this->applyNodePool($block->canonical, $this->nodePool);
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
     * Walk a canonical AST bottom-up, intern each leaf via $pool, and
     * SPLICE the pooled instance into its parent's child slot so
     * structurally identical leaves across blocks genuinely share one
     * object — reducing RSS on large corpora (engine-008: the old pass
     * rebound only its local parameter, so nothing ever entered the
     * tree while the strongly-held first-seen store made the mode a
     * net memory regression).
     *
     * The walk iterates getSubNodeNames() — the public child-slot
     * contract of php-parser nodes — instead of a per-node reflection
     * walk (same finding: the reflection version allocated a
     * ReflectionClass per node, on exactly the corpora the mode
     * targets). Composite nodes pass through intern() unchanged, so
     * only leaf slots are rewritten; the walk returns the interned node
     * for the caller (or normalize()) to install.
     */
    private function applyNodePool(Node $node, CanonicalNodePool $pool): Node
    {
        foreach ($node->getSubNodeNames() as $name) {
            // isset() skips uninitialized typed slots and null children —
            // the same slots the old reflection walk skipped.
            if (!isset($node->$name)) {
                continue;
            }
            $value = $node->$name;
            if ($value instanceof Node) {
                $interned = $this->applyNodePool($value, $pool);
                if ($interned !== $value) {
                    $node->$name = $interned;
                }
            } elseif (is_array($value)) {
                foreach ($value as $k => $v) {
                    if ($v instanceof Node) {
                        $interned = $this->applyNodePool($v, $pool);
                        if ($interned !== $v) {
                            $node->$name[$k] = $interned;
                        }
                    }
                }
            }
        }

        return $pool->intern($node);
    }

    /**
     * Pre-scan the block clone for every user-spelled Variable name.
     *
     * The variable pass deals sentinels from a shared `__Vn` counter;
     * without this set, a user identifier that literally spells a
     * generated name (`$__V0`) could occupy its own slot and alias two
     * distinct variables onto one token once any pass re-enters a
     * renamed node (engine-055 guard — see CanonicalizingVisitor).
     *
     * @return array<string,true>
     */
    private static function collectUserVariableNames(Node $clone): array
    {
        $names = [];
        $finder = new NodeFinder();
        foreach ($finder->findInstanceOf([$clone], Node\Expr\Variable::class) as $variable) {
            /** @var Node\Expr\Variable $variable */
            if (is_string($variable->name)) {
                $names[$variable->name] = true;
            }
        }
        return $names;
    }

    /**
     * Property names eligible for the recursive copy pass, per node
     * class. engine-030: the old walk built a fresh ReflectionClass and
     * enumerated getProperties() for EVERY node (~4.6 µs/node measured);
     * discovery now runs once per class and the hot loop is plain public
     * property access.
     *
     * @var array<class-string<Node>, list<string>>
     */
    private static array $copyableProperties = [];

    /**
     * Recursively copy a node and all its child nodes.
     *
     * The shallow `clone` already value-copies scalars and the whole
     * protected `attributes` array (arrays are copy-on-write by value in
     * PHP); the cached name list then drives the recursive pass over the
     * remaining public instance properties — exactly the property set the
     * old reflection walk copied (see {@see discoverCopyableProperties()}
     * for the static/protected delta, which is a self-assignment no-op
     * and the value-copied attributes array respectively).
     *
     * @internal
     */
    private static function copyNode(Node $node): Node
    {
        $clone = clone $node;

        $names = self::$copyableProperties[$node::class]
            ??= self::discoverCopyableProperties($node::class);

        foreach ($names as $name) {
            // isset() reproduces the old walk's two skips: uninitialized
            // typed properties (isInitialized false) and null values.
            if (!isset($clone->$name)) {
                continue;
            }
            $value = $clone->$name;
            if (is_array($value)) {
                $clone->$name = self::copyArray($value);
            } elseif ($value instanceof Node) {
                $clone->$name = self::copyNode($value);
            }
        }

        return $clone;
    }

    /**
     * Public, non-static instance properties of a node class — the copy
     * set. Statics (String_::$replacements) were re-assigned to equal
     * copies by the old reflection walk; skipping them keeps that no-op
     * off the hot path. `attributes` is the only non-public instance
     * property any node class carries (NodeAbstract::$attributes, and it
     * is value-copied by the shallow clone), so skipping non-publics
     * loses nothing; the explicit name guard documents the dependency.
     *
     * @param class-string<Node> $class
     * @return list<string>
     */
    private static function discoverCopyableProperties(string $class): array
    {
        $names = [];
        foreach ((new \ReflectionClass($class))->getProperties() as $property) {
            if (
                $property->isStatic()
                || !$property->isPublic()
                || $property->getName() === 'attributes'
            ) {
                continue;
            }
            $names[] = $property->getName();
        }
        return $names;
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
    /** Shape of the generated variable-sentinel space (engine-055 guard). */
    private const GENERATED_VAR_SHAPE = '/^__V\d+$/';

    /** @var array<string,string> original user name → generated sentinel */
    private array $varRenames = [];
    private int $varCounter = 0;

    /** @var array<string,true> every user-spelled variable name of this block */
    private readonly array $userVarNames;

    /**
     * @param array<string,true> $userVarNames names collected by
     *        {@see Normalizer::collectUserVariableNames()} before the
     *        traversal, so dealt sentinels can be steered clear of them.
     */
    public function __construct(
        private readonly string $mode,
        private readonly ?PluginRegistry $plugins = null,
        array $userVarNames = [],
    ) {
        $this->userVarNames = $userVarNames;
    }

    /**
     * Return type intentionally bare (engine-039, PHP-8.1-floor
     * convention per AGENTS.md): NodeVisitorAbstract::leaveNode declares
     * no native type and a null return is php-parser's "no replacement"
     * signal. The explicit @return names only the parts this visitor
     * actually returns, so the inherited `null|int|Node|Node[]` contract
     * union stops leaking into analysis.
     *
     * @return null|Node Replacement node, or null to leave it as-is
     */
    #[\Override]
    public function leaveNode(Node $node)
    {
        // engine-036(a): the Match_/Switch_ desugaring below is a
        // cross-parity fold — `match` (=== arms, no fall-through) and
        // `switch` (== arms, fall-through) genuinely differ, and
        // canonicalising both to one __MATCH__ sentinel made a
        // match-vs-switch pair report EXACT even under strict mode.
        // Strict keeps both shapes raw; the fold remains a
        // default/aggressive near-dup heuristic.
        if ($this->mode === 'strict') {
            return null;
        }
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

    /**
     * once-029 (php-dup-finder-once-029): the user-plugin loop used to
     * sit below BOTH mode bail-outs, so a registered plugin only ever
     * saw nodes on the aggressive path — silently dead under
     * strict/default, contradicting the NormalizationPlugin contract.
     * The invocation now closes every mode branch: plugins run once
     * per node, after the built-in passes that particular mode
     * performs, and `visit()` receives the real mode name so a plugin
     * can self-gate. Aggressive ordering is unchanged (same position,
     * factored into runPlugins); strict/default are the newly-honest
     * legs. With no plugins registered the traversal is byte-identical
     * to before in all three modes.
     */
    #[\Override]
    public function enterNode(Node $node)
    {
        $this->canonicalizeVariables($node);
        if ($this->mode === 'strict') {
            $this->runPlugins($node);
            return null;
        }
        $this->canonicalizeLiterals($node);
        $this->canonicalizeNamedArgs($node);
        if ($this->mode !== 'aggressive') {
            $this->runPlugins($node);
            return null;
        }
        $this->canonicalizeNames($node);
        $this->canonicalizeAttributes($node);
        $this->runPlugins($node);
        return null;
    }

    /**
     * Hand the node to every registered plugin once this mode's
     * built-in passes have run over it — user-defined passes run last
     * within their mode so they see the canonical form the built-ins
     * produced (strict: identifier pass; default: + literals, named
     * args; aggressive: + name and attribute passes. The match/switch
     * fold replaces the node in leaveNode, after this visit).
     */
    private function runPlugins(Node $node): void
    {
        if ($this->plugins === null) {
            return;
        }
        foreach ($this->plugins->plugins() as $plugin) {
            $plugin->visit($node, $this->mode);
        }
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
     *
     * Known limitation, accepted under its mode gate (engine-036): a
     * fall-through switch case (`case 1: foo(); case 2: bar(); break;`)
     * folds as if its cases were exclusive arms, so the normalised form
     * does not model the shared execution. The heuristic trades that
     * fidelity for cross-parity grouping in default/aggressive; strict
     * never performs the fold at all, so the mis-fold cannot surface as
     * an EXACT verdict there.
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

            // For case body: a lone `return <expr>;` contributes the value
            // directly; everything else is wrapped as a statement list. The
            // `->expr !== null` arm matters twice over: a bare `return;`
            // carries a null expr (which Arg::__construct rejects — the BUG-A
            // TypeError behind every worker-poison crash on switch-shaped
            // code), and letting it fall into the statement-list branch
            // encodes it EXPLICITLY as Closure{stmts:[Return_(null)]} instead
            // of silently aliasing it to the value-return form it never was.
            // $case->stmts here belongs to Normalizer::normalize()'s deep
            // clone, so wrapping it never mutates the cached original AST.
            if (
                count($case->stmts) === 1
                && $case->stmts[0] instanceof Node\Stmt\Return_
                && $case->stmts[0]->expr !== null
            ) {
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
        // Declarations and uses share ONE handler (engine-055): the old
        // Param branch stamped a sentinel onto $node->var->name during
        // the Param visit, and the traverser then re-entered that very
        // child as a Variable whose (already-rewritten) name missed the
        // map — dealing a second slot, so the declaration canonicalised
        // to __V1 while the body read __V0 and the rename map soaked up
        // generated names as keys. A Param always carries a plain
        // Variable child, so the Variable branch below renames both
        // sites exactly once, in declaration order.
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            if ($node->name === 'this') {
                return; // keep $this — semantically distinct
            }
            $node->name = $this->getOrAssignVar($node->name);
            return;
        }
        // engine-036(b): the __FN wipe is a container-label collapse —
        // a default/aggressive convenience. It used to run ahead of the
        // strict bail-out in enterNode, so two same-shape differently-
        // named methods reported EXACT even under strict, erasing the
        // very identity strict exists to preserve. Strict now keeps
        // declaration names verbatim.
        if ($this->mode === 'strict') {
            return;
        }
        // Function and method declaration names are container labels, not
        // body content — collapse so two equally-shaped functions with
        // different names produce identical canonical hashes.
        if ($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod) {
            $node->name = new Node\Identifier('__FN');
        }
    }

    /**
     * First-occurrence ordinal rename for local identifiers, guarded
     * against the two aliasing classes engine-055 names:
     *
     *  - A sentinel is never dealt to a name some user variable of this
     *    block literally carries, so `$__V0` cannot alias the generated
     *    space (the pathological spelling intentionally stops clumping
     *    with ordinary variables — the trade that buys collision safety).
     *  - A generated-shaped name that is NOT a user variable of the
     *    block is a rename artifact (a pass re-entering an already
     *    renamed node); renaming it again would mint a phantom slot and
     *    pollute the map with generated keys, so it passes through.
     */
    private function getOrAssignVar(string $original): string
    {
        if (isset($this->varRenames[$original])) {
            return $this->varRenames[$original];
        }
        if (preg_match(self::GENERATED_VAR_SHAPE, $original) === 1
            && !isset($this->userVarNames[$original])
        ) {
            return $original;
        }
        do {
            $token = '__V' . $this->varCounter++;
        } while (isset($this->userVarNames[$token]));
        $this->varRenames[$original] = $token;
        return $token;
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

    /**
     * Aggressive-mode name pass: every recognised call/property/class
     * identifier is replaced by its name-stamped sentinel (`__CALL_send`,
     * `__PROP_total`, `__CLASS_Repo`).
     *
     * engine-002: the old scheme numbered these maps by first occurrence
     * within the block. A branch-role-swapped pair (`if($f){$o->send($m)}
     * else{$o->log($e)}` vs the arms exchanged) re-deals the ordinal
     * maps isomorphically — both sides canonicalise to byte-identical
     * streams and the detector stamps the pair EXACT similarity 1.00,
     * which is a behavior inversion, not a type-1 clone. A stamp that
     * embeds the name is injective on the identifier space: the same
     * name is the same token at every position (shared framework
     * vocabularies — the two SQL-builders of the mode's stated purpose
     * — still merge byte-identically), while any permutation of names
     * across arms changes at least one token and can no longer hide
     * behind slot order. Cross-vocabulary shape twins fall out of the
     * exact tier into the Jaccard/TED tiers, where Util\IdentifierIds
     * folds the stamps symmetrically.
     *
     * Variables keep first-occurrence ordinals on purpose: local-name
     * agnosticism is the mode's pass-#1 contract and variable slots
     * carry no cross-block identity claim.
     */
    private function canonicalizeNames(Node $node): void
    {
        // method/function call names
        if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) {
            $node->name = new Node\Identifier('__CALL_' . $node->name->name);
            return;
        }
        if ($node instanceof Node\Expr\NullsafeMethodCall && $node->name instanceof Node\Identifier) {
            $node->name = new Node\Identifier('__CALL_' . $node->name->name);
            return;
        }
        if ($node instanceof Node\Expr\StaticCall && $node->name instanceof Node\Identifier) {
            $node->name = new Node\Identifier('__CALL_' . $node->name->name);
            // also fold the class name
            if ($node->class instanceof Node\Name) {
                $node->class = new Node\Name('__CLASS_' . $node->class->toString());
            }
            return;
        }
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $name = $node->name->toString();
            // keep core functions that change structural meaning of clone
            if (!self::isStructuralFunction($name)) {
                $node->name = new Node\Name('__CALL_' . $name);
            }
            return;
        }
        // property access
        if ($node instanceof Node\Expr\PropertyFetch && $node->name instanceof Node\Identifier) {
            $node->name = new Node\Identifier('__PROP_' . $node->name->name);
            return;
        }
        if ($node instanceof Node\Expr\NullsafePropertyFetch && $node->name instanceof Node\Identifier) {
            $node->name = new Node\Identifier('__PROP_' . $node->name->name);
            return;
        }
        if ($node instanceof Node\Expr\StaticPropertyFetch && $node->name instanceof Node\VarLikeIdentifier) {
            $node->name = new Node\VarLikeIdentifier('__PROP_' . $node->name->name);
            return;
        }
        // class constant fetch and `new X`
        if ($node instanceof Node\Expr\ClassConstFetch && $node->class instanceof Node\Name) {
            $node->class = new Node\Name('__CLASS_' . $node->class->toString());
            return;
        }
        if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
            $node->class = new Node\Name('__CLASS_' . $node->class->toString());
            return;
        }
        if ($node instanceof Node\Expr\Instanceof_ && $node->class instanceof Node\Name) {
            $node->class = new Node\Name('__CLASS_' . $node->class->toString());
            return;
        }
    }

    /**
     * Functions whose semantics change clustering — keep their names.
     *
     * Synthetic `__DB_<OP>__` calls produced by
     * {@see DbOpCanonicalizer} are treated structurally so the op
     * name survives the aggressive name pass; without this, two
     * canonicalised DB ops would collapse to `__CALL___DB_READ__` and
     * the pass would stamp over the very signal we just synthesised.
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
