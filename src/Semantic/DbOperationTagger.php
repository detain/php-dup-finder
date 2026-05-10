<?php
declare(strict_types=1);

namespace Phpdup\Semantic;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Phpdup\Normalization\DbOpRegistry;

/**
 * Walks an AST and produces a `tag → count` multiset of recognised
 * database operations.
 *
 * The summary is a coarse, dialect-agnostic shape: a function that
 * does one DB read and one DB write looks the same as another
 * function with one read and one write, regardless of whether the
 * libraries are Eloquent, Doctrine, PDO, mysqli, raw SQL, or any
 * mix. This is the input to the type-4 tag-Jaccard scoring band
 * added by option 3 of `docs/plans/orm-db-semantic-dedup.md`.
 *
 * **Example summary**
 *
 *   ['db.read' => 2, 'db.write' => 1, 'db.execute' => 1]
 *
 * means the block performs two reads, one write, and one execute
 * (e.g. a `prepare`+`execute` pair) — the *shape* a similarity
 * scorer can compare against another summary.
 *
 * **Recognition**
 *
 * Reuses the same {@see DbOpRegistry} as
 * {@see \Phpdup\Normalization\DbOpCanonicalizer}, so the recognised
 * call set is identical: Eloquent, Doctrine, query builders, PDO,
 * mysqli (object + procedural), `pg_*`, `sqlsrv_*`, `sqlite_*`,
 * legacy `mysql_*`, plus the generic CRUD verbs.
 *
 * Synthetic `__DB_<OP>__` calls produced by an earlier
 * canonicalisation pass also contribute to the summary — they
 * carry the canonical op as a `phpdup.dbOp` attribute set by
 * {@see \Phpdup\Normalization\DbOpCanonicalizer::ATTR_OP}, which
 * the tagger picks up directly so the tag stream stays consistent
 * regardless of whether the input AST is original or canonicalised.
 */
final class DbOperationTagger
{
    public function __construct(
        private readonly DbOpRegistry $registry = new DbOpRegistry(),
    ) {
    }

    /**
     * Tag every recognised DB call in $node and return a
     * `tag → count` multiset.
     *
     * Stops at function boundaries within the block: nested closures
     * are walked for tags too because they typically capture and
     * mutate the same database state. Callers wanting a strict
     * single-frame summary should run the tagger on the block's
     * direct stmts instead of the whole node.
     *
     * @return array<string,int>
     */
    public function tag(Node $node): array
    {
        $tags = [];

        $finder = new NodeFinder();
        foreach ($finder->find([$node], static function (Node $n) {
            return $n instanceof Node\Expr\MethodCall
                || $n instanceof Node\Expr\NullsafeMethodCall
                || $n instanceof Node\Expr\StaticCall
                || $n instanceof Node\Expr\FuncCall;
        }) as $call) {
            $op = $this->lookup($call);
            if ($op !== null) {
                $tags[$op] = ($tags[$op] ?? 0) + 1;
            }
        }
        return $tags;
    }

    /**
     * Resolve a single call node to its canonical op tag, or null
     * if unrecognised.
     *
     * Order of resolution:
     *   1. `phpdup.dbOp` attribute (set by DbOpCanonicalizer when
     *      the call has already been canonicalised).
     *   2. Synthetic `__DB_<OP>__(…)` function names (parsed by the
     *      visible suffix when the attribute is missing — e.g. when
     *      the AST was deserialized without preserving attributes).
     *   3. Registry lookup against the original method/function name.
     *
     * Accepts Node (not Node\Expr) because the {@see NodeFinder}
     * iteration return type is the broader Node base. The first
     * branch's instanceof guards re-narrow before any access.
     */
    private function lookup(Node $call): ?string
    {
        $attr = $call->getAttribute('phpdup.dbOp');
        if (is_string($attr) && $attr !== '') {
            return $attr;
        }
        if ($call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name) {
            $name = $call->name->toString();
            if (str_starts_with($name, '__DB_') && str_ends_with($name, '__')) {
                $op = strtolower(substr($name, 5, -2));
                return 'db.' . $op;
            }
            return $this->registry->lookupFunction($name);
        }
        if ($call instanceof Node\Expr\StaticCall && $call->name instanceof Node\Identifier) {
            return $this->registry->lookupMethod($call->name->name);
        }
        if (($call instanceof Node\Expr\MethodCall
            || $call instanceof Node\Expr\NullsafeMethodCall)
            && $call->name instanceof Node\Identifier
        ) {
            return $this->registry->lookupMethod($call->name->name);
        }
        return null;
    }
}
