<?php
declare(strict_types=1);

namespace Phpdup\Refactor;

use Phpdup\Clustering\Cluster;

/**
 * Promotes Holes (positions where members disagree) into a typed,
 * named parameter list.
 *
 * Heuristics:
 *
 *   - inferredType
 *       all observed values look like ints   → 'int'
 *       all look like floats                  → 'float'
 *           (float covers the full repr() vocabulary of output-025:
 *            decimal '1.0', exponent '1.0E+25', non-finite 'INF'/'NAN')
 *       all look like bools                   → 'bool'
 *       all look like nulls                   → 'null'
 *       all are PHP string literals           → 'string'
 *       all are bare identifiers/$vars        → 'mixed'
 *       all are class-like names              → 'class-string'
 *       hole kind is 'name' & looks callable  → 'callable'
 *       fallback                              → 'mixed'
 *
 *   - suggestedName
 *       prefer a name that's the longest common substring of observed
 *       values when the values are identifier-like; otherwise fall
 *       back to a role name based on the hole's kind:
 *
 *         literal     → $value | $threshold (if numeric)
 *         identifier  → $arg
 *         name        → $name
 *         call        → $callback
 *         subtree     → $expr
 *
 *       collisions are resolved with a numeric suffix.
 */
final class ParameterSynthesizer
{
    /**
     * Numeric literal shapes as they arrive in a hole's observed values —
     * i.e. exactly what AntiUnifier::repr() emits (output-025):
     *
     *   int    — signed digits ('1', '-3'); ints are precision-immune.
     *   float  — the var_export family: decimal ('1.0', '-0.5'),
     *            exponent magnitude ('1.0E+25', '1.0E-5'), plus the
     *            legacy `(string)`-cast spellings a pre-025 cached
     *            payload may still carry ('1.5e-3', '.5',
     *            '1.0000000000000001E+25'), and the non-finite words
     *            'INF' / '-INF' / 'NAN' (var_export of INF/NAN, the
     *            shape a 1e309-style literal takes after the lexer's
     *            (float) conversion).
     *
     * Without the exponent/non-finite arms an integral-magnitude float
     * hole fell through the whole cascade to 'mixed' — typing honesty
     * demanded 'float'.
     */
    private const INT_LITERAL_REGEX = '/^-?\d+$/';
    private const FLOAT_LITERAL_REGEX = '/^(?:-?(?:\d+\.\d*|\.\d+|\d+(?:\.\d*)?[eE][+-]?\d+)|-?INF|NAN)$/';

    /** The remaining cascade shape tests, promoted from inline duplicates
     *  to the single classification authority (output-045), verbatim. */
    private const STRING_LITERAL_REGEX = '/^([\'"]).*\1$/s';
    private const CLASS_NAME_REGEX = '/^[A-Z][A-Za-z0-9_]*(\\\\[A-Z][A-Za-z0-9_]*)*$/';
    private const IDENTIFIER_SHAPE_REGEX = '/^[a-z_][a-zA-Z0-9_]*$/';

    /**
     * Mutually-exclusive shape categories, one dispatch per value
     * (classifyValue). A value's category is what the value IS; the old
     * cascade's homogeneity arms tested which raw regex SETS a spelling
     * belongs to, and those sets overlap — the class-like regex also
     * accepts the words 'TRUE'/'FALSE'/'NULL'/'INF'/'NAN', the identifier
     * shape also accepts 'true'/'false'/'null'. The word-case split below
     * keeps every arm's membership recoverable from the counts WITHOUT a
     * second walk: the arms become family-subset tests over the tally.
     * 'identifier' and 'other' can name a callable slot, never a type.
     */
    private const CATEGORY_INT = 'int';
    private const CATEGORY_FLOAT = 'float';
    private const CATEGORY_FLOAT_WORD = 'float-word';           // 'INF', 'NAN'
    private const CATEGORY_BOOL = 'bool';                       // 'true', 'false'
    private const CATEGORY_BOOL_CAPS = 'bool-caps';             // 'TRUE', 'FALSE'
    private const CATEGORY_NULL = 'null';                       // 'null'
    private const CATEGORY_NULL_CAPS = 'null-caps';             // 'NULL'
    private const CATEGORY_STRING = 'string';
    private const CATEGORY_CLASS_NAME = 'class-name';
    private const CATEGORY_IDENTIFIER = 'identifier';
    private const CATEGORY_OTHER = 'other';

