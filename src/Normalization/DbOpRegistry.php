<?php
declare(strict_types=1);

namespace Phpdup\Normalization;

/**
 * Registry of DB-call → canonical-op mappings used by
 * {@see DbOpCanonicalizer}.
 *
 * The registry classifies every recognised database call into a small
 * set of canonical operations (`db.read`, `db.write`, `db.delete`,
 * `db.execute`, `db.query`) so that semantically equivalent calls
 * across ORMs, query builders, and raw drivers fold to identical
 * tokens during normalisation.
 *
 * **Stock entries** cover the most common PHP database stacks:
 *
 *   - **Eloquent / Laravel**: `Model::find()`, `Model::create()`,
 *     `Model::where()->first()`, `DB::table()`, `DB::select()`.
 *   - **Doctrine ORM**: `EntityManager::find()`, `Repository::find()`,
 *     `EntityManager::flush()`, `EntityManager::persist()`.
 *   - **PDO**: `PDO::query()`, `PDO::prepare()`, `PDOStatement::execute()`,
 *     `PDOStatement::fetch()`, `PDOStatement::fetchAll()`.
 *   - **mysqli**: `mysqli::query()`, `mysqli::prepare()`,
 *     `mysqli_query()`, `mysqli_stmt_execute()`, `mysqli_fetch_assoc()`.
 *   - **PostgreSQL ext**: `pg_query()`, `pg_query_params()`,
 *     `pg_fetch_assoc()`, `pg_fetch_all()`.
 *   - **Generic patterns**: any method literally named `find`,
 *     `findById`, `save`, `update`, `delete`, `query`, `execute`
 *     on an unknown receiver — coarse but high-recall.
 *
 * The registry is intentionally biased toward **high recall**: a few
 * benign false positives (e.g. matching a non-DB `query()` method on
 * an unrelated class) are preferable to missing real ORM ↔ raw-SQL
 * clones. Reporters surface the `db-op` cluster tag so reviewers see
 * which clusters were formed via this canonicalisation.
 *
 * **Customisation**: callers can pass a `customMethodOps` map to the
 * constructor to override or extend the built-in dispatch table.
 * Per-profile overrides land via {@see profiles/db-aware-*.json}
 * (option 4 — symbol-equivalence-class registry).
 *
 * @internal Used by {@see DbOpCanonicalizer}.
 */
final class DbOpRegistry
{
    public const OP_READ    = 'db.read';
    public const OP_WRITE   = 'db.write';
    public const OP_DELETE  = 'db.delete';
    public const OP_EXECUTE = 'db.execute';
    public const OP_QUERY   = 'db.query';

