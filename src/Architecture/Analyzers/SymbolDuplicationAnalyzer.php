<?php
declare(strict_types=1);

namespace Phpdup\Architecture\Analyzers;

use Phpdup\Architecture\ArchitecturalAnalyzer;
use Phpdup\Architecture\Finding;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Index\SymbolIndex;
use Phpdup\Similarity\ContainmentSimilarity;
use Phpdup\Similarity\JaccardSimilarity;

/**
 * Item 3.4 (--symbols): the duplicate-symbol evidence analyzer.
 *
 * The Clusterer side of the lane (SymbolIndex + forced-pair injection)
 * guarantees that every same-name / twin-class / same-basename pair is
 * *compared* regardless of the bag/df heuristics. This analyzer turns the
 * outcome into a report line: for each forced pair whose endpoints involve
 * this cluster, it measures the pair at the report's own normalisation
 * (Jaccard over the run's live ngram bags, containment + size ratio) and
 * files it as an architectural finding:
 *
 *   - symbol-pair-merged — both endpoints are members of this cluster: the
 *     symbol route fed a pair the heuristics might never have proposed, and
 *     the regular scoring accepted it. The cluster's own pair rows carry
 *     the authoritative tier; the finding names the route.
 *   - symbol-pair-shadow — exactly one endpoint is a member: the partner
 *     shares the symbol but is not in this cluster. The message reports the
 *     measured similarities against the run's floor — a below-floor partner
 *     is the "same filename different logic" evidence, not a merge.
 *
 * Mount is conditional and inert-by-default: ReportStage appends this
 * analyzer only when symbols_forced is armed, so every non-armed run —
 * including the frozen Goldens — never constructs it and the payload is
 * byte-identical (DriftAnalyzer's inert-mount precedent).
 *
 * Known scope limit (disclosed in the S4 lane report): a forced pair whose
 * NEITHER endpoint lands in any reported cluster — two fully unique files
 * whose same-name blocks diverged below every floor — has no carrier in the
 * existing architectural-findings spine (findings are cluster-bound; an
 * unclustered orphan pair rides only the candidate stream and the scoring
 * counters). Row-level surfacing for that residual is a payload-shape
 * change; the schema/report-token window belongs to the ROLLUP lane, so
 * this lane feeds the spine zero new keys and reports the gap instead of
 * minting one. The acceptance corpus therefore pins the shadow case through
 * a carrier cluster (both real myadmin-style shapes: shadowed helpers whose
 * files do share boilerplate with other sites).
 */
final class SymbolDuplicationAnalyzer implements ArchitecturalAnalyzer
{
    public const CODE_MERGED = 'symbol-pair-merged';
    public const CODE_SHADOW = 'symbol-pair-shadow';

    private JaccardSimilarity $jaccard;

    private ContainmentSimilarity $containment;

    /** @var array<string, Block> block id => block, for resolving partner sites */
    private array $byId = [];

    /**
     * @param SymbolIndex $symbols  built over the run's final BlockIndex (same
     *                              inputs as the cluster pass ⇒ same pairs,
     *                              pinned by the determinism test)
     */
    public function __construct(
        private readonly SymbolIndex $symbols,
        private readonly float $similarityThreshold = 0.8,
    ) {
        $this->jaccard = new JaccardSimilarity();
        $this->containment = new ContainmentSimilarity();
        foreach ($symbols->blocksById() as $id => $block) {
            $this->byId[$id] = $block;
        }
    }

    /**
     * @return list<Finding>
     */
    #[\Override]
    public function analyze(Cluster $cluster): array
    {
        $memberIds = [];
        foreach ($cluster->members as $m) {
            $memberIds[$m->id] = true;
        }

        $findings = [];
        $seen = [];
        foreach ($this->symbols->forcedPairs() as [$lo, $hi, $reason]) {
            $inLo = isset($memberIds[$lo]);
            $inHi = isset($memberIds[$hi]);
            if (!$inLo && !$inHi) {
                continue;
            }

            // forcedPairs() only ever references ids inside blocksById(),
            // so the lookups below are total by construction.
            $a = $this->byId[$lo];
            $b = $this->byId[$hi];

            $measured = $this->measure($a, $b);
            $both = $inLo && $inHi;
            $route = SymbolIndex::routeLabel($reason);

            if ($both) {
                $message = sprintf(
                    'forced symbol pair (%s route, "%s") entered comparison through the symbol lane and merged here: %s <-> %s; measured jaccard %s (floor %s), containment %s.',
                    $route,
                    $this->symbolOf($reason, $a, $b),
                    $a->location(),
                    $b->location(),
                    self::pct($measured[0]),
                    self::pct($this->similarityThreshold),
                    self::pct($measured[1]),
                );
                if (isset($seen[$message])) {
                    continue;
                }
                $seen[$message] = true;
                $findings[] = new Finding(
                    analyzer: 'SymbolDuplication',
                    code: self::CODE_MERGED,
                    message: $message,
                    severity: Finding::SEVERITY_NOTE,
                    suggestion: null,
                );
                continue;
            }

            $there = $inLo ? $b : $a;
            $merged = $measured[0] >= $this->similarityThreshold;
            $sameSymbol = $reason !== SymbolIndex::REASON_BASENAME;
            $message = sprintf(
                '%s %s at %s is not in this cluster: measured jaccard %s %s floor %s (containment %s, size ratio %s). %s',
                $sameSymbol ? 'same-symbol partner' : 'same-basename-file partner',
                $there->qualifiedName(),
                $there->location(),
                self::pct($measured[0]),
                $merged ? 'at or above the' : 'below the',
                self::pct($this->similarityThreshold),
                self::pct($measured[1]),
                self::pct($measured[2]),
                $merged
                    ? 'The scoring lanes decided the structures apart (tier refutation or split); verify the payloads.'
                    : ($sameSymbol
                        ? 'Same name, different logic — evidence, not a merge.'
                        : 'Files share a basename; these sites are not the same symbol — comparison ran, no merge claimed.'),
            );
            if (isset($seen[$message])) {
                continue;
            }
            $seen[$message] = true;
            $findings[] = new Finding(
                analyzer: 'SymbolDuplication',
                code: self::CODE_SHADOW,
                message: $message,
                severity: $sameSymbol ? Finding::SEVERITY_WARNING : Finding::SEVERITY_NOTE,
                suggestion: $sameSymbol
                    ? 'Compare the two sites deliberately: the symbol lane forced the comparison the similarity heuristics skipped.'
                    : null,
            );
        }

        return $findings;
    }

    /**
     * @return array{0: float, 1: float, 2: float} [jaccard, containment, sizeRatio]
     */
    private function measure(Block $a, Block $b): array
    {
        $bagA = $a->ngramBag ?? [];
        $bagB = $b->ngramBag ?? [];
        $jac = $this->jaccard->similarity($bagA, $bagB);
        $cont = $this->containment->containmentAndSizeRatio($bagA, $bagB);

        return [$jac, $cont['containment'], $cont['sizeRatio']];
    }

    /**
     * The shared symbol the route keyed on, for the message.
     */
    private function symbolOf(string $reason, Block $a, Block $b): string
    {
        return match ($reason) {
            SymbolIndex::REASON_CLASS => (string) $a->class,
            SymbolIndex::REASON_BASENAME => (string) basename($a->file),
            default => (string) $a->name,
        };
    }

    private static function pct(float $v): string
    {
        return number_format($v, 3, '.', '');
    }
}