    /**
     * Which shape family each old homogeneity arm actually tested
     * (a value matched the arm's own regex, with the cascade order as the
     * tiebreaker when several arms pass). Families as category lists:
     */
    private const FLOAT_FAMILY = [self::CATEGORY_FLOAT, self::CATEGORY_FLOAT_WORD];
    private const BOOL_FAMILY = [self::CATEGORY_BOOL, self::CATEGORY_BOOL_CAPS];
    private const NULL_FAMILY = [self::CATEGORY_NULL, self::CATEGORY_NULL_CAPS];
    private const CLASS_FAMILY = [
        self::CATEGORY_CLASS_NAME,
        self::CATEGORY_FLOAT_WORD,   // 'INF'/'NAN' match the class-like regex
        self::CATEGORY_BOOL_CAPS,    // 'TRUE'/'FALSE' likewise
        self::CATEGORY_NULL_CAPS,    // and 'NULL'
    ];

    /**
     * Category → emitted type name for the union projection; categories
     * absent from this map ('identifier', 'other') are untypeable.
     *
     * @var array<string,string>
     */
    private const CATEGORY_TYPE_NAMES = [
        self::CATEGORY_INT         => 'int',
        self::CATEGORY_FLOAT       => 'float',
        self::CATEGORY_FLOAT_WORD  => 'float',
        self::CATEGORY_BOOL        => 'bool',
        self::CATEGORY_BOOL_CAPS   => 'bool',
        self::CATEGORY_NULL        => 'null',
        self::CATEGORY_NULL_CAPS   => 'null',
        self::CATEGORY_STRING      => 'string',
        self::CATEGORY_CLASS_NAME  => 'class-string',
    ];

    /**
     * Stable union ordering: scalar primitives first, then class-string —
     * the unionType roster the counts now spell through (output-045).
     */
    private const UNION_TYPE_ORDER = ['int', 'float', 'string', 'bool', 'null', 'class-string'];

    public function synthesize(Cluster $cluster): void
    {
        $usedNames = [];
        foreach ($cluster->holes as $hole) {
            if ($hole->placeholder === AntiUnifier::CAP_SENTINEL) {
                // output-S57 r1 F1: the cap sentinel is an EVIDENCE marker
                // (the routed tail was never observed), never a template
                // gap. Its rows are all MISSING — inferring from them would
                // fabricate a parameter no member contains. Leave the
                // sentinel synthesizer-inert; refusal chains read its
                // degraded flag, not its name.
                continue;
            }
            $hole->inferredType = $this->inferType($hole);
            $hole->suggestedName = $this->suggestName($hole, $usedNames);
        }
    }

