<?php
declare(strict_types=1);

namespace Phpdup\Clustering;

/**
 * Thrown when {@see Clusterer::generateCandidatePairs()} is asked to emit
 * more candidate pairs than its safety budget allows.
 *
 * Rationale (improve_logic.md §3.6, item 0.5): candidate generation unions
 * the inverted-index postings of every rare n-gram of a block, so a single
 * shared rare gram makes a pair. Inside a huge clone family — thousands of
 * loosely chained near-clones, the "167-member return-400 chain" failure
 * texture of §3.5 — that union grows quadratically in the family size and
 * pair output exploded to ~2,400-3,400 pairs/block (≈320M pairs, 80 GB) on
 * the observed monorepo corpora before any scoring ran. The budget bounds
 * that output: enumeration stops loudly at the cap instead of OOM-ing,
 * and the message names the knobs that shrink the pair set.
 *
 * This is a fail-fast guard, not a filter: below the budget (i.e. on every
 * corpus the tool has ever completed) the generator's output is identical
 * to the unguarded code, pair for pair, in order.
 */
final class CandidateBudgetException extends \RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /**
     * Build the actionable halt for a budget breach.
     *
     * @param int $budget        pair count the generator was allowed to yield
     * @param int $blocksScanned blocks whose candidates were enumerated before the halt
     * @param int $totalBlocks   blocks in the index
     */
    public static function exceeded(int $budget, int $blocksScanned, int $totalBlocks): self
    {
        $scanPct = $totalBlocks > 0
            ? sprintf('%.1f%%', $blocksScanned / $totalBlocks * 100)
            : 'n/a';

        return new self(sprintf(
            'Candidate generation exceeded its safety budget of %s pairs (halted after %s of %s blocks, %s).'
            . " The corpus contains a clone family so large that rare n-gram postings flood candidate"
            . " generation (pair output grows quadratically within such a family).\n"
            . 'Ways forward: lower --max-df to prune common n-grams from seeding; scan a narrower path'
            . " (or exclude the family's directories via the config \"exclude\" list);"
            . ' or run on a larger box and raise Clusterer::MAX_CANDIDATE_PAIRS.',
            number_format($budget),
            number_format($blocksScanned),
            number_format($totalBlocks),
            $scanPct,
        ));
    }
}
