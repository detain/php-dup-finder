<?php
declare(strict_types=1);

namespace Phpdup\Census;

/**
 * One reported shared call-protocol row (item 3.5): the ordered callee
 * names of the maximal window, its corpus support, canonical location
 * samples, and the per-slot table of differing constant arguments
 * ("Report each protocol with its sites and a table of constant
 * arguments that differ").
 *
 * argDiff rows are slot-major and value-ordered deterministically:
 * {slot, call, values: list<{token, sites}>, none} where `values`
 * counts the constant-argument witnesses seen at that slot ACROSS THE
 * FULL CORPUS (the miner's per-token caps bound one occurrence, the
 * census counters themselves are corpus-complete — a 79/80 split like
 * the doc's cURL story surfaces in full) and `none` counts the
 * occurrences of the window that carried no token there (holes). The
 * table lists at most ARG_VALUES_PER_SLOT tokens per slot, count desc
 * then token asc — "a table of constant arguments that differ" is the
 * letter this row serves.
 */
final class CallProtocolRow
{
    /** Distinct argument tokens listed per slot (counts, disclosed). */
    public const ARG_VALUES_PER_SLOT = 8;

    /**
     * @param list<string> $names
     * @param list<string> $locations
     * @param list<array{slot:int,call:string,values:list<array{token:string,sites:int}>,none:int}> $argDiff
     */
    public function __construct(
        public readonly array $names,
        public readonly int $length,
        public readonly int $sites,
        public readonly int $files,
        public readonly array $locations,
        public readonly array $argDiff,
    ) {
    }
}