    /**
     * Map a hole's observed rows to one parameter type in a SINGLE
     * classification pass (output-045). The old cascade proved each
     * homogeneity with its own whole-list walk (up to ~6 passes) and, on
     * disagreement, RE-walked every value through classifyValue() — 6–10
     * regexes per value. Now every row is classified exactly once and both
     * decisions — all-same and union — derive from the category counts.
     *
     * Decision order mirrors the historical cascade:
     *
     *   1. optional_block            → 'bool' (kind short-circuit, unchanged)
     *   2. all rows in one arm       → that arm's type name
     *   3. 'name' hole, every row identifier-shaped → 'callable|string'
     *   4. every row typeable        → union in UNION_TYPE_ORDER
     *   5. anything untypeable       → 'mixed'
     */
    private function inferType(Hole $hole): string
    {
        if ($hole->kind === 'optional_block') {
            return 'bool';
        }

        $values = $hole->observedValues;
        if (!$values) return 'mixed';

        [$counts, $identifierShaped] = $this->tallyCategories($values);

        // Homogeneity arms, in the exact cascade order the old code applied
        // them — each is now a family-subset test over the counts. int and
        // string are disjoint single categories; the word-case split makes
        // float/bool/null/class memberships of overlapping spellings
        // ('INF', 'TRUE', 'NULL', …) provable from the same tally.
        if (count($counts) === 1 && isset($counts[self::CATEGORY_INT]))  return 'int';
        if (self::countsWithinFamily($counts, self::FLOAT_FAMILY))       return 'float';
        if (self::countsWithinFamily($counts, self::BOOL_FAMILY))        return 'bool';
        if (self::countsWithinFamily($counts, self::NULL_FAMILY))        return 'null';
        if (count($counts) === 1 && isset($counts[self::CATEGORY_STRING])) return 'string';
        if (self::countsWithinFamily($counts, self::CLASS_FAMILY))       return 'class-string';

        // 'name' positions whose every row matches the raw identifier shape
        // are function/method-name slots. The lowercase bool/null words are
        // identifier-shaped (the old callable arm tested the raw regex,
        // which accepts 'true'/'false'/'null' and rejects the caps).
        if ($hole->kind === 'name' && $identifierShaped === count($values)) {
            return 'callable|string';
        }

        // Mixed evidence: emit a union only when EVERY row classifies to a
        // PHP type. Bare identifiers are NOT classified — they're often
        // variables in the template, and naming a type for them would be a
        // lie — so their presence (or any unparseable row) still degrades
        // the whole hole to 'mixed' rather than a misleading partial union.
        $union = $this->unionFromCounts($counts);
        return $union ?? 'mixed';
    }

