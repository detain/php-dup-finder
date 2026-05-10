<?php
declare(strict_types=1);

namespace Phpdup\Normalization;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Collapses the canonical CRUD trinity — *read → mutate → save* —
 * into a single synthetic `__DB_UPSERT__("entity")` token so the
 * ORM-flavoured equivalent of a raw `UPDATE` clusters with the raw
 * `UPDATE`.
 *
 * **The pattern**
 *
 * ```php
 * $user = User::find($id);          // read     (any DbOpRegistry::OP_READ)
 * $user->name = 'Bob';              // mutate   (property or setter, 1+ times)
 * $user->save();                    // save     (DbOpRegistry::OP_WRITE)
 * ```
 *
 * …rewrites to a single statement:
 *
 * ```php
 * __DB_UPSERT__('user');
 * ```
 *
 * which clusters trivially with the raw equivalent
 * `__DB_QUERY__('users', 'UPDATE')` (after DbOpCanonicalizer runs)
 * because both blocks reduce to the same shape.
 *
 * **Recognised read variants**
 *
 *   - Static call: `Model::find($id)`, `Model::findOrFail($id)`,
 *     `Model::firstWhere(...)`.
 *   - Instance call: `$em->find(User::class, $id)`,
 *     `$repo->findOneBy([...])`, `$db->table('users')->find($id)`.
 *
 * **Recognised mutate variants**
 *
 *   - Property assignment: `$x->name = 'Bob'`, `$x->name .= 'X'`.
 *   - Setter method:        `$x->setName('Bob')`.
 *
 * **Recognised save variants**
 *
 *   - Active Record style: `$x->save()`, `$x->update([...])`.
 *   - Doctrine style:      `$em->flush()`, `$em->persist($x)`.
 *
 * **Conservative dataflow**
 *
 * The collapser tracks the *bound variable* of the read and only
 * absorbs mutate/save statements that target the same variable. A
 * stray non-mutate statement breaks the chain — the collapser then
 * abandons that read and resumes scanning. Doctrine's `flush()` is
 * accepted as a save terminator regardless of receiver, since by
 * convention it persists everything in the unit-of-work; this is
 * reasonable for the surface patterns we care about.
 *
 * **Risk profile**
 *
 * Slightly higher than option 1 (DbOpCanonicalizer alone): false
 * matches happen when an unrelated statement is misclassified as a
 * mutate. The walker is intentionally short-circuited to keep
 * pattern coverage narrow — false misses (a real trinity not
 * collapsed) are preferable to false matches. Off by default; gated
 * behind `--trinity-collapse` (CLI) / `trinity_collapse: true`
 * (JSON).
 */
final class TrinityCollapser extends NodeVisitorAbstract
{
    public function __construct(
        private readonly DbOpRegistry $registry = new DbOpRegistry(),
    ) {
    }

