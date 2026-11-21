<?php
declare(strict_types=1);

namespace Phpdup\Util;

/**
 * Strips the rename suffix that the Normalizer stamps onto renamed
 * identifiers: first-occurrence numeric counters (`__V3`, legacy
 * `__CALL12`) and — since the engine-002 sentinel redesign — name
 * stamps on calls/properties/classes (`__CALL_save`, `__PROP_total`,
 * `__CLASS_App\Db`).
 *
 * Why a shared helper instead of two call-site regexes: item 1.1's
 * lockstep law. The n-gram bag and the TED labels MUST un-number
 * together — bag-only un-numbering promotes guard-drift pairs into
 * the Jaccard tier where numbered `labelOf` output corrupts the edit
 * score and drops pairs containment accepts today (improve_logic.md
 * §3.3 row-2 is the counterexample). One helper, consumed by
 * NgramFingerprint and AptedDistance::labelOf, makes the drift
 * structurally impossible. Folding the name stamps here is what keeps
 * aggressive mode's cross-vocabulary shape twins scoring as
 * near-duplicates after the exact tier turned name-honest (engine-002).
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
 * Deliberate no-ops (suffix-less by construction, so the regexes
 * leave them alone): `__FN`, `__STR`, `__MATCH__`, `__DB_<op>__`,
 * structural-function keep-list names, and `$this`. The stamped
 * branch covers CALL/PROP/CLASS only — variables are ordinal by
 * design, so a user identifier that merely looks stamped (a literal
 * `$__V_foo`) is never folded.
 */
final class IdentifierIds
{
    /** Numbered rename suffixes (variable ordinals; legacy call stamps). */
    private const NUMBERED = '/__(V|CALL|PROP|CLASS)\d+$/';

    /**
     * Name-stamped rename suffixes from the engine-002 redesign:
     * `__CALL_<name>` / `__PROP_<name>` / `__CLASS_<qualified name>`
     * (identifier characters plus namespace separators).
     */
    private const STAMPED = '/__(CALL|PROP|CLASS)_[A-Za-z0-9_\\\\]+$/';

    /** @internal */
    private function __construct()
    {
    }

    /**
     * Remove trailing rename suffixes (counters and name stamps) from
     * one serialized token or label. Pure; unstamped input is returned
     * unchanged. This is the un-numbered KNOB's fold — the counter
     * branch is mode-gated; the stamp branch is redundant here because
     * every consumer runs {@see foldStamps()} first.
     */
    public static function strip(string $token): string
    {
        $stripped = self::foldStamps($token);
        $stripped = preg_replace(self::NUMBERED, '__$1', $stripped);
        // preg_replace only fails on catastrophic backtracking; these
        // patterns cannot backtrack pathologically, but never launder a
        // null into a string silently.
        if ($stripped === null) {
            throw new \RuntimeException('identifier id strip failed for token: ' . $token);
        }
        return $stripped;
    }

    /**
     * Collapse the engine-S02a name stamps (`__CALL_send`,
     * `__PROP_total`, `__CLASS_App\Db`) to their bare sentinels in BOTH
     * numbering modes.
     *
     * This fold is deliberately mode-independent: the counters it joins
     * in the un-numbered mode are first-occurrence ordinals whose
     * spread item 1.1 made an explicit knob, but a name stamp is the
     * canonicalisation of the identifier's IDENTITY — the aggressive
     * pass's contract has always been that call/property/class
     * vocabularies never disambiguate in the SIMILARITY tiers (only in
     * the exact tier, which is precisely what engine-002 re-honoured).
     * Folding here, in the same shared helper, keeps bag↔label lockstep
     * for both consumers.
     */
    public static function foldStamps(string $token): string
    {
        $folded = preg_replace(self::STAMPED, '__$1', $token);
        if ($folded === null) {
            throw new \RuntimeException('identifier stamp fold failed for token: ' . $token);
        }
        return $folded;
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
