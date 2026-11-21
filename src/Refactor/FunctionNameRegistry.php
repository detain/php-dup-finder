<?php
declare(strict_types=1);

namespace Phpdup\Refactor;

use Phpdup\Clustering\Cluster;

/**
 * Cross-cluster function-name uniqueness (W4-B fix M3/M4).
 *
 * SignatureBuilder picks each cluster's function name in isolation — and
 * in parallel runs it runs inside forked RefactorWorkers, where no shared
 * in-memory state is possible. That leaves two shipped artifacts racing in
 * one shared namespace: ApplyExtractor/RefactorPatchReporter emit per-
 * cluster files under `namespace Refactored;`, so two clusters mining the
 * same verb+noun (array_key_exists ×3, is_array ×3, __construct ×4 on the
 * real src corpus) collide into a redeclare fatal the moment a human
 * requires two generated files together. The pre-5.4 posture was strictly
 * worse (109 identical `extractedFunction` files) — mining redistributed
 * the collision into plausible human names, it did not introduce it.
 *
 * The cure is one pass at the merge point, which is also the single
 * name-decision point in the post-worker flow: EVERY consumer
 * (ApplyExtractor::extractFunctionName, the patch declaration emitted from
 * `$cluster->signature`, the member-hint guidance, JSON/CLI display)
 * derives the name from `$cluster->signature`, so rewriting the signature
 * here — once, uniquely — resolves every surface in lockstep.
 *
 * Collision policy: first claim (in ranked report order) keeps the mined
 * name; the loser gains a suffix derived from its CONTENT cluster id —
 * never a list index — so the resolved name is reproducible across runs
 * and independent of how the list arrived here.
 *
 * Lifecycle law (P4/PipelineState family): the registry is constructed
 * fresh inside ReportStage::run() — ONE instance per report payload. It
 * MUST NOT be made static or cached across cycles: Watch and Server re-run
 * the pipeline in the same process, and cross-run claims would smuggle
 * another cycle's ordering into this payload's names.
 */
final class FunctionNameRegistry
{
    /** @var int Hex characters of the cluster id used for the first suffix tier. */
    private const SHORT_ID = 8;

    /** @var array<string, string> resolved name → claiming cluster id */
    private array $claims = [];

    /**
     * Resolve every cluster's function name to a run-unique one.
     *
     * @param list<Cluster> $clusters in final ranked report order
     * @return int number of clusters renamed (0 = corpus was already unique)
     */
    public function resolve(array $clusters): int
    {
        $renamed = 0;
        foreach ($clusters as $c) {
            if ($c->signature === null) {
                continue; // no extraction proposed, nothing to name
            }
            $name = self::nameOf($c->signature);
            if ($name === null) {
                continue; // signature text we cannot parse; consumers keep their own fallbacks
            }
            $owner = $this->claims[$name] ?? null;
            if ($owner === null || $owner === $c->id) {
                // First claim wins verbatim; re-resolving a payload this
                // instance already named is a no-op (idempotent pass).
                $this->claims[$name] = $c->id;
                continue;
            }
            $unique         = $this->uniqueName($name, $c->id);
            $c->signature   = self::withName($c->signature, $unique);
            $this->claims[$unique] = $c->id;
            $renamed++;
        }
        return $renamed;
    }

    /** Extract the function name a signature declares (single parsing truth). */
    public static function nameOf(string $signature): ?string
    {
        return preg_match('/^function\s+([a-zA-Z_][a-zA-Z0-9_]*)/', $signature, $m) === 1
            ? $m[1]
            : null;
    }

    /** Swap the declared name inside a signature, preserving the rest verbatim. */
    public static function withName(string $signature, string $name): string
    {
        return (string) preg_replace(
            '/^function\s+[a-zA-Z_][a-zA-Z0-9_]*/',
            'function ' . $name,
            $signature,
            1,
        );
    }

    /**
     * Claimed names → original cluster ids (inspection/tests only).
     *
     * @return array<string,string>
     */
    public function claims(): array
    {
        return $this->claims;
    }

    /**
     * Grow a content-derived suffix (short id → full id → sha256 of id)
     * until the candidate is unclaimed. Ids are content hashes, so two
     * clusters sharing a suffix prefix still separate on the longer tier.
     */
    private function uniqueName(string $name, string $clusterId): string
    {
        $short = (string) preg_replace('/[^A-Za-z0-9]/', '', $clusterId);
        $tiers = [];
        for ($take = self::SHORT_ID; strlen($short) > $take; $take += self::SHORT_ID) {
            $tiers[] = substr($short, 0, $take);
        }
        $tiers[] = $short;
        $tiers[] = hash('sha256', $clusterId);
        foreach (array_unique($tiers) as $suffix) {
            $candidate = $name . '_' . $suffix;
            if (!isset($this->claims[$candidate])) {
                return $candidate;
            }
        }
        // Practically unreachable: the tiers are content-derived and the
        // final one is a hash of the id itself.
        throw new \LogicException("FunctionNameRegistry could not mint a unique name for {$name} (cluster {$clusterId})");
    }
}
