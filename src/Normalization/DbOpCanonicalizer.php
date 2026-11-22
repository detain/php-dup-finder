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
 *     `__DB_READ__("users", "SELECT", __DB_COLS_*())`.
 *
 *   SQL-promoted synthetics carry a **payload signature**: the third
 *   argument is a structural name token `__DB_COLS_<sorted set>`
 *   holding the statement's column set, so "same table + verb,
 *   different columns" lands in the near-duplicate tiers instead of
 *   the fabricated type-1 EXACT class (engine-003 half B). The set
 *   rides a name token because aggressive mode erases literal values
 *   to `__STR`; the deliberately unterminated name (no trailing `__`)
 *   keeps it out of {@see \Phpdup\Semantic\DbOperationTagger}'s
 *   synthetic-op parse so it never mints a bogus op tag.
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
 *
 * One exception to pure-name matching: the **generic name tier**
 * (`get` / `all` / `save` — the stock METHOD_OPS entries flagged
 * {@see DbOpRegistry::ENTRY_FLAG_GENERIC}, surfaced by
 * {@see DbOpRegistry::genericNameTier()}) is folded only when the
 * call carries DB evidence. Those verbs are
 * ubiquitous on non-DB objects (a cache `store->get()`, a
 * `logger->get()` or a `Cache::get()` facade read are not database
 * reads), and name-only folding of them fabricated EXACT "DB clones"
 * of unrelated code. Evidence arms: a table-literal builder seed
 * anywhere in the receiver chain (`DB::table('users')->…->get()`,
 * `$x->table('users')->get()`), a receiver variable/property whose
 * name is a recognised DB word ({@see DEFAULT_RECEIVER_EVIDENCE_NAMES},
 * extendable via the constructor), or — at a statically-rooted chain
 * or static call — a class whose last segment is itself an evidence
 * name (`Model::where(…)->get()`). A tier name the user re-mapped to
 * a *different* op than the stock classification in the registry
 * bypasses the gate; a verbatim re-statement (what bundled profiles
 * ship) does not. Composer-backed class signals for plain domain
 * models (`User::all()`) are admitted through the constructor seam
 * {@see $extraReceiverEvidenceNames}; wiring a config bucket for them
 * (db_symbols class names) is a booked follow-up that touches files
 * outside this leg's locks, so until it lands such chains stay
 * unfolded — under-folding is lossless, fabrication is not.
 */
final class DbOpCanonicalizer extends NodeVisitorAbstract
{
    /** AST attribute key for the canonical op. */
    public const ATTR_OP = 'phpdup.dbOp';

    /**
     * Cap on payload-signature members: statements touching more
     * columns than this contribute their first (sorted) {@see
     * MAX_SIGNATURE_COLUMNS} members plus an `n<total>` arity tail —
     * coarse by design, deterministic always (engine-003 half B).
     */
    private const MAX_SIGNATURE_COLUMNS = 12;

    /**
     * Receiver-name evidence for the generic name tier: lower-cased
     * variable / property names that identify a database-ish object at
     * a glance (`$db`, `$pdo`, `$this->connection`, `$repository`, …).
     * Deliberately excludes generic container words like `store`,
     * `cache` or `logger` — those are exactly the non-DB receivers the
     * gate exists to protect.
     *
     * @var list<string>
     */
    public const DEFAULT_RECEIVER_EVIDENCE_NAMES = [
        'db', 'dbc', 'dbh', 'dbal', 'pdo', 'dsn', 'conn', 'connection',
        'link', 'mysqli', 'pgsql', 'postgres', 'sqlite', 'sqlsrv', 'odbc',
        'em', 'entitymanager', 'repository', 'repo', 'dao', 'mapper',
        'query', 'builder', 'querybuilder', 'qb', 'stmt', 'statement',
        'sth', 'cursor', 'resultset', 'doctrine', 'eloquent',
        'model', 'entity', 'record', 'table',
    ];

    /** @var array<string,true> Lower-cased evidence names, ready for isset(). */
    private readonly array $receiverEvidenceNames;

