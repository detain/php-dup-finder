<?php
declare(strict_types=1);

namespace Phpdup\Normalization;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * AST pass that rewrites recognised database calls into canonical
 * synthetic function-call nodes so equivalent ORM and raw-SQL
 * variants produce identical token streams.
 *
 * **What it does**
 *
 *   - `User::find($id)` / `$em->find(User::class, $id)` /
 *     `DB::table('users')->find($id)` →
 *     `__DB_FIND__("users", $id)` (the table arg uses the entity name
 *     when the ORM is class-keyed and the literal table when it's a
 *     query-builder).
 *
 *   - `$pdo->query("SELECT * FROM users WHERE id = ?")` /
 *     `mysqli_query($db, "SELECT * FROM users WHERE id = ?")` /
 *     `pg_query($conn, "SELECT * FROM users WHERE id = $1")` →
 *     `__DB_QUERY__("SELECT", "users")`.
 *
 *   - `$user->save()` / `$em->flush()` / `Model::create([...])` →
 *     `__DB_WRITE__("?", ...)` (table inferred from receiver when
 *     possible, "?" otherwise).
 *
 *   - Generic CRUD verbs (`update`, `delete`, `insert`, `fetch`, …)
 *     fold to the matching `__DB_<OP>__` token.
 *
 * **Naming convention**
 *
 * Synthetic calls always use a function-call (`Node\Expr\FuncCall`)
 * with a name beginning `__DB_` — this prefix is treated as a
 * structural keyword by {@see CanonicalizingVisitor::isStructuralFunction()}
 * so subsequent name canonicalisation does **not** rewrite it to
 * `__CALL0`, preserving the op signal in the n-gram fingerprint.
 *
 * **Order of operations**
 *
 * `DbOpCanonicalizer` runs as a **pre-pass** before the main
 * {@see CanonicalizingVisitor}. It mutates the AST in place; the
 * downstream variable / literal / name passes then operate on the
 * rewritten form.
 *
 * **Tagging**
 *
 * After a successful rewrite the canonicalizer attaches a
 * `phpdup.dbOp` attribute to the synthesised node carrying the
 * canonical op (one of the `DbOpRegistry::OP_*` constants). Other
 * subsystems (pattern recognition, behavioural similarity, future
 * trinity-collapse) can read this without re-doing the lookup.
 *
 * **Risk profile**
 *
 * Aggressive — a non-DB `query()` or `find()` method on an unrelated
 * class will still be rewritten. The canonicalizer is therefore
 * gated behind `db_aware: true` (CLI: `--db-aware`) and is **off by
 * default**. Tier-1 AST-only clustering remains the unmodified path.
 */
final class DbOpCanonicalizer extends NodeVisitorAbstract
{
    /** AST attribute key for the canonical op. */
    public const ATTR_OP = 'phpdup.dbOp';

    public function __construct(
        private readonly DbOpRegistry $registry = new DbOpRegistry(),
    ) {
    }

