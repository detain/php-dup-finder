<?php
declare(strict_types=1);

namespace Phpdup\Refactor;

/**
 * The code-domain projector for Hole display fields (item 5.4 review r2, N-1).
 *
 * Ownership law, in one sentence: ParameterSynthesizer OWNS the display
 * convention — `suggestedName` carries its `$` sigil and `inferredType` may
 * carry PHPDoc spellings (`class-string`, plain `null`) that are right for
 * humans but NOT parseable as PHP parameter declarations. Anything that
 * emits CODE must pass those fields through this class; anything that shows
 * TEXT to a human reads the raw fields. Before this existed,
 * RefactorTestReporter re-prepended the sigil (128/159 real-corpus skeletons
 * died as `mixed $$arg` under php -l) and re-emitted PHPDoc types verbatim
 * (`class-string $name` → unexpected token "class"), while ApplyExtractor
 * fed the sigil-carrying name straight into `Variable()`, so extracted
 * bodies addressed variable-variables (`$$threshold`) instead of their own
 * parameters.
 *
 * Measured truth, not cargo cult: PHP's ONLY forbidden parameter binding is
 * `$this` — a parameter named `$class` is perfectly legal (proven by the
 * corpus census test). The reserved-WORD deaths were always the TYPE
 * position. So the name layer validates identifier shape, refuses `this`,
 * and falls back deterministically (placeholder, then 'param') — content,
 * never randomness; the type layer maps the known-unsafe spellings and
 * drops or collapses anything unrecognizable to a parseable union
 * (`int[]`→array, `T[]`→array, `array<...>`→array, `*-string`→string,
 * `true`/`false`→bool, dedupe, `X|null`→`?X`, all-null or mixed-null→mixed).
 *
 * Every member of this API is pure and deterministic: the same Hole fields
 * project to the same token in the declaration (SignatureBuilder), the
 * extracted body and the call-site rewrites (ApplyExtractor), and the test
 * skeleton (RefactorTestReporter) — one truth per surface family.
 */
final class CodeParam
{
    private function __construct()
    {
    }

    /** Code-ready parameter variable, WITH the `$` (render position). */
    public static function varName(string $suggestedName, string $placeholder): string
    {
        return '$' . self::namePart($suggestedName, $placeholder);
    }

    /** Code-ready variable name WITHOUT the `$` (PhpParser `Variable()` ctor position). */
    public static function bareName(string $suggestedName, string $placeholder): string
    {
        return self::namePart($suggestedName, $placeholder);
    }

    /**
     * Make `$dollarName` unique within one parameter list. `$used` is the
     * caller-owned tally for THAT signature only — deterministic first-seen
     * order, collisions grow `_2`, `_3`, … (no cross-run identity claimed).
     *
     * @param array<string,int> $used
     */
    public static function uniqueWithin(string $dollarName, array &$used): string
    {
        if (!isset($used[$dollarName])) {
            $used[$dollarName] = 1;
            return $dollarName;
        }
        do {
            $used[$dollarName]++;
            $candidate = $dollarName . '_' . $used[$dollarName];
        } while (isset($used[$candidate]));
        $used[$candidate] = 1;
        return $candidate;
    }

    /**
     * Code-domain type text. Empty in → empty out (the caller renders an
     * untyped parameter); never returns something php -l would reject for
     * the vocabulary ParameterSynthesizer's inferType can emit.
     */
    public static function typeText(string $inferredType): string
    {
        if ($inferredType === '') {
            return '';
        }

        $parts = array_map('trim', explode('|', $inferredType));
        $mapped = [];
        $hasNull = false;
        foreach ($parts as $part) {
            if ($part === '' && count($parts) === 1) {
                return '';
            }
            if ($part === 'null') {
                $hasNull = true;
                continue;
            }
            $code = self::mapTypeMember($part);
            if ($code === null) {
                continue; // unparseable member: honesty is to drop it, not ship poison
            }
            $mapped[$code] = true;
        }

        $codes = array_keys($mapped);
        if ($codes === []) {
            return 'mixed'; // only null survived, or nothing survived
        }
        if ($hasNull && count($codes) === 1) {
            return '?' . $codes[0];
        }
        if ($hasNull) {
            return 'mixed';
        }
        return implode('|', $codes);
    }

    /** @return string|null null = member cannot be expressed as a code type */
    private static function mapTypeMember(string $part): ?string
    {
        // Array shapes: int[], array<int,string>, list<T>, non-empty-array.
        if (str_ends_with($part, '[]')) {
            return 'array';
        }
        if (str_contains($part, '<')) {
            // Generic bracket: template arguments never decide the code type.
            return preg_match('/^(array|list|iterable|non-empty-array)\b/', $part) === 1 ? 'array' : null;
        }
        if (str_ends_with($part, '-string')) {
            return 'string'; // class-string, callable-string, numeric-string, literal-string, int-string, …
        }
        if (str_ends_with($part, '-array')) {
            return 'array'; // non-empty-array and friends
        }
        switch ($part) {
            case 'true':
            case 'false':
                return 'bool';
            case 'void':
            case 'never':
                return null; // meaningless as a parameter type
        }
        // Simple keyword types and class-shaped names (incl. FQCN) pass through.
        if (preg_match('/^(\??)[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*(\\\\[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)*$/', $part) === 1) {
            return ltrim($part, '?'); // leading ? on one member of a union would not parse; re-applied only at top
        }
        return null;
    }

    private static function namePart(string $suggestedName, string $placeholder): string
    {
        $raw = $suggestedName !== '' ? $suggestedName : $placeholder;
        $id = self::sanitizeId(ltrim($raw, '$'));
        if ($id === '' || $id === 'this') {
            // '$this' is PHP's one forbidden binding; empty result means the
            // source carried no usable identifier — fall back to the content
            // placeholder, and only then to a generic 'param'.
            $alt = self::sanitizeId(ltrim($placeholder, '$'));
            $id = ($alt !== '' && $alt !== 'this') ? $alt : 'param';
        }
        return $id;
    }

    private static function sanitizeId(string $raw): string
    {
        $id = (string)preg_replace('/[^a-zA-Z0-9_\x80-\xff]/', '_', $raw);
        if ($id === '') {
            return '';
        }
        if (preg_match('/^[0-9]/', $id) === 1) {
            $id = '_' . $id;
        }
        return $id;
    }
}