    /**
     * Apply trinity collapse to a single AST root.
     *
     * Mutates $root in-place. Idempotent: running twice produces the
     * same shape because synthetic `__DB_UPSERT__` calls aren't reads.
     */
    public function apply(Node $root): void
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor($this);
        $traverser->traverse([$root]);
    }

    /**
     * Walk every node that carries a `stmts` statement-array and
     * collapse trinities inside it. Functions, methods, closures,
     * if/else branches, loop bodies, try/catch — anything with a
     * stmt list — gets the same scan.
     */
    public function leaveNode(Node $node): ?Node
    {
        if (property_exists($node, 'stmts') && is_array($node->stmts ?? null)) {
            /** @var list<Node\Stmt> $stmts */
            $stmts = $node->stmts;
            $collapsed = $this->collapse($stmts);
            if ($collapsed !== $stmts) {
                $node->stmts = $collapsed;
            }
        }
        // If_ also has elseifs and an else with their own stmts,
        // already covered by leaveNode visiting them recursively.
        return null;
    }

    /**
     * Scan a flat stmt array for read-mutate-save trinities. Returns
     * the original array unchanged when no trinity is found.
     *
     * @param list<Node\Stmt> $stmts
     * @return list<Node\Stmt>
     */
    public function collapse(array $stmts): array
    {
        $out = [];
        $i = 0;
        $n = count($stmts);
        while ($i < $n) {
            $found = $this->matchTrinity($stmts, $i);
            if ($found === null) {
                $out[] = $stmts[$i];
                $i++;
                continue;
            }
            [$consumed, $entity] = $found;
            $out[] = $this->buildUpsert($entity, $stmts[$i]->getAttributes());
            $i += $consumed;
        }
        return $out;
    }

    /**
     * If `$stmts[$start]` opens a trinity, return
     * `[stmtsConsumed, entity]`. Otherwise return null.
     *
     * @param list<Node\Stmt> $stmts
     * @return array{0:int,1:?string}|null
     */
    private function matchTrinity(array $stmts, int $start): ?array
    {
        $first = $stmts[$start] ?? null;
        if (!$first instanceof Node\Stmt\Expression
            || !$first->expr instanceof Node\Expr\Assign
        ) {
            return null;
        }

        // Read-target variable must be a plain variable.
        if (!$first->expr->var instanceof Node\Expr\Variable
            || !is_string($first->expr->var->name)
        ) {
            return null;
        }
        $varName = $first->expr->var->name;

        // RHS must be a recognised read.
        $entity = $this->classifyRead($first->expr->expr);
        if ($entity === null) {
            return null;
        }

        $mutateCount = 0;
        $j = $start + 1;
        $n = count($stmts);

        while ($j < $n) {
            $next = $stmts[$j];
            if ($this->isMutateOf($next, $varName)) {
                $mutateCount++;
                $j++;
                continue;
            }
            if ($this->isSaveOf($next, $varName)) {
                $j++;
                break;
            }
            // Unrelated statement — abandon trinity.
            return null;
        }

        // Trinity must have read + 1+ mutates + save (we just consumed save).
        // After the loop, $j points one past save.
        if ($mutateCount === 0) {
            return null;
        }
        return [$j - $start, $entity[1]];
    }

    /**
     * If $expr is a recognised DB read call, return
     * `[op, entity]`; otherwise null.
     *
     * @return array{0:string,1:?string}|null
     */
    private function classifyRead(Node\Expr $expr): ?array
    {
        if ($expr instanceof Node\Expr\StaticCall && $expr->name instanceof Node\Identifier) {
            $op = $this->registry->lookupMethod($expr->name->name);
            if ($op !== DbOpRegistry::OP_READ) {
                return null;
            }
            $entity = $expr->class instanceof Node\Name
                ? $this->lastSegment($expr->class->toString())
                : null;
            return [$op, $entity];
        }
        if (($expr instanceof Node\Expr\MethodCall
            || $expr instanceof Node\Expr\NullsafeMethodCall)
            && $expr->name instanceof Node\Identifier
        ) {
            $op = $this->registry->lookupMethod($expr->name->name);
            if ($op !== DbOpRegistry::OP_READ) {
                return null;
            }
            // Doctrine: $em->find(User::class, $id) — first arg may be a class const.
            $entity = $this->extractEntityFromFirstArg($expr->args)
                ?? $this->extractTableFromBuilderChain($expr);
            return [$op, $entity];
        }
        return null;
    }

    /**
     * Whether $stmt is a mutation of $varName — either property
     * assignment (`$x->p = ...`, `$x->p .= ...`) or a setter call
     * (`$x->setP(...)`, `$x->p = ...`).
     */
    private function isMutateOf(Node\Stmt $stmt, string $varName): bool
    {
        if (!$stmt instanceof Node\Stmt\Expression) {
            return false;
        }
        $expr = $stmt->expr;

        // $x->prop = … (or any AssignOp variant — .= |= += etc.)
        if ($expr instanceof Node\Expr\Assign
            || $expr instanceof Node\Expr\AssignOp
        ) {
            $target = $expr->var;
            if ($target instanceof Node\Expr\PropertyFetch
                && $target->var instanceof Node\Expr\Variable
                && $target->var->name === $varName
            ) {
                return true;
            }
        }

        // $x->setName(…), $x->withFoo(…) — accept any method call
        // on $x that *looks* like a mutator (set/with/add/remove
        // prefix, or fluent self-returning).
        if ($expr instanceof Node\Expr\MethodCall
            && $expr->var instanceof Node\Expr\Variable
            && $expr->var->name === $varName
            && $expr->name instanceof Node\Identifier
        ) {
            $name = strtolower($expr->name->name);
            foreach (['set', 'with', 'add', 'remove', 'append', 'replace'] as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Whether $stmt is a save terminator: `$x->save()`,
     * `$x->update()`, `$em->flush()`, or `$em->persist($x)`.
     */
    private function isSaveOf(Node\Stmt $stmt, string $varName): bool
    {
        if (!$stmt instanceof Node\Stmt\Expression) {
            return false;
        }
        $expr = $stmt->expr;
        if (!$expr instanceof Node\Expr\MethodCall
            || !$expr->name instanceof Node\Identifier
        ) {
            return false;
        }
        $methodName = strtolower($expr->name->name);

        // $x->save() / $x->update(...) — receiver-bound write.
        if ($expr->var instanceof Node\Expr\Variable
            && $expr->var->name === $varName
        ) {
            $op = $this->registry->lookupMethod($expr->name->name);
            return $op === DbOpRegistry::OP_WRITE;
        }

        // $em->flush() — accept regardless of receiver.
        if ($methodName === 'flush') {
            return true;
        }

        // $em->persist($x) — first arg is the variable.
        if ($methodName === 'persist') {
            $arg0 = $expr->args[0] ?? null;
            return $arg0 instanceof Node\Arg
                && $arg0->value instanceof Node\Expr\Variable
                && $arg0->value->name === $varName;
        }

        return false;
    }

    /**
     * Build a `__DB_UPSERT__("entity")` synthetic statement.
     *
     * @param array<string,mixed> $attrs Source-position attributes from the
     *                                   trinity's first statement.
     */
    private function buildUpsert(?string $entity, array $attrs): Node\Stmt\Expression
    {
        $call = new Node\Expr\FuncCall(
            new Node\Name('__DB_UPSERT__'),
            [new Node\Arg(new Node\Scalar\String_(
                $entity !== null && $entity !== '' ? strtolower($entity) : '?',
            ))],
        );
        $call->setAttribute(DbOpCanonicalizer::ATTR_OP, 'db.upsert');
        return new Node\Stmt\Expression($call, $attrs);
    }

    /**
     * @param array<int,Node\Arg|Node\VariadicPlaceholder> $args
     */
    private function extractEntityFromFirstArg(array $args): ?string
    {
        $first = $args[0] ?? null;
        if (!$first instanceof Node\Arg) {
            return null;
        }
        if ($first->value instanceof Node\Expr\ClassConstFetch
            && $first->value->class instanceof Node\Name
            && $first->value->name instanceof Node\Identifier
            && strtolower($first->value->name->name) === 'class'
        ) {
            return $this->lastSegment($first->value->class->toString());
        }
        if ($first->value instanceof Node\Scalar\String_) {
            return $first->value->value;
        }
        return null;
    }

    /**
     * Walk the receiver chain of a method-call read to find a
     * `table('users')` / `from('users')` seed call, mirroring the
     * walker in {@see DbOpCanonicalizer}.
     */
    private function extractTableFromBuilderChain(Node\Expr $node): ?string
    {
        $cursor = $node;
        for ($i = 0; $i < 10; $i++) {
            $var = $cursor->var ?? null;
            if ($var === null) {
                return null;
            }
            if ($var instanceof Node\Expr\StaticCall) {
                $methodName = $var->name instanceof Node\Identifier ? strtolower($var->name->name) : '';
                if (in_array($methodName, ['table', 'from', 'into'], true)) {
                    $arg0 = $var->args[0] ?? null;
                    if ($arg0 instanceof Node\Arg && $arg0->value instanceof Node\Scalar\String_) {
                        return $arg0->value->value;
                    }
                }
                if ($var->class instanceof Node\Name) {
                    return $this->lastSegment($var->class->toString());
                }
                return null;
            }
            if ($var instanceof Node\Expr\MethodCall || $var instanceof Node\Expr\NullsafeMethodCall) {
                $methodName = $var->name instanceof Node\Identifier ? strtolower($var->name->name) : '';
                if (in_array($methodName, ['table', 'from', 'into'], true)) {
                    $arg0 = $var->args[0] ?? null;
                    if ($arg0 instanceof Node\Arg && $arg0->value instanceof Node\Scalar\String_) {
                        return $arg0->value->value;
                    }
                }
                $cursor = $var;
                continue;
            }
            return null;
        }
        return null;
    }

    /** `App\Models\User` → `User`. */
    private function lastSegment(string $name): string
    {
        $parts = explode('\\', $name);
        return (string)end($parts);
    }
}
