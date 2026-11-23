<?php
declare(strict_types=1);

namespace Phpdup\Refactor;

use PhpParser\Node;
use Phpdup\Extraction\Block;

/**
 * Derives the formal parameters the cluster members already share.
 *
 * php-dup-finder-output-006: holes capture only divergent
 * sub-expressions, so an extracted abstraction whose members each take
 * `$user` references `$user` as a FREE VARIABLE — the suggestion was
 * never behavior-preserving for any member with parameters. This class
 * computes the promotion set; output-S49a makes
 * {@see SignatureBuilder} render it, output-S49b wires it through
 * {@see ApplyExtractor} (generated-function parameter list + call-site
 * locals). Until S49b lands, this is display truth only: no hole is
 * minted for a promoted parameter and `Cluster::$holePaths` never sees
 * one, so the apply venue keeps its pre-S49 behavior for the hole part
 * of the list.
 *
 * The intersection law (finding 006 fix, verbatim): "intersect members'
 * parameter lists (same position/name/normalized type), promote the
 * shared prefix." Concretely — the promoted set is the longest POSITIONAL
 * PREFIX such that for every position i, EVERY member declares a
 * parameter at i that is:
 *   - a simple variable (`$name` form, never `$$expr`, never `$this`),
 *   - name-equal across all members (exact — user-visible spelling),
 *   - type-equal up to normalization (case-insensitive builtins,
 *     `\PDO` ≡ `PDO`, union/intersection parts compared as sets;
 *     `self`/`static`/`parent` are refused outright — they only mean
 *     something in class context and the extracted artifact is a plain
 *     function),
 *   - by reference in no member (reference semantics cannot be judged
 *     per-position from declarations alone),
 *   - variadic in no member (positional call-site passing has no slot
 *     for the tail),
 *   - defaulted in no member (a promoted parameter is always passed, so
 *     a shared default would be decorative here and a differing one a
 *     silent behavior choice — stop at either),
 *   - carrying no attributes and no flags (PHP 8 constructor property
 *     promotion is class-scope state, never hoistable),
 * and the parameter's `$name` does not collide with a parameter name a
 * hole already renders (a collision means two signature lines would
 * bind the same variable — drop the position and stop the prefix).
 *
 * Prefix, not subset: position i+1 is examined only when position i
 * promoted, because the generated abstraction receives the member's
 * own locals positionally (S49b) — a skipped middle slot would shift
 * every later argument.
 *
 * Evidence rules (output-S26 honesty, output-S57 cap doctrine):
 *   - a cluster of fewer than 2 members shares nothing to promote;
 *   - a routed cluster (more members than
 *     {@see AntiUnifier::representativeCount()}) promotes NOTHING: its
 *     tail members were never walked, so "every member already declares
 *     this parameter" would be an unobserved claim — routed signatures
 *     keep their pre-S49a shape exactly as the CAP sentinel keeps them
 *     hole-inert;
 *   - any member whose AST is not loaded promotes NOTHING (no evidence,
 *     no invention);
 *   - any member whose root is not signature-bearing (if/foreach/stmt-
 *     group blocks) promotes NOTHING — there is no formal-parameter
 *     region in that abstraction's scope at all.
 *
 * Selection is content-derived and deterministic: cluster order fixes
 * both the walk and the display text (the first member's own spelling
 * of an agreeing type is what renders), so serial and forked refactor
 * passes derive byte-identical sets by construction.
 */
final class ParamIntersection
{
    /** Refusal marker: this type node cannot be compared/promoted. */
    private const REFUSED = "\0refused";

    private function __construct()
    {
    }

    /**
     * The promoted shared-prefix parameters of a cluster's members.
     *
     * @param list<Block>  $members      cluster members in cluster order
     * @param list<string> $reservedVars '$'-carrying names already rendered
     *                                   as hole parameters by the caller
     *                                   (collision stops the prefix)
     * @return list<PromotedParam>
     */
    public static function promote(array $members, array $reservedVars = []): array
    {
        if (count($members) < 2) {
            return [];
        }
        // output-S57 routing law: a capped cluster never saw its tail.
        if (AntiUnifier::representativeCount(count($members)) < count($members)) {
            return [];
        }

        /** @var list<list<Node\Param>> $paramLists */
        $paramLists = [];
        foreach ($members as $member) {
            if ($member->ast === null) {
                return [];
            }
            $params = self::declaredParams($member->ast);
            if ($params === null) {
                return [];
            }
            $paramLists[] = $params;
        }

        $reserved  = array_flip($reservedVars);
        $shortest  = min(array_map('count', $paramLists));
        $promoted  = [];
        for ($i = 0; $i < $shortest; $i++) {
            $first = $paramLists[0][$i];
            $name  = self::paramName($first);
            if ($name === null || $name === 'this' || !self::positionallyPromotable($first)) {
                break;
            }
            $key = self::typeKey($first->type);
            if ($key === self::REFUSED) {
                break;
            }
            if (isset($reserved['$' . $name])) {
                // A hole already renders this variable name — the two
                // claims cannot share one signature. Stay conservative.
                break;
            }
            foreach ($paramLists as $k => $params) {
                if ($k === 0) {
                    continue;
                }
                $other = $params[$i];
                if (
                    self::paramName($other) !== $name
                    || !self::positionallyPromotable($other)
                    || self::typeKey($other->type) !== $key
                ) {
                    break 2;
                }
            }
            $display = self::typeText($first->type);
            if ($display === self::REFUSED) {
                break;
            }
            $promoted[] = new PromotedParam($name, $display === '' ? null : $display);
        }
        return $promoted;
    }

