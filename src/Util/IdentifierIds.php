<?php
declare(strict_types=1);

namespace Phpdup\Util;

/**
 * Strips the first-occurrence numeric suffix that the aggressive
 * Normalizer stamps onto renamed identifiers (`__V3`, `__CALL12`,
 * `__PROP0`, `__CLASS7`).
 *
 * Why a shared helper instead of two call-site regexes: item 1.1's
 * lockstep law. The n-gram bag and the TED labels MUST un-number
 * together — bag-only un-numbering promotes guard-drift pairs into
 * the Jaccard tier where numbered `labelOf` output corrupts the edit
 * score and drops pairs containment accepts today (improve_logic.md
 * §3.3 row-2 is the counterexample). One helper, consumed by
 * NgramFingerprint and AptedDistance::labelOf, makes the drift
 * structurally impossible.
 *
 * Slot-safety: the suffix is only ever stripped at the END of a
 * serialized token/label, which is exactly where the renamed
 * identifier sits (`Variable|v:__V1`, `Name|n:__CALL5`,
 * `id:__PROP2`). A string literal whose text happens to end in
 * `__CALL5` would also collapse — a negligible over-strip that is
 * applied identically to bag and labels, so the lockstep invariant
 * (and its monotonicity argument) survives untouched. Aggressive
 * mode rewrites literals to `__STR` long before this runs anyway.
 *
 * Deliberate no-ops (all suffix-less by construction, so the regex
 * leaves them alone): `__FN`, `__STR`, `__MATCH__`, `__DB_<op>__`,
 * structural-function keep-list names, and `$this`.
 */
final class IdentifierIds
{
    /** @internal */
    private function __construct()
    {
    }

    /**
     * Remove trailing first-occurrence counters from one serialized
     * token or label. Pure; numbered input is returned unchanged.
     */
    public static function strip(string $token): string
    {
        $stripped = preg_replace('/__(V|CALL|PROP|CLASS)\d+$/', '__$1', $token);
        // preg_replace only fails on catastrophic backtracking; this
        // pattern cannot backtrack pathologically, but never launder a
        // null into a string silently.
        if ($stripped === null) {
            throw new \RuntimeException('identifier id strip failed for token: ' . $token);
        }
        return $stripped;
    }

    /**
     * True when the token carries a numbered rename — used by the
     * mode-lock pins to prove the knob actually bites on real ASTs.
     */
    public static function isNumbered(string $token): bool
    {
        $result = preg_match('/__(V|CALL|PROP|CLASS)\d+$/', $token);
        if ($result === false) {
            throw new \RuntimeException('identifier id probe failed for token: ' . $token);
        }
        return $result === 1;
    }
}
