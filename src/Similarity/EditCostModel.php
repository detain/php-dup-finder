<?php
declare(strict_types=1);

namespace Phpdup\Similarity;

/**
 * Edit-cost model for weighted tree-edit distance.
 *
 * The default APTED uses unit costs — every insert/delete/relabel
 * contributes 1 to the distance. That treats a literal change the
 * same as a method-call rename, which is wrong: changing a method
 * call materially changes program behaviour, while a literal swap
 * usually doesn't.
 *
 * The 'semantic' model assigns:
 *   - method/function call labels  → cost 2.0
 *   - control-flow keywords        → cost 1.5
 *   - literals                     → cost 0.5
 *   - everything else              → cost 1.0
 *
 * **Label format contract:** `cost()` receives labels in the prefixed
 * form that {@see \Phpdup\Util\AstSerializer::shortType()} emits
 * (e.g. "Expr_MethodCall", "Stmt_If_", "Scalar_Int_").  The semantic
 * weight table above lists bare names ("MethodCall", "If_", "Int_").
 *
 * Before lookup, `cost()` performs two preprocessing steps:
 *   1. Strip any trailing `|…` scalar-type suffix via `explode('|', $label)[0]`
 *   2. Strip the leading `Expr_`/`Stmt_`/`Scalar_` prefix via `canonicalizeLabel()`
 *
 * so callers pass prefixed labels unchanged and the matching is always
 * against bare names.
 *
 * Distances are returned as floats; AptedDistance::similarity()
 * scales appropriately.
 *
 * Models:
 *   - 'default'  → all costs 1.0 (legacy behaviour)
 *   - 'semantic' → weighted as above
 */
final class EditCostModel
{
    public const MODEL_DEFAULT  = 'default';
    public const MODEL_SEMANTIC = 'semantic';

    /** @var list<string> */
    public const MODELS = [self::MODEL_DEFAULT, self::MODEL_SEMANTIC];

    public function __construct(public readonly string $model = self::MODEL_DEFAULT)
    {
        if (!in_array($model, self::MODELS, true)) {
            throw new \InvalidArgumentException("Unknown edit cost model: {$model}");
        }
    }

    /**
     * Cost of inserting/deleting a node with the given label, or
     * substituting a node with a different-labelled one.
     *
     * The incoming $label is always in the prefixed form that
     * {@see \Phpdup\Util\AstSerializer::shortType()} returns
     * ("Expr_MethodCall", "Stmt_If_", "Scalar_Int_", etc.).
     * `canonicalizeLabel()` strips the "Expr_"/"Stmt_"/"Scalar_" prefix
     * before the semantic weight table lookup, so the bare-name table
     * entries ("MethodCall", "If_", "Int_") always match.
     */
    public function cost(string $label): float
    {
        if ($this->model === self::MODEL_DEFAULT) {
            return 1.0;
        }
        // Semantic weighting.
        $bare = explode('|', $label)[0];   // strip scalar suffix if any
        $bare = self::canonicalizeLabel($bare);
        if (self::isCallLabel($bare))      return 2.0;
        if (self::isControlFlow($bare))    return 1.5;
        if (self::isLiteral($bare))        return 0.5;
        return 1.0;
    }

    /**
     * Strip namespace prefixes that shortType() emits.
     *
     * shortType() returns "Expr_MethodCall", "Stmt_If_", "Scalar_Int_", etc.
     * but the cost-table uses bare names like "MethodCall", "If_", "Int_".
     */
    private static function canonicalizeLabel(string $label): string
    {
        if (str_starts_with($label, 'Expr_')) {
            return substr($label, 5);
        }
        if (str_starts_with($label, 'Stmt_')) {
            return substr($label, 5);
        }
        if (str_starts_with($label, 'Scalar_')) {
            return substr($label, 7);
        }
        return $label;
    }

    private static function isCallLabel(string $label): bool
    {
        return $label === 'MethodCall'
            || $label === 'NullsafeMethodCall'
            || $label === 'StaticCall'
            || $label === 'FuncCall'
            || $label === 'New_'
            || $label === 'Name';
    }

    private static function isControlFlow(string $label): bool
    {
        return $label === 'If_'
            || $label === 'For_'
            || $label === 'Foreach_'
            || $label === 'While_'
            || $label === 'Do_'
            || $label === 'Switch_'
            || $label === 'Case_'
            || $label === 'Match_'
            || $label === 'MatchArm'
            || $label === 'TryCatch'
            || $label === 'Catch_'
            || $label === 'Return_'
            || $label === 'Break_'
            || $label === 'Continue_'
            || $label === 'Throw_'
            || $label === 'Goto_';
    }

    private static function isLiteral(string $label): bool
    {
        return $label === 'String_'
            || $label === 'Int_'
            || $label === 'Float_'
            || $label === 'InterpolatedString'
            || $label === 'InterpolatedStringPart'
            || $label === 'Array_'
            || $label === 'ArrayItem';
    }
}