    /**
     * @param list<string> $extraReceiverEvidenceNames Additional receiver
     *                                                 names treated as DB
     *                                                 evidence. Internal
     *                                                 extension seam only:
     *                                                 no config key admits
     *                                                 these yet (a later leg
     *                                                 wires them), so this
     *                                                 is not public API.
     */
    public function __construct(
        private readonly DbOpRegistry $registry = new DbOpRegistry(),
        array $extraReceiverEvidenceNames = [],
    ) {
        $this->receiverEvidenceNames = array_fill_keys(
            array_merge(
                self::DEFAULT_RECEIVER_EVIDENCE_NAMES,
                array_map('strtolower', $extraReceiverEvidenceNames),
            ),
            true,
        );
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

    /**
     * Return type intentionally bare (engine-039, PHP-8.1-floor
     * convention per AGENTS.md): the previous `?Node\Expr\FuncCall` was
     * narrower than the NodeVisitor contract itself — a latent
     * signature-compatibility trap. A null return is php-parser's "no
     * replacement" signal; the explicit @return names only the parts
     * actually returned so the inherited `null|int|Node|Node[]` union
     * stops leaking into analysis. The rewrite helpers keep their
     * precise types.
     *
     * @return null|Node Replacement node, or null to leave it as-is
     */
    #[\Override]
    public function leaveNode(Node $node)
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
        // Receiver-evidence gate for statics (engine-003 F-2): a bare
        // `Cache::get()` / `Config::get()` facade read carries no more
        // DB signal than an instance `store->get()` — the tier verbs
        // need the same proof here, with the class' last segment as
        // the name-evidence candidate. Non-tier verbs (`User::find()`)
        // keep folding on the registry name alone, as before. Plain
        // model names (`User::all()`) fold once `user` is admitted as
        // evidence via the constructor seam; the config bucket that
        // would feed it automatically is a booked follow-up — see the
        // class docblock.
        if (
            $this->registry->requiresReceiverEvidence($node->name->name)
            && !$this->classSegmentIsEvidence($node->class)
        ) {
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
                        $this->extractColumnSet($sqlArg),
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

        // Receiver-evidence gate (engine-003): generic CRUD verbs
        // (`get` / `all` / `save`) are ordinary method names on cache
        // stores, loggers and collections — folding them on name alone
        // fabricates EXACT DB clones of non-DB code. Refuse the fold
        // unless the receiver side carries DB evidence.
        if (
            $this->registry->requiresReceiverEvidence($methodName)
            && !$this->hasReceiverEvidence($node)
        ) {
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
                        $this->extractColumnSet($sqlArg),
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
                        $this->extractColumnSet($sqlArg),
                    );
                }
            }
        }

        return $this->buildSynthetic($op, null, $node->getAttributes());
    }

    /**
     * Build the canonical `__DB_<OP>__("table"[, "VERB"[,
     * __DB_COLS_<set>()]])` FuncCall.
     *
     * The synthetic call deliberately discards user-supplied arguments
     * — the table + (optional) SQL verb + (optional) payload column
     * set is the load-bearing signal for clustering. Keeping arity
     * stable across ORM/raw-SQL variants is the whole point:
     * Eloquent's `User::find($id)` and Doctrine's
     * `$em->find(User::class, $id)` both fold to
     * `__DB_READ__("user")`, regardless of the user-supplied args.
     *
     * @param array<string,mixed> $attrs
     * @param string|null $columnSet Sorted payload column set joined
     *                               with `_` (see
     *                               {@see extractColumnSet()}); rides
     *                               a structural nested call name
     *                               `__DB_COLS_<set>` instead of a
     *                               string literal, because the
     *                               aggressive literal pass erases
     *                               string values to `__STR` — and
     *                               is deliberately NOT terminated
     *                               with `__`, so
     *                               {@see \Phpdup\Semantic\DbOperationTagger}
     *                               's `__DB_<OP>__` fallback parse
     *                               never mistakes it for an op tag.
     */
    private function buildSynthetic(
        string $op,
        ?string $tableOrEntity,
        array $attrs,
        ?string $verb = null,
        ?string $columnSet = null,
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
        if ($columnSet !== null && $columnSet !== '') {
            $synthArgs[] = new Node\Arg(new Node\Expr\FuncCall(
                new Node\Name('__DB_COLS_' . $columnSet),
            ));
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
     * Coarse payload signature for SQL-promoted synthetics
     * (engine-003 half B): the sorted, de-duplicated, lower-cased set
     * of column identifiers a raw statement explicitly names —
     * INSERT/REPLACE column lists, SET assignment targets and SELECT
     * projection terms. `null` when the statement names none (e.g.
     * `DELETE FROM …`, `SET NAMES …`), which keeps the synthetic's
     * shape identical to the pre-signature form for payload-less
     * statements.
     *
     * Two clones on the same table+verb that touch different columns
     * then produce different canonical streams — near-duplicate
     * territory — instead of the fabricated type-1 EXACT class the
     * finding repros. Two spellings of the same column set (case,
     * order, backtick quoting, `t.` qualification) stay identical, so
     * genuine same-payload clones still cluster.
     *
     * Deliberately coarse: WHERE predicates are ignored, `*` is one
     * member, expressions reduce to their leading identifier, and
     * more than {@see MAX_SIGNATURE_COLUMNS} members are truncated to
     * the alphabetically-first slice plus an `n<total>` arity tail
     * (payloads sharing the first slice and total collide — a known,
     * accepted coarseness). Members join with `_`, so a single column
     * literally named `credits_updated` aliases the set
     * {credits, updated}; another accepted coarseness. Likewise the
     * MySQL `INSERT … ON DUPLICATE KEY UPDATE` clause: source (2)
     * keys on the `SET` keyword, so upsert assignment columns are
     * not collected and `…(a,b) VALUES … ON DUPLICATE KEY UPDATE c
     * = 3` carries only the insert list `a_b` — ODKU-differing twins
     * keep folding EXACT (third accepted coarseness; pinned by
     * testOnDuplicateKeyUpdateColumnsAreOutsideTheSignature in
     * DbOpCanonicalizerTest, so widening the clause into the set
     * later is a deliberate, pin-noticing act — engine-S06b r1
     * LOW-1).
     */
    private function extractColumnSet(string $sql): ?string
    {
        /** @var array<string,true> $cols */
        $cols = [];

        // 1) INSERT/REPLACE `(c1, c2, …)` list. The `(?!values)` guard
        //    stops the no-column-list form `INSERT INTO t VALUES (…)`
        //    from reading its VALUES tuple as the list.
        if (
            preg_match(
                '/\b(?:INSERT|REPLACE)\s+(?:IGNORE\s+)?(?:INTO\s+)?(?!values\b)[^\s(]+\s*\(([^()]*)\)/i',
                $sql,
                $m
            ) === 1
        ) {
            foreach (explode(',', $m[1]) as $part) {
                $id = self::signatureColumn($part);
                if ($id !== null) {
                    $cols[$id] = true;
                }
            }
        }

        // 2) SET assignment targets — the identifier left of each `=`
        //    between SET and WHERE. Covers `UPDATE … SET` and the
        //    MySQL `INSERT … SET` variant. The MySQL `INSERT … ON
        //    DUPLICATE KEY UPDATE` clause carries no `SET` keyword and
        //    is therefore out of scope — documented boundary above.
        if (preg_match('/\bSET\b(.*?)(?=\bWHERE\b|$)/is', $sql, $m) === 1) {
            if (preg_match_all('/(`?[A-Za-z_][A-Za-z0-9_`"]*(?:\s*\.\s*`?[A-Za-z_][A-Za-z0-9_`"]*)?)\s*=(?!=)/', $m[1], $mm) > 0) {
                foreach ($mm[1] as $part) {
                    $id = self::signatureColumn($part);
                    if ($id !== null) {
                        $cols[$id] = true;
                    }
                }
            }
        }

        // 3) SELECT projection list — between SELECT (after an
        //    optional modifier) and the first FROM. The first
        //    `SELECT … FROM` span in the statement (the outer
        //    projection, or the first sub-query's when there is no
        //    outer one) joins the set; coarse.
        if (
            preg_match(
                '/\bSELECT\s+(?:DISTINCT\s+|HIGH_PRIORITY\s+|SQL_CALC_FOUND_ROWS\s+)?(.*?)\s+FROM\s/is',
                $sql,
                $m
            ) === 1
        ) {
            foreach (explode(',', $m[1]) as $part) {
                $id = self::projectionColumn($part);
                if ($id !== null) {
                    $cols[$id] = true;
                }
            }
        }

        if ($cols === []) {
            return null;
        }

        $set = array_keys($cols);
        sort($set, SORT_STRING);
        $total = count($set);
        if ($total > self::MAX_SIGNATURE_COLUMNS) {
            $set   = array_slice($set, 0, self::MAX_SIGNATURE_COLUMNS);
            $set[] = 'n' . $total;
        }
        return implode('_', $set);
    }

    /**
     * Bare column identifier from one raw SQL fragment: trims, strips
     * every backtick/`"`/`[]` quoting layer from each dotted segment,
     * reduces a dotted name to its last segment, lower-cases. Null
     * when the fragment is not a plain (possibly qualified, possibly
     * quoted) identifier.
     */
    private static function signatureColumn(string $raw): ?string
    {
        // Identifier quoting is removed per-segment so `` `u`.`col` ``
        // and `u.col` and `col` all reduce to `col`.
        $unquoted = str_replace(['`', '"', '[', ']'], '', trim($raw));
        if ($unquoted === '') {
            return null;
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\s*\.\s*[A-Za-z_][A-Za-z0-9_]*)*$/', $unquoted) !== 1) {
            return null;
        }
        $segments = preg_split('/\s*\.\s*/', $unquoted) ?: [$unquoted];
        return strtolower((string) end($segments));
    }

    /**
     * Signature member for one SELECT projection term: `*` and
     * `t.*` collapse to `*`; an `AS alias` tail is dropped; a plain
     * (qualified) identifier contributes its last segment; anything
     * else (function calls, expressions) contributes its first
     * identifier, so `COUNT(*)` reads as `count`. Null only when the
     * term holds no identifier at all (a bare number).
     */
    private static function projectionColumn(string $part): ?string
    {
        $part = trim($part);
        if ($part === '') {
            return null;
        }
        if ($part === '*' || preg_match('/[`A-Za-z_][A-Za-z0-9_`]*\s*\.\s*\*$/', $part) === 1) {
            return '*';
        }
        $part = (string) preg_replace('/\s+AS\s+\S+$/i', '', $part);
        $bare = self::signatureColumn($part);
        if ($bare !== null) {
            return $bare;
        }
        if (preg_match('/[A-Za-z_][A-Za-z0-9_]*/', $part, $m) === 1) {
            return strtolower($m[0]);
        }
        return null;
    }

    /**
     * For Doctrine-style entity-class methods, the first arg may be a
     * class-const fetch (`User::class`) or a string literal table name.
     * Surface it as the entity and let the caller drop it from the
     * synthetic args.
     *
     * Call args are typed as php-parser 5.9 declares them — a key-
     * unspecified array of Arg|VariadicPlaceholder|ArgPlaceholder. A
     * placeholder is not an entity and the Arg guard below already
     * returns null for it (and for a missing 0 key).
     *
     * @param array<array-key,Node\Arg|Node\VariadicPlaceholder|Node\ArgPlaceholder> $args
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
     * Evidence oracle for the generic name tier, instance calls (see
     * the class docblock). Three strict arms; each carries actual DB
     * signal. Deliberately NOT the table-extraction helper: any
     * static-rooted chain yields a non-null "entity" there (the
     * class-name fallback is right for labelling, wrong for
     * evidence), which let `Cache::tags('x')->get($k)` re-fold to the
     * bug's fabricated EXACT hash (engine-003 F-3).
     *
     *   1. **Seed literal** — a `table()` / `from()` / `into()` call
     *      with a string-literal argument anywhere in the receiver
     *      chain (`DB::table('users')->…->get()`, `$x->table('users')->get()`).
     *   2. **Receiver name** — a variable or property hop along the
     *      chain (`$db`, `$this->connection`, `$repository`) whose
     *      name is in {@see $receiverEvidenceNames} — whole-word.
     *   3. **Evidence class seed** — the chain bottoms out at a
     *      static call whose class' last segment is itself an
     *      evidence name (`Model::where(…)->get()`). Domain models
     *      whose class name has not been admitted via the evidence
     *      seam (`User::where(…)->get()`) stay unfolded, which is the
     *      lossless direction until the config wiring leg feeds them.
     *
     * Stops at anything that is not a plain property/method/array hop
     * (function-call results, expressions); no hop, no evidence.
     */
    private function hasReceiverEvidence(Node\Expr $node): bool
    {
        $cursor = $node;
        for ($i = 0; $i < 10; $i++) {
            $var = $cursor->var ?? null;
            if ($var === null) {
                return false;
            }
            if ($var instanceof Node\Expr\Variable && is_string($var->name)) {
                return isset($this->receiverEvidenceNames[strtolower($var->name)]);
            }
            if ($var instanceof Node\Expr\PropertyFetch || $var instanceof Node\Expr\NullsafePropertyFetch) {
                if (
                    $var->name instanceof Node\Identifier
                    && isset($this->receiverEvidenceNames[strtolower($var->name->name)])
                ) {
                    return true;
                }
                $cursor = $var;
                continue;
            }
            if ($var instanceof Node\Expr\MethodCall || $var instanceof Node\Expr\NullsafeMethodCall) {
                if ($this->seedLiteralIn($var) !== null) {
                    return true;
                }
                $cursor = $var;
                continue;
            }
            if ($var instanceof Node\Expr\ArrayDimFetch) {
                $cursor = $var;
                continue;
            }
            if ($var instanceof Node\Expr\StaticCall) {
                return $this->seedLiteralIn($var) !== null
                    || $this->classSegmentIsEvidence($var->class);
            }
            return false;
        }
        return false;
    }

    /**
     * The table name when $call is a builder seed — `table('users')` /
     * `from('users')` / `into('users')` with a string-literal first
     * argument — otherwise null. Used as the strict structural
     * evidence arm and mirroring the seed verbs
     * {@see extractTableFromBuilderChain()} extracts.
     */
    private function seedLiteralIn(
        Node\Expr\MethodCall|Node\Expr\NullsafeMethodCall|Node\Expr\StaticCall $call
    ): ?string {
        if (!$call->name instanceof Node\Identifier) {
            return null;
        }
        if (!in_array(strtolower($call->name->name), ['table', 'from', 'into'], true)) {
            return null;
        }
        $arg0 = $call->args[0] ?? null;
        if ($arg0 instanceof Node\Arg && $arg0->value instanceof Node\Scalar\String_) {
            return $arg0->value->value;
        }
        return null;
    }

    /**
     * Whether a static-call class is an evidence-bearing name by its
     * last segment (`App\Models\Repository` → `repository`). Dynamic
     * class expressions carry no name signal and are refused.
     */
    private function classSegmentIsEvidence(Node\Name|Node\Expr $class): bool
    {
        if (!$class instanceof Node\Name) {
            return false;
        }
        return isset($this->receiverEvidenceNames[strtolower($this->lastSegment($class->toString()))]);
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
     * The list is typed as php-parser 5.9 declares call args — key-
     * unspecified array of Arg|VariadicPlaceholder|ArgPlaceholder. Only
     * real Args carry a value; the `!instanceof Arg` skip below already
     * ignores both placeholder kinds.
     *
     * @param array<array-key,Node\Arg|Node\VariadicPlaceholder|Node\ArgPlaceholder> $args
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