    /**
     * The declared parameter list of a signature-bearing block root, or
     * null when the root carries no formal parameters at all.
     *
     * @return list<Node\Param>|null
     */
    public static function declaredParams(Node $root): ?array
    {
        if (
            $root instanceof Node\Stmt\ClassMethod
            || $root instanceof Node\Stmt\Function_
            || $root instanceof Node\Expr\Closure
            || $root instanceof Node\Expr\ArrowFunction
        ) {
            return $root->params;
        }
        return null;
    }

    /** Bare variable name of a param, null when it is not a plain `$name`. */
    private static function paramName(Node\Param $param): ?string
    {
        return $param->var instanceof Node\Expr\Variable && is_string($param->var->name)
            ? $param->var->name
            : null;
    }

    /** A position the positional-call law may carry. */
    private static function positionallyPromotable(Node\Param $param): bool
    {
        return !$param->byRef
            && !$param->variadic
            && $param->default === null
            && $param->attrGroups === []
            && $param->hooks === []
            && $param->flags === 0;
    }

    /**
     * Normalized comparison key of a type node: lower-cased leaves, no
     * leading backslash, union/intersection parts compared as sets.
     * '' means untyped; {@see self::REFUSED} means no comparison is
     * honest (unrecognized node shape or class-context type).
     */
    private static function typeKey(?Node $type): string
    {
        if ($type === null) {
            return '';
        }
        $parts = self::leafNames($type);
        if ($parts === null) {
            return self::REFUSED;
        }
        $normalized = array_map(
            static fn(string $part): string => strtolower(str_replace('\\', '', $part)),
            $parts,
        );
        if (array_intersect(array_map(static fn(string $p): string => ltrim($p, '?'), $normalized), ['self', 'static', 'parent']) !== []) {
            return self::REFUSED;
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            sort($normalized);
            return implode('|', $normalized);
        }
        return $normalized[0];
    }

    /** Display text for the first agreeing member's type node, or REFUSED. */
    private static function typeText(?Node $type): string
    {
        if ($type === null) {
            return '';
        }
        if (self::typeKey($type) === self::REFUSED) {
            return self::REFUSED;
        }
        return self::renderType($type);
    }

    /**
     * Leaf type names of a comparable type node, null when a component
     * shape is not recognized.
     *
     * @return list<string>|null
     */
    private static function leafNames(?Node $type): ?array
    {
        if ($type instanceof Node\NullableType) {
            $inner = self::leafNames($type->type);
            if ($inner === null || count($inner) !== 1) {
                // '?A|B' is not expressible pre-8.2 and its traversal
                // shape is not comparable here.
                return null;
            }
            return ['?' . $inner[0]];
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $parts = [];
            foreach ($type->types as $part) {
                $inner = self::leafNames($part);
                if ($inner === null || count($inner) !== 1) {
                    // Nested union/intersection inside a member position
                    // (DNF shapes) — over a set of leaves is not comparable.
                    return null;
                }
                $parts[] = $inner[0];
            }
            return $parts;
        }
        if ($type instanceof Node\Identifier) {
            // php-parser v5: builtin scalar type names arrive as
            // Identifier (Name subclasses like FullyQualified are
            // qualified names). VarLikeIdentifier extends Identifier and
            // can never occupy a type slot, so name-as-string is safe.
            return [$type->name];
        }
        if ($type instanceof Node\Name) {
            return [$type->toString()];
        }
        return null;
    }

    private static function renderType(Node $type): string
    {
        if ($type instanceof Node\NullableType) {
            return '?' . self::renderType($type->type);
        }
        $glue = match (true) {
            $type instanceof Node\UnionType        => '|',
            $type instanceof Node\IntersectionType => '&',
            default                               => '',
        };
        if ($glue !== '') {
            /** @var Node\UnionType|Node\IntersectionType $type */
            return implode($glue, array_map(
                static fn(Node $part): string => self::renderType($part),
                $type->types,
            ));
        }
        if ($type instanceof Node\Name\FullyQualified) {
            return '\\' . $type->toString();
        }
        /** @var Node\Name|Node\Identifier $type — leafNames() guaranteed it */
        return $type instanceof Node\Identifier ? $type->name : $type->toString();
    }
}