    /**
     * Apply DB-op canonicalisation to a single AST root (a Block's
     * canonical node).
     *
     * Mutates $root in-place so subsequent normalisation passes see
     * the rewritten form.
     */
    public function apply(Node $root): void
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor($this);
        $traverser->traverse([$root]);
    }

    public function leaveNode(Node $node): ?Node\Expr\FuncCall
    {
        // Static call: ClassName::method(...)
        if ($node instanceof Node\Expr\StaticCall && $node->name instanceof Node\Identifier) {
            return $this->rewriteStaticCall($node);
        }

        // Instance call: $x->method(...) and $x?->method(...)
        if (
            ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall)
            && $node->name instanceof Node\Identifier
        ) {
            return $this->rewriteMethodCall($node);
        }

        // Function call: foo(...)
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            return $this->rewriteFuncCall($node);
        }

        return null;
    }

    private function rewriteStaticCall(Node\Expr\StaticCall $node): ?Node\Expr\FuncCall
    {
        if (!$node->name instanceof Node\Identifier) {
            return null;
        }
        $op = $this->registry->lookupMethod($node->name->name);
        if ($op === null) {
            return null;
        }
        // For `DB::select("...")`, `DB::statement("...")`, surface the
        // verb + table from the SQL string arg if present.
        if ($op === DbOpRegistry::OP_QUERY || $op === DbOpRegistry::OP_EXECUTE
            || $op === DbOpRegistry::OP_READ) {
            $sqlArg = $this->firstStringArg($node->args);
            if ($sqlArg !== null) {
                $parsed = SqlTableExtractor::extract($sqlArg);
                if ($parsed !== null) {
                    [$verb, $table] = $parsed;
                    return $this->buildSynthetic(
                        $this->verbToOp($verb),
                        $table,
                        $node->getAttributes(),
                        $verb,
                    );
                }
            }
        }
        $entity = $node->class instanceof Node\Name
            ? $this->lastSegment($node->class->toString())
            : null;
        return $this->buildSynthetic($op, $entity, $node->getAttributes());
    }

    /**
     * @param Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall $node
     */
    private function rewriteMethodCall(Node\Expr $node): ?Node\Expr\FuncCall
    {
        if (!$node->name instanceof Node\Identifier) {
            return null;
        }
        $methodName = $node->name->name;
        $op = $this->registry->lookupMethod($methodName);
        if ($op === null) {
            return null;
        }

        // For `query()` / `exec()` / `prepare()` the first string arg
        // is usually raw SQL — promote (verb, table) into the synthetic
        // args so the verb shows up as a token.
        if ($op === DbOpRegistry::OP_QUERY || $op === DbOpRegistry::OP_EXECUTE) {
            $sqlArg = $this->firstStringArg($node->args);
            if ($sqlArg !== null) {
                $parsed = SqlTableExtractor::extract($sqlArg);
                if ($parsed !== null) {
                    [$verb, $table] = $parsed;
                    return $this->buildSynthetic(
                        $this->verbToOp($verb),
                        $table,
                        $node->getAttributes(),
                        $verb,
                    );
                }
            }
        }

        // Doctrine-style: `$repo->find(User::class, $id)` carries the
        // entity as the first class-const arg. Surface it.
        $entity = $this->extractEntityFromFirstArg($node->args);

        // Builder receivers like `DB::table('users')->where(...)`
        // expose the table name as the first arg of the seed call.
        // Walk the receiver chain to find a `table('...')` literal.
        if ($entity === null) {
            $entity = $this->extractTableFromBuilderChain($node);
        }
        return $this->buildSynthetic($op, $entity, $node->getAttributes());
    }

    private function rewriteFuncCall(Node\Expr\FuncCall $node): ?Node\Expr\FuncCall
    {
        if (!$node->name instanceof Node\Name) {
            return null;
        }
        $name = $node->name->toString();

        // Skip already-canonical synthetic calls so we don't loop.
        if (str_starts_with($name, '__DB_')) {
            return null;
        }

        $op = $this->registry->lookupFunction($name);
        if ($op === null) {
            return null;
        }

        // Extract verb + table from the first SQL-string arg (if any).
        if ($op === DbOpRegistry::OP_QUERY || $op === DbOpRegistry::OP_EXECUTE) {
            $sqlArg = $this->firstStringArg($node->args);
            if ($sqlArg !== null) {
                $parsed = SqlTableExtractor::extract($sqlArg);
                if ($parsed !== null) {
                    [$verb, $table] = $parsed;
                    return $this->buildSynthetic(
                        $this->verbToOp($verb),
                        $table,
                        $node->getAttributes(),
                        $verb,
                    );
                }
            }
        }

        return $this->buildSynthetic($op, null, $node->getAttributes());
    }

    /**
     * Build the canonical `__DB_<OP>__("table"[, "VERB"])` FuncCall.
     *
     * The synthetic call deliberately discards user-supplied arguments
     * — the table + (optional) SQL verb tuple is the load-bearing
     * signal for clustering. Keeping arity stable across ORM/raw-SQL
     * variants is the whole point: Eloquent's `User::find($id)` and
     * Doctrine's `$em->find(User::class, $id)` both fold to
     * `__DB_READ__("user")`, regardless of the user-supplied args.
     *
     * @param array<string,mixed> $attrs
     */
    private function buildSynthetic(
        string $op,
        ?string $tableOrEntity,
        array $attrs,
        ?string $verb = null,
    ): Node\Expr\FuncCall {
        $synthName = '__DB_' . strtoupper(str_replace('db.', '', $op)) . '__';

        $synthArgs = [];
        $synthArgs[] = new Node\Arg(
            new Node\Scalar\String_($tableOrEntity !== null && $tableOrEntity !== ''
                ? strtolower($tableOrEntity)
                : '?'),
        );
        if ($verb !== null) {
            $synthArgs[] = new Node\Arg(new Node\Scalar\String_(strtoupper($verb)));
        }

        $call = new Node\Expr\FuncCall(
            new Node\Name($synthName),
            $synthArgs,
            $attrs,
        );
        $call->setAttribute(self::ATTR_OP, $op);
        return $call;
    }

    /**
     * For Doctrine-style entity-class methods, the first arg may be a
     * class-const fetch (`User::class`) or a string literal table name.
     * Surface it as the entity and let the caller drop it from the
     * synthetic args.
     *
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
            // Common in DB::table('users') / repo->getRepository('User').
            return $first->value->value;
        }
        return null;
    }

    /**
     * Walk the left-hand chain of a method call looking for the
     * table/entity seed that started the builder chain.
     *
     * Three seed forms are recognised:
     *
     *   1. `DB::table('users')` → `'users'` (literal first arg of the
     *      method whose name is `table`, `from`, or `into`).
     *   2. `Model::where(...)` / `Model::query()` → `'Model'` (last
     *      segment of the static-call class name, used when no
     *      explicit table seed appears earlier in the chain).
     *   3. None — returns null (the caller falls back to `'?'`).
     *
     * The walker traverses at most ~10 levels to avoid pathological
     * deep chains, and stops as soon as a non-call receiver is hit.
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
                // No literal table seed; surface the class name as the entity.
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

    /**
     * Return the SQL extracted from the first scalar-string-like
     * argument, or null. Recognises:
     *
     *   - `Node\Scalar\String_` — the common case, single/double-quoted.
     *   - `Node\Scalar\InterpolatedString` — `"SELECT * FROM x WHERE
     *     id = {$id}"` joins its literal parts so the verb/table
     *     extractor still gets a usable shape.
     *   - `Node\Expr\BinaryOp\Concat` of strings — common in
     *     `'SELECT * FROM ' . $table` patterns; we recover the static
     *     prefix.
     *
     * @param array<int,Node\Arg|Node\VariadicPlaceholder> $args
     */
    private function firstStringArg(array $args): ?string
    {
        foreach ($args as $arg) {
            if (!$arg instanceof Node\Arg) {
                continue;
            }
            $extracted = $this->staticTextFromExpr($arg->value);
            if ($extracted !== null) {
                return $extracted;
            }
        }
        return null;
    }

    /**
     * Recover the static-text portion of a string-shaped expression
     * for downstream SQL parsing. Interpolated holes are replaced
     * with a placeholder marker so the SqlTableExtractor's identifier
     * regex still skips past them cleanly.
     */
    private function staticTextFromExpr(Node\Expr $expr): ?string
    {
        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }
        if ($expr instanceof Node\Scalar\InterpolatedString) {
            $out = '';
            foreach ($expr->parts as $part) {
                if ($part instanceof Node\InterpolatedStringPart) {
                    $out .= $part->value;
                } else {
                    $out .= ' ? ';
                }
            }
            return $out;
        }
        if ($expr instanceof Node\Expr\BinaryOp\Concat) {
            $left  = $this->staticTextFromExpr($expr->left);
            $right = $this->staticTextFromExpr($expr->right);
            if ($left === null && $right === null) {
                return null;
            }
            return ($left ?? ' ? ') . ($right ?? ' ? ');
        }
        return null;
    }

    /** Map a SQL verb to the canonical op constant. */
    private function verbToOp(string $verb): string
    {
        return match (strtoupper($verb)) {
            'SELECT'             => DbOpRegistry::OP_READ,
            'INSERT', 'REPLACE'  => DbOpRegistry::OP_WRITE,
            'UPDATE'             => DbOpRegistry::OP_WRITE,
            'DELETE', 'TRUNCATE' => DbOpRegistry::OP_DELETE,
            default              => DbOpRegistry::OP_QUERY,
        };
    }

    /** `App\Models\User` → `User`. */
    private function lastSegment(string $name): string
    {
        $parts = explode('\\', $name);
        return (string)end($parts);
    }
}
