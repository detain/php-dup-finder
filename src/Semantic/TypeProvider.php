<?php
declare(strict_types=1);

namespace Phpdup\Semantic;

/**
 * Source of inferred type information for a project.
 *
 * Implementations wrap external static analysers (Psalm, PHPStan)
 * and translate their issue/typing reports into a uniform
 * `(file, line, expr) → type-string` lookup.
 *
 * Status: NOT WIRED (engine-S31a catalog ledger, row 4). The planned
 * consumer is ParameterSynthesizer — upgrading `mixed` holes to
 * concrete types when an external analyser already inferred them —
 * but ParameterSynthesizer constructs no provider today and infers
 * from the AST alone.
 *
 * The interface is intentionally narrow — phpdup never feeds AST
 * back to the provider, only ever queries it. This keeps providers
 * cheap to implement and makes the {@see NullTypeProvider} fallback
 * a no-op zero-cost default once wiring lands.
 */
interface TypeProvider
{
    /**
     * Return the provider's best guess at the type of the
     * expression at the given file + line. May be:
     *
     *   - a single type      e.g. 'int', 'string', 'App\\User'
     *   - a union            e.g. 'int|string', 'string|null'
     *   - 'mixed' / null     when no information is available
     */
    public function typeAt(string $file, int $line): ?string;

    /** Identifier for diagnostics — e.g. 'psalm', 'phpstan', 'null'. */
    public function name(): string;
}
