<?php
declare(strict_types=1);

namespace Phpdup\Ir;

/**
 * Base class for the phpdup intermediate representation (IR).
 *
 * The IR is a canonical, language-/library-agnostic representation
 * of a block's *operations* — what it reads, writes, computes, and
 * branches on — stripped of the surface syntax that distinguishes
 * an Eloquent call from a raw `pg_query`. It is the foundation of
 * option 5 of `docs/plans/orm-db-semantic-dedup.md`.
 *
 * **Why a separate IR**
 *
 * Options 1–4 work by progressively rewriting the PHP AST so two
 * dialect-divergent operations become structurally identical. That
 * scales as far as the rewrites can be made canonical, which is
 * fundamentally limited by the AST's PHP-specific node shapes. The
 * IR side-steps the PHP AST entirely: each IR node represents an
 * operation, not a syntactic form, so semantically equivalent code
 * has byte-identical IR by construction.
 *
 * **Scope**
 *
 * The initial scaffold covers:
 *
 *   - DB operations (`DbRead`, `DbWrite`, `DbDelete`, `DbExecute`,
 *     `DbQuery`).
 *   - Control flow (`Branch` for if/else, `Loop` for for/while/foreach).
 *   - Local data flow (`Assign`, `Var`, `Literal`).
 *   - Generic calls (`Call`) for unrecognised method/function calls.
 *   - Composite blocks (`BlockIr`) that list child IR nodes.
 *   - Returns (`Return_`).
 *
 * Future extension domains (HTTP, file IO, queue, …) plug in by
 * adding sibling node types and extending {@see IrLifter}.
 *
 * **Risk profile**
 *
 * The lift is **lossy by design** — many PHP-specific details (type
 * coercion, magic methods, error suppression, references) are
 * intentionally erased. {@see IrLifter} returns `null` when it
 * can't produce a faithful IR; callers fall back to AST-level
 * scoring on lift failure (per the plan's risk-mitigation note).
 */
abstract class IrNode
{
    /**
     * Short, deterministic identifier for the IR node kind. Used
     * by {@see IrPrinter} to produce a stable token-stream prefix
     * and by {@see IrSimilarity} for kind-shape comparisons.
     */
    abstract public function kind(): string;

    /**
     * Direct child IR nodes in traversal order. Empty for leaves.
     *
     * @return list<IrNode>
     */
    public function children(): array
    {
        return [];
    }

    /**
     * Optional scalar payload contributing to the token stream
     * (e.g. table name, variable name, op symbol). Returning `null`
     * means the node has no scalar contribution beyond its kind.
     */
    public function scalar(): ?string
    {
        return null;
    }
}
