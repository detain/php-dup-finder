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
     * @return list<string> Short reason strings; empty list = no
     *                      static divergence detected.
     */
    public function probe(Cluster $cluster): array
    {
        $reasons = [];
        $sideEffectCount = 0;
        foreach ($cluster->members as $m) {
            if ($m->ast === null) {
                continue;
            }
            $summary = $this->summarizer->summarize($m->ast);
            if ($summary['sideEffects']) {
                $sideEffectCount++;
            }
        }
        if ($sideEffectCount > 0 && $sideEffectCount < count($cluster->members)) {
            $reasons[] = sprintf(
                'side-effects present in %d of %d members — abstraction may be lossy',
                $sideEffectCount,
                count($cluster->members),
            );
        }
        if ($sideEffectCount === count($cluster->members) && $sideEffectCount > 0) {
            $reasons[] = 'all members emit side effects — auto-replay not safe; run mutation tests manually';
        }
        return $reasons;
    }
}