    /**
     * Method names → canonical op (case-insensitive lookup).
     *
     * @var array<string,string>
     */
    private const METHOD_OPS = [
        // Reads
        'find'           => self::OP_READ,
        'findorfail'     => self::OP_READ,
        'findbyid'       => self::OP_READ,
        'findone'        => self::OP_READ,
        'findoneby'      => self::OP_READ,
        'findall'        => self::OP_READ,
        'first'          => self::OP_READ,
        'firstorfail'    => self::OP_READ,
        'get'            => self::OP_READ,
        'all'            => self::OP_READ,
        'fetch'          => self::OP_READ,
        'fetchall'       => self::OP_READ,
        'fetchone'       => self::OP_READ,
        'fetchassoc'     => self::OP_READ,
        'fetchcolumn'    => self::OP_READ,
        'fetchrow'       => self::OP_READ,
        'fetchobject'    => self::OP_READ,
        'getresult'      => self::OP_READ,
        'select'         => self::OP_READ,
        'selectone'      => self::OP_READ,
        // NOTE: `where`, `whereIn`, `orderBy`, `limit`, `select` (as a
        // builder), `with`, `join` and other intermediate query-builder
        // chain methods are intentionally NOT mapped — they return a
        // Builder, not a result, so the *terminal* call (e.g. ->get(),
        // ->first(), ->update()) is what carries the canonical op.
        // {@see DbOpCanonicalizer::extractTableFromBuilderChain()}
        // walks up the chain from the terminal call to recover the
        // table/entity name from the seed call.

        // Writes
        'save'           => self::OP_WRITE,
        'create'         => self::OP_WRITE,
        'update'         => self::OP_WRITE,
        'updateorcreate' => self::OP_WRITE,
        'firstorcreate'  => self::OP_WRITE,
        'insert'         => self::OP_WRITE,
        'insertgetid'    => self::OP_WRITE,
        'persist'        => self::OP_WRITE,
        'flush'          => self::OP_WRITE,
        'push'           => self::OP_WRITE,
        'replace'        => self::OP_WRITE,
        'upsert'         => self::OP_WRITE,

        // Deletes
        'delete'         => self::OP_DELETE,
        'destroy'        => self::OP_DELETE,
        'remove'         => self::OP_DELETE,
        'forcedelete'    => self::OP_DELETE,
        'truncate'       => self::OP_DELETE,

        // Generic execute / query
        'execute'        => self::OP_EXECUTE,
        'exec'           => self::OP_EXECUTE,
        'query'          => self::OP_QUERY,
        'rawquery'       => self::OP_QUERY,
        'prepare'        => self::OP_EXECUTE,
        'statement'      => self::OP_EXECUTE,
    ];

    /**
     * Function names → canonical op (case-insensitive lookup).
     *
     * Procedural drivers (mysqli_*, pg_*, mssql_*, sqlite_*) and the
     * legacy mysql_* extension all map cleanly here.
     *
     * @var array<string,string>
     */
    private const FUNCTION_OPS = [
        // mysqli (procedural)
        'mysqli_query'         => self::OP_QUERY,
        'mysqli_real_query'    => self::OP_QUERY,
        'mysqli_prepare'       => self::OP_EXECUTE,
        'mysqli_stmt_execute'  => self::OP_EXECUTE,
        'mysqli_execute_query' => self::OP_QUERY,
        'mysqli_fetch_assoc'   => self::OP_READ,
        'mysqli_fetch_array'   => self::OP_READ,
        'mysqli_fetch_row'     => self::OP_READ,
        'mysqli_fetch_object'  => self::OP_READ,
        'mysqli_fetch_all'     => self::OP_READ,
        'mysqli_num_rows'      => self::OP_READ,
        'mysqli_insert_id'     => self::OP_WRITE,

        // postgres
        'pg_query'             => self::OP_QUERY,
        'pg_query_params'      => self::OP_QUERY,
        'pg_prepare'           => self::OP_EXECUTE,
        'pg_execute'           => self::OP_EXECUTE,
        'pg_fetch_assoc'       => self::OP_READ,
        'pg_fetch_array'       => self::OP_READ,
        'pg_fetch_row'         => self::OP_READ,
        'pg_fetch_object'      => self::OP_READ,
        'pg_fetch_all'         => self::OP_READ,
        'pg_num_rows'          => self::OP_READ,
        'pg_insert'            => self::OP_WRITE,
        'pg_update'            => self::OP_WRITE,
        'pg_delete'            => self::OP_DELETE,

        // legacy mysql_*
        'mysql_query'          => self::OP_QUERY,
        'mysql_fetch_assoc'    => self::OP_READ,
        'mysql_fetch_array'    => self::OP_READ,
        'mysql_fetch_row'      => self::OP_READ,
        'mysql_fetch_object'   => self::OP_READ,
        'mysql_num_rows'       => self::OP_READ,
        'mysql_insert_id'      => self::OP_WRITE,

        // sqlsrv
        'sqlsrv_query'         => self::OP_QUERY,
        'sqlsrv_prepare'       => self::OP_EXECUTE,
        'sqlsrv_execute'       => self::OP_EXECUTE,
        'sqlsrv_fetch'         => self::OP_READ,
        'sqlsrv_fetch_array'   => self::OP_READ,
        'sqlsrv_fetch_object'  => self::OP_READ,

        // sqlite3
        'sqlite_query'         => self::OP_QUERY,
        'sqlite_fetch_array'   => self::OP_READ,
        'sqlite_fetch_object'  => self::OP_READ,

        // Firebird / InterBase
        'ibase_query'         => self::OP_QUERY,
        'ibase_prepare'       => self::OP_EXECUTE,
        'ibase_execute'       => self::OP_EXECUTE,
        'ibase_fetch_row'     => self::OP_READ,
        'ibase_fetch_assoc'   => self::OP_READ,
        'ibase_fetch_object'  => self::OP_READ,
        'ibase_free_result'   => self::OP_READ,
        'ibase_num_rows'      => self::OP_READ,
        'ibase_insert_id'     => self::OP_WRITE,
        'ibase_affected_rows' => self::OP_READ,
        'ibase_commit'        => self::OP_WRITE,
        'ibase_rollback'      => self::OP_WRITE,
        'ibase_trans'         => self::OP_WRITE,

        // MSSQL / DB-Library (php-dblib or old mssql extension)
        'mssql_query'         => self::OP_QUERY,
        'mssql_fetch_row'     => self::OP_READ,
        'mssql_fetch_array'   => self::OP_READ,
        'mssql_fetch_assoc'   => self::OP_READ,
        'mssql_fetch_object'  => self::OP_READ,
        'mssql_num_rows'      => self::OP_READ,
        'mssql_affected_rows' => self::OP_READ,
        'mssql_free_result'   => self::OP_READ,
        'mssql_close'         => self::OP_READ,
        'mssql_connect'       => self::OP_READ,

        // IBM DB2 / ODBC
        'db2_prepare'         => self::OP_EXECUTE,
        'db2_execute'         => self::OP_EXECUTE,
        'db2_query'           => self::OP_QUERY,
        'db2_fetch_row'       => self::OP_READ,
        'db2_fetch_assoc'     => self::OP_READ,
        'db2_fetch_array'     => self::OP_READ,
        'db2_fetch_object'    => self::OP_READ,
        'db2_num_rows'        => self::OP_READ,
        'db2_affected_rows'   => self::OP_READ,
        'db2_free_result'     => self::OP_READ,
        'db2_get_last_insert_id' => self::OP_WRITE,
    ];