    /**
     * True when every category present in the tally lies inside the given
     * arm family. The tally is never empty (callers guard on a non-empty
     * value list), so no extra "at least one row" check is needed.
     *
     * @param array<string,int> $counts
     * @param list<string>      $family
     */
    private static function countsWithinFamily(array $counts, array $family): bool
    {
        foreach ($counts as $category => $occurrences) {
            if (!in_array($category, $family, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * One pass over the observed rows, classifying each value EXACTLY once
     * into its shape category (output-045). Alongside the category counts it
     * tallies identifier-SHAPED rows: category 'identifier' plus the
     * lowercase bool and null words — with the word-case split the
     * categories themselves ARE the membership of the raw
     * /^[a-z_][a-zA-Z0-9_]*$/ test the old callable arm ran, recovered in
     * the same walk for zero extra regex cost.
     *
     * @param list<string> $values
     * @return array{0: array<string,int>, 1: int}  [category => count, identifier-shaped row count]
     */
    private function tallyCategories(array $values): array
    {
        $counts           = [];
        $identifierShaped = 0;

        foreach ($values as $v) {
            $category = $this->classifyValue($v);
            $counts[$category] = ($counts[$category] ?? 0) + 1;

            if (
                $category === self::CATEGORY_IDENTIFIER
                || $category === self::CATEGORY_BOOL
                || $category === self::CATEGORY_NULL
            ) {
                $identifierShaped++;
            }
        }

        return [$counts, $identifierShaped];
    }

    /**
     * The stable-ordered union spelled by the category counts — kept only
     * when every row type-ifies (the old unionType contract, without the
     * second walk). Null says "at least one row is untypeable"; the caller
     * then answers 'mixed'.
     *
     * @param array<string,int> $counts
     */
    private function unionFromCounts(array $counts): ?string
    {
        $types = [];
        foreach ($counts as $category => $occurrences) {
            $type = self::CATEGORY_TYPE_NAMES[$category] ?? null;
            if ($type === null) {
                return null; // 'identifier' / 'other' row: partial evidence
            }
            $types[$type] = true;
        }
        if ($types === []) {
            return null;
        }

        $sorted = [];
        foreach (self::UNION_TYPE_ORDER as $type) {
            if (isset($types[$type])) {
                $sorted[] = $type;
            }
        }

        return implode('|', $sorted);
    }

    /**
     * The literal-shape category of a single observed value — the one
     * classification authority (output-045). A value is dispatched onto
     * what it IS (numeric float vs the word 'NAN', 'true' vs 'TRUE'), and
     * the overlapping regex-set membership the old arms relied on lives in
     * the family constants instead. First-byte gating skips regexes a value
     * cannot match by shape alone — a quoted row never tries a numeric
     * regex, a '$var' never tries any type regex.
     *
     * @return 'int'|'float'|'float-word'|'bool'|'bool-caps'|'null'|'null-caps'|'string'|'class-name'|'identifier'|'other'
     */
    private function classifyValue(string $v): string
    {
        if ($v === '') {
            // The canonical absence row (Hole::MISSING) is no evidence of
            // any shape — the old cascade reached the same 'mixed' verdict.
            return self::CATEGORY_OTHER;
        }

        $head = $v[0];
        $ord  = ord($head);

        if ($head === '-' || $head === '.' || ($ord >= 0x30 && $ord <= 0x39)) {
            // Numeric family: signed int digits, the full var_export float
            // vocabulary (decimal, exponent, leading-dot, legacy-cast and
            // '-INF' spellings). Nothing else opens with sign/dot/digit, so
            // failing both regexes settles it: 'other'.
            if (preg_match(self::INT_LITERAL_REGEX, $v) === 1) {
                return self::CATEGORY_INT;
            }
            if (preg_match(self::FLOAT_LITERAL_REGEX, $v) === 1) {
                return self::CATEGORY_FLOAT;
            }
            return self::CATEGORY_OTHER;
        }

        if ($head === "'" || $head === '"') {
            // Only a quote-opened row can be a PHP string literal.
            return preg_match(self::STRING_LITERAL_REGEX, $v) === 1
                ? self::CATEGORY_STRING
                : self::CATEGORY_OTHER;
        }

        // Keyword arms, split by case: the lowercase words are identifier-
        // SHAPED (the old callable arm's raw regex accepted them) and the
        // all-caps ones are class-like-SHAPED (the old class arm's regex
        // accepted them) — the families keep both readings provable.
        if ($v === 'true' || $v === 'false') {
            return self::CATEGORY_BOOL;
        }
        if ($v === 'TRUE' || $v === 'FALSE') {
            return self::CATEGORY_BOOL_CAPS;
        }
        if ($v === 'null') {
            return self::CATEGORY_NULL;
        }
        if ($v === 'NULL') {
            return self::CATEGORY_NULL_CAPS;
        }

        if (($head === 'I' || $head === 'N') && preg_match(self::FLOAT_LITERAL_REGEX, $v) === 1) {
            // 'INF' / 'NAN': non-finite float words that ALSO satisfy the
            // class-like regex. Own category: the float arm sat above the
            // class arm in the cascade, and both families list this one.
            return self::CATEGORY_FLOAT_WORD;
        }

        if ($ord >= 0x41 && $ord <= 0x5A) {
            // A-Z: possibly a class-like name, possibly namespaced.
            if (preg_match(self::CLASS_NAME_REGEX, $v) === 1) {
                return self::CATEGORY_CLASS_NAME;
            }
        } elseif (($ord >= 0x61 && $ord <= 0x7A) || $head === '_') {
            // a-z/underscore: possibly a bare identifier.
            if (preg_match(self::IDENTIFIER_SHAPE_REGEX, $v) === 1) {
                return self::CATEGORY_IDENTIFIER;
            }
        }

        return self::CATEGORY_OTHER;
    }

    /**
     * @param array<string,bool> $usedNames
     */
    private function suggestName(Hole $hole, array &$usedNames): string
    {
        $values = $hole->observedValues;
        $base = null;

        // Optional segments: derive a verb from the first identifier in the
        // segment so the boolean param reads naturally — e.g. a segment whose
        // first call is `some_other_logic(...)` becomes `$includeSomeOtherLogic`.
        if ($hole->kind === 'optional_block') {
            $base = 'include' . ucfirst($this->camelCaseFromSegment($values));
            return $this->resolveCollision('$' . $base, $usedNames);
        }

        // Try longest common substring of identifier-shaped values
        if (count($values) >= 2 && $this->allMatch($values, '/^\$?[a-zA-Z_][a-zA-Z0-9_]*$/')) {
            $stripped = array_map(static fn(string $v) => ltrim($v, '$'), $values);
            $base = $this->longestCommonSubstring($stripped);
            if ($base !== null && strlen($base) < 3) {
                $base = null;
            }
        }

        if ($base === null) {
            $base = match ($hole->kind) {
                'literal' => $hole->inferredType === 'int' || $hole->inferredType === 'float' ? 'threshold' : 'value',
                'identifier' => 'arg',
                'name' => 'name',
                'call' => 'callback',
                'subtree' => 'expr',
                default => 'param',
            };
        }

        $base = $this->camelToSnake($base);
        return $this->resolveCollision('$' . $base, $usedNames);
    }

    /** @param array<string,bool> $usedNames */
    private function resolveCollision(string $name, array &$usedNames): string
    {
        if (!isset($usedNames[$name])) {
            $usedNames[$name] = true;
            return $name;
        }
        $i = 1;
        $candidate = $name . $i;
        while (isset($usedNames[$candidate])) {
            $i++;
            $candidate = $name . $i;
        }
        $usedNames[$candidate] = true;
        return $candidate;
    }

    /**
     * Extract a camelCase verb-ish name from the first non-absent observed
     * segment. Used to label optional_block boolean parameters.
     *
     * @param list<string> $observed
     */
    private function camelCaseFromSegment(array $observed): string
    {
        foreach ($observed as $v) {
            // output-S27: the canonical absence row is Hole::MISSING ('');
            // '<missing>' survives only as a pre-1.5 stale-payload defense.
            if ($v === Hole::MISSING || $v === '<missing>') continue;
            // First identifier-like token in the segment, e.g.
            //   `some_other_logic($here);` → `some_other_logic`
            //   `$ret = otherStuff();`     → `otherStuff`
            //   `if ($x) doThing();`       → `doThing`
            if (preg_match('/[a-zA-Z_][a-zA-Z0-9_]{2,}/', $v, $matches)) {
                $token = $matches[0];
                // skip stop-words; reach for the next identifier in that case.
                $stop = ['if', 'for', 'foreach', 'while', 'do', 'try', 'catch', 'switch', 'return', 'throw', 'else', 'true', 'false', 'null'];
                if (in_array(strtolower($token), $stop, true)) {
                    if (preg_match_all('/[a-zA-Z_][a-zA-Z0-9_]{2,}/', $v, $all)) {
                        foreach ($all[0] as $cand) {
                            if (!in_array(strtolower($cand), $stop, true)) {
                                $token = $cand;
                                break;
                            }
                        }
                    }
                }
                return $this->snakeToCamel($token);
            }
        }
        return 'OptionalBlock';
    }

    private function snakeToCamel(string $s): string
    {
        $parts = preg_split('/[_\\s]+/', $s) ?: [$s];
        $out = '';
        foreach ($parts as $i => $p) {
            $out .= $i === 0 ? lcfirst($p) : ucfirst($p);
        }
        return $out;
    }

    /** @param list<string> $values */
    private function allMatch(array $values, string $regex): bool
    {
        foreach ($values as $v) {
            if (preg_match($regex, $v) !== 1) return false;
        }
        return !!$values;
    }

    /** @param list<string> $strings */
    private function longestCommonSubstring(array $strings): ?string
    {
        if (!$strings) return null;
        if (count($strings) === 1) return $strings[0];
        $best = $strings[0];
        for ($i = 1; $i < count($strings); $i++) {
            $best = $this->lcs($best, $strings[$i]);
            if ($best === '') return null;
        }
        return $best;
    }

    private function lcs(string $a, string $b): string
    {
        $m = strlen($a);
        $n = strlen($b);
        if ($m === 0 || $n === 0) return '';
        $dp = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));
        $maxLen = 0;
        $endA = 0;
        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                if ($a[$i - 1] === $b[$j - 1]) {
                    $dp[$i][$j] = $dp[$i - 1][$j - 1] + 1;
                    if ($dp[$i][$j] > $maxLen) {
                        $maxLen = $dp[$i][$j];
                        $endA = $i;
                    }
                }
            }
        }
        return $maxLen === 0 ? '' : substr($a, $endA - $maxLen, $maxLen);
    }

    private function camelToSnake(string $s): string
    {
        $s = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $s) ?? $s;
        return strtolower($s);
    }
}
