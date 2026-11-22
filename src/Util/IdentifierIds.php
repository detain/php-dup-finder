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
 * Deliberate no-ops (suffix-less by construction, so the walker
 * leaves them alone): `__FN`, `__STR`, `__MATCH__`, `__DB_<op>__`,
 * structural-function keep-list names, and `$this`. The stamped
 * branch covers CALL/PROP/CLASS only — variables are ordinal by
 * design, so a user identifier that merely looks stamped (a literal
 * `$__V_foo`) is never folded.
 *
 * Engine-S38 (engine-053): the two end-anchored `preg_replace` calls
 * this class used to run per token paid the PCRE engine on every
 * node label, ×nodes ×2 ×pairs inside the Apted loop with no memo.
 * The trim is now a regex-free anchored-tail walk (the discovery's
 * first named remedy) wrapped in a bounded per-process memo (its
 * second: "distinct-token set ≪ call count"). Measured on the replay
 * shape (small distinct-label set re-folded per pair, no opcache JIT):
 * legacy PCRE ~0.29 µs/call vs memoized walk ~0.21 µs/call steady
 * state (~1.4×; hits cost an array fetch, ~0.07 µs, minus the
 * interpreted call overhead); a cold first-sight walk costs ~1.2 µs,
 * which the replay-heavy call pattern amortises (all numbers disclosed
 * in reviews/exec/engine-S38.impl.md, not hidden). The walk replicates
 * the old PCRE semantics byte-exactly — leftmost sentinel start wins,
 * the run must reach the string end, `$` also asserts before a single
 * trailing newline, and classes are compared per byte (the old patterns
 * had no /u). Proven by the equivalence property pins in
 * tests/Unit/Util/IdentifierIdsTest.php, which replay the legacy
 * patterns verbatim as the oracle over seeded random and adversarial
 * corpora.
 */
final class IdentifierIds
{
    /**
     * Numbered rename suffixes (variable ordinals; legacy call/prop/class
     * stamps): sentinel literal at the tail-run start => its folded form.
     */
    private const COUNTER_PREFIXES = ['__V' => '__V', '__CALL' => '__CALL', '__PROP' => '__PROP', '__CLASS' => '__CLASS'];

    /**
     * Name-stamped rename suffixes from the engine-002 redesign:
     * `__CALL_<name>` / `__PROP_<name>` / `__CLASS_<qualified name>`
     * (identifier characters plus namespace separators).
     */
    private const STAMP_PREFIXES = ['__CALL_' => '__CALL', '__PROP_' => '__PROP', '__CLASS_' => '__CLASS'];

    /** Bytes the counter run may hold — identical to the old `\d` (ASCII). */
    private const COUNTER_RUN = '0123456789';

    /** Bytes the stamp run may hold — the old `[A-Za-z0-9_\\]` class. */
    private const STAMP_RUN = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_\\';

    /**
     * Memo bounds (per process): the cache maps each token to the pure
     * walker's output, so neither bound affects any value — only how
     * many results are kept. On overflow the memo resets to empty,
     * which keeps memory flat across long-lived serve/watch processes
     * while still absorbing the small hot label set of a burst.
     */
    private const MEMO_MAX_ENTRIES = 8192;
    private const MEMO_MAX_BYTES = 2_097_152;

    /** @var array<string,string> */
    private static array $foldMemo = [];
    private static int $foldMemoBytes = 0;

    /** @var array<string,string> */
    private static array $stripMemo = [];
    private static int $stripMemoBytes = 0;

    /** @internal */
    private function __construct()
    {
    }

    /**
     * Remove trailing rename suffixes (counters and name stamps) from
     * one serialized token or label. Value-pure; unstamped input is
     * returned unchanged. This is the un-numbered KNOB's fold — the
     * counter branch is mode-gated; the stamp branch is redundant here
     * because every consumer runs {@see foldStamps()} first.
     */
    public static function strip(string $token): string
    {
        return self::$stripMemo[$token] ?? self::rememberStrip($token);
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
        return self::$foldMemo[$token] ?? self::rememberFold($token);
    }

    /**
     * True when the token carries a numbered rename — used by the
     * mode-lock pins to prove the knob actually bites on real ASTs.
     */
    public static function isNumbered(string $token): bool
    {
        return self::trimAnchoredTail($token, self::COUNTER_PREFIXES, self::COUNTER_RUN) !== null;
    }

    private static function rememberFold(string $token): string
    {
        $folded = self::trimAnchoredTail($token, self::STAMP_PREFIXES, self::STAMP_RUN) ?? $token;
        if (count(self::$foldMemo) >= self::MEMO_MAX_ENTRIES || self::$foldMemoBytes >= self::MEMO_MAX_BYTES) {
            self::$foldMemo = [];
            self::$foldMemoBytes = 0;
        }
        self::$foldMemoBytes += strlen($token);

        return self::$foldMemo[$token] = $folded;
    }

    private static function rememberStrip(string $token): string
    {
        $folded = self::foldStamps($token);
        $stripped = self::trimAnchoredTail($folded, self::COUNTER_PREFIXES, self::COUNTER_RUN) ?? $folded;
        if (count(self::$stripMemo) >= self::MEMO_MAX_ENTRIES || self::$stripMemoBytes >= self::MEMO_MAX_BYTES) {
            self::$stripMemo = [];
            self::$stripMemoBytes = 0;
        }
        self::$stripMemoBytes += strlen($token);

        return self::$stripMemo[$token] = $stripped;
    }

    /**
     * Regex-free equivalent of one old end-anchored `preg_replace`:
     * find the leftmost sentinel prefix (a match, being `$`-anchored,
     * can only start at an occurrence of `__`) followed by a run of at
     * least one run-class byte that reaches the string end — or, as the
     * old PCRE `$` also asserted, the end minus a single trailing
     * newline. Returns the folded token (prefix kept, run dropped) or
     * null when no anchored tail matches.
     *
     * @param array<string,string> $prefixes sentinel literal => folded replacement
     * @param string $runBytes the run's byte class (strspn-style set)
     */
    private static function trimAnchoredTail(string $token, array $prefixes, string $runBytes): ?string
    {
        $subject = $token;
        $lineBreak = '';
        if (str_ends_with($subject, "\n")) {
            $subject = substr($subject, 0, -1);
            $lineBreak = "\n";
        }
        $start = strpos($subject, '__');
        if ($start === false) {
            return null; // every sentinel literal opens with two underscores
        }
        $lastRunStart = strlen($subject) - 1;
        if (strspn($subject, $runBytes, $lastRunStart) === 0) {
            // The anchored run must consume the very last byte. This is an
            // intentional fast-fail short-circuit, redundant by construction:
            // the runBoundary math below already rejects the same input, so
            // the guard is deliberately unpinned — deleting it turns no test
            // red (engine-S38 review r1, NIT-2/MUT4).
            return null;
        }
        // The run reaches the end, so a match's run starts at or after the
        // beginning of the longest run-class suffix.
        $runBoundary = strlen(rtrim($subject, $runBytes));
        do {
            foreach ($prefixes as $prefix => $folded) {
                $runStart = $start + strlen($prefix);
                if ($runStart < $runBoundary || $runStart > $lastRunStart) {
                    continue; // straddles the boundary, or leaves no room for the run
                }
                if (substr_compare($subject, $prefix, $start, strlen($prefix)) === 0) {
                    return substr($subject, 0, $start) . $folded . $lineBreak;
                }
            }
        } while (($start = strpos($subject, '__', $start + 1)) !== false);

        return null;
    }
}
