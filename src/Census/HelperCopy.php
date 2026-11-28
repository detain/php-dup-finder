<?php

declare(strict_types=1);

namespace Phpdup\Census;

/**
 * One helper-adoption group (item 3.2): a small function/method whose
 * body contains a mined idiom pattern, plus every occurrence of that
 * same pattern OUTSIDE the helper's body — its inline re-implementations.
 *
 * The doc-letter acceptance shape: `Env::isDev — 5 inline copies`.
 * Cluster-independent by construction: pattern groups come from the
 * census's maximal-idiom rows, never from Clusterer output.
 *
 * v1 semantics, all pinned by HelperAdoptionTest:
 *  - `defSites` counts occurrences of the pattern whose file+line falls
 *    inside THIS helper's body range (they may sit in nested control
 *    structures — the body contains them either way);
 *  - `inlineSites` = group sites - defSites, i.e. occurrences anywhere
 *    else: other files AND same-file occurrences outside this helper's
 *    range. A file that merely CALLS nothing here is indistinguishable
 *    from one that inlines the pattern's tail — call-site attribution
 *    is MINE-E (3.5) territory;
 *  - when several small helpers share one pattern (cross-file twins —
 *    e.g. both `Env::isDev` and each 5-statement `inline_*.php::handle`
 *    body contain the Equal group), EACH helper gets its own row and
 *    the others count as its copies. That is honest per-helper
 *    accounting; the group-level dedupe view is `patternsFound()` on
 *    {@see HelperAdoption}.
 *
 * Item 3.2 follow-on (MINE-E, closes the "call-site attribution is
 * MINE-E territory" line above): `callers` answers the OTHER half of
 * the doc's `Env::bool() — 6 callers, 9 inline re-implementations`.
 * Null means the call-sequence census was not armed on the run (the
 * honest absence); an int means it was, and counts call-site
 * OCCURRENCES of this helper's method name outside its own body span,
 * via the census's receiver-blind v1 join (see
 * {@see CallSequenceCensus::sitesForMethod} for the exact matching
 * rules and their over-count caveats). `callerLocations` rides the
 * same canonical sample cap as copy sites.
 */
final class HelperCopy
{
    /**
     * @param array<string,int> $inlineFiles file => copy occurrences (all files with count > 0)
     * @param list<string>      $copyLocations canonical 'file:line' samples of copy sites (<= sample cap)
     * @param list<string>      $callerLocations canonical 'file:line' samples of call sites (<= sample cap; only when census armed)
     */
    public function __construct(
        public readonly string $helperLabel,
        public readonly string $defFile,
        public readonly int $defStart,
        public readonly int $defEnd,
        public readonly string $helperKind,
        public readonly int $defStatements,
        public readonly string $patternExact,
        public readonly string $patternAbstract,
        public readonly int $nodes,
        public readonly string $rootType,
        public readonly int $sitesTotal,
        public readonly int $defSites,
        public readonly int $inlineSites,
        public readonly array $inlineFiles,
        public readonly array $copyLocations,
        public readonly ?int $callers = null,
        public readonly array $callerLocations = [],
    ) {
        if ($defStart < 1 || $defEnd < $defStart) {
            throw new \InvalidArgumentException('helper body range must be a positive ascending span, got ' . $defStart . '-' . $defEnd);
        }
        if ($sitesTotal < $defSites || $defSites < 0) {
            throw new \InvalidArgumentException(sprintf(
                'site accounting broken for %s: %d total < %d def',
                $helperLabel,
                $sitesTotal,
                $defSites,
            ));
        }
        if ($inlineSites !== $sitesTotal - $defSites) {
            throw new \InvalidArgumentException(sprintf(
                'inline sites must be sites minus def sites for %s',
                $helperLabel,
            ));
        }
    }

    /** Distinct files carrying at least one copy occurrence. */
    public function inlineFileCount(): int
    {
        return count($this->inlineFiles);
    }
}
