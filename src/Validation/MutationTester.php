<?php
declare(strict_types=1);

namespace Phpdup\Validation;

use Phpdup\Clustering\Cluster;
use Phpdup\Semantic\DataflowSummarizer;

/**
 * Best-effort mutation tester: probes whether each cluster member
 * appears to be behaviourally substitutable for the suggested
 * abstraction.
 *
 * The full vision (compile abstraction → run member-shaped inputs
 * through it → compare outputs) needs a sandbox runtime and is out
 * of scope here. This skeleton implements a *static-only* check
 * that flags clusters where members carry side effects we can't
 * easily replay (DB calls, file I/O, header writes). Reporters
 * surface these as a `behavioural-divergence` warning so reviewers
 * know to mutation-test the cluster manually.
 *
 * Probe accounting (php-dup-finder-output-031): a member whose AST
 * was not loaded cannot be probed. The check now distinguishes
 * probed members from unprobed ones and never lets the unprobed
 * tail silently masquerade as a clean verdict.
 */
final class MutationTester
{
    public function __construct(
        private readonly DataflowSummarizer $summarizer = new DataflowSummarizer(),
    ) {
    }

    /**
     * Probe a cluster for behavioural divergence between members.
     *
     * Empty list = no static divergence detected. A cluster with any
     * unprobed member (AST not loaded — lazy-AST mode on big trees)
     * never returns an empty list: an all-unprobed cluster yields a
     * refusal verdict, and a partially-probed cluster carries an
     * accounting line naming how many members the verdict covers.
     *
     * @return list<string> Short reason strings.
     */
    public function probe(Cluster $cluster): array
    {
        $reasons = [];
        $probed = 0;
        $unprobed = 0;
        $sideEffectCount = 0;
        foreach ($cluster->members as $m) {
            if ($m->ast === null) {
                $unprobed++;
                continue;
            }
            $probed++;
            $summary = $this->summarizer->summarize($m->ast);
            if ($summary['sideEffects']) {
                $sideEffectCount++;
            }
        }

        if ($unprobed > 0 && $probed === 0) {
            $reasons[] = sprintf(
                'all %d members unprobed (AST not loaded) — cannot issue a clean verdict; run mutation tests manually',
                $unprobed,
            );

            return $reasons;
        }

        if ($unprobed > 0) {
            $reasons[] = sprintf(
                '%d of %d probed (%d unprobed: AST not loaded)',
                $probed,
                $probed + $unprobed,
                $unprobed,
            );
        }

        if ($sideEffectCount > 0 && $sideEffectCount < $probed) {
            $reasons[] = sprintf(
                'side-effects present in %d of %d probed members — abstraction may be lossy',
                $sideEffectCount,
                $probed,
            );
        }
        if ($sideEffectCount > 0 && $sideEffectCount === $probed) {
            $reasons[] = 'all probed members emit side effects — auto-replay not safe; run mutation tests manually';
        }

        return $reasons;
    }
}