    /** @var array<string,string> Method-name lookup, lower-cased. */
    private array $methodOps;

    /** @var array<string,string> Function-name lookup, lower-cased. */
    private array $functionOps;

    /**
     * @param array<string,string> $customMethodOps   Additional or overriding method → op entries.
     * @param array<string,string> $customFunctionOps Additional or overriding function → op entries.
     */
    public function __construct(array $customMethodOps = [], array $customFunctionOps = [])
    {
        $this->methodOps = self::METHOD_OPS;
        foreach ($customMethodOps as $name => $op) {
            $this->methodOps[strtolower($name)] = $op;
        }

        $this->functionOps = self::FUNCTION_OPS;
        foreach ($customFunctionOps as $name => $op) {
            $this->functionOps[strtolower($name)] = $op;
        }
    }

    /**
     * Look up the canonical op for a method-call name.
     *
     * @return string|null One of the OP_* constants, or null when the
     *                     method name is not recognised as a DB op.
     */
    public function lookupMethod(string $name): ?string
    {
        return $this->methodOps[strtolower($name)] ?? null;
    }

    /**
     * Look up the canonical op for a function-call name.
     *
     * @return string|null One of the OP_* constants, or null when the
     *                     function name is not recognised as a DB op.
     */
    public function lookupFunction(string $name): ?string
    {
        return $this->functionOps[strtolower($name)] ?? null;
    }

    /**
     * Build a default registry containing the stock entries shipped
     * with phpdup. Equivalent to `new DbOpRegistry()`.
     */
    public static function default(): self
    {
        return new self();
    }
}
