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
    public function synthesize(Cluster $cluster): void
    {
        $usedNames = [];
        foreach ($cluster->holes as $hole) {
            $hole->inferredType = $this->inferType($hole);
            $hole->suggestedName = $this->suggestName($hole, $usedNames);
        }
    }

    private function inferType(Hole $hole): string
    {
        if ($hole->kind === 'optional_block') {
            return 'bool';
        }

        $values = $hole->observedValues;
        if (!$values) return 'mixed';

        if ($this->allMatch($values, '/^-?\d+$/'))                              return 'int';
        if ($this->allMatch($values, '/^-?\d+\.\d+$/'))                         return 'float';
        if ($this->allMatchSet($values, ['true', 'false', 'TRUE', 'FALSE']))    return 'bool';
        if ($this->allMatchSet($values, ['null', 'NULL']))                      return 'null';
        if ($this->allMatch($values, '/^([\'"]).*\1$/s'))                       return 'string';

        // class-like: all CamelCase identifiers, possibly namespaced
        if ($this->allMatch($values, '/^[A-Z][A-Za-z0-9_]*(\\\\[A-Z][A-Za-z0-9_]*)*$/')) {
            return 'class-string';
        }

        // callable: hole kind name + values look like simple identifiers
        if ($hole->kind === 'name' && $this->allMatch($values, '/^[a-z_][a-zA-Z0-9_]*$/')) {
            return 'callable|string';
        }

        return 'mixed';
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
            if ($v === '<absent>' || $v === '<missing>' || $v === '') continue;
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

    /**
     * @param list<string> $values
     * @param list<string> $set
     */
    private function allMatchSet(array $values, array $set): bool
    {
        foreach ($values as $v) {
            if (!in_array($v, $set, true)) return false;
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
