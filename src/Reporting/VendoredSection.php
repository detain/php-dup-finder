<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;
use Phpdup\Scanning\VendorMarker;

/**
 * Item 5.2 (W2b) — the 'vendored duplicates' section.
 *
 * Info-doc ruling (§5.2 design line): "auto-demote vendored↔vendored
 * under --scope". Armed here by a scope filter that is NOT 'all'; a
 * fully-vendored cluster (every member file carries a
 * {@see VendorMarker} signal — vendor tree, composer.lock package path,
 * or known upstream header) is a duplicate OF third-party code, not a
 * first-party finding, and moves out of the reported/measured slice
 * into its own section. Mixed clusters (a first-party member alongside
 * a vendored copy — the "you forked the package and edited one twin"
 * archetype) are NOT vendored↔vendored and stay in the main report,
 * untouched, per the ruling's exact pair semantics.
 *
 * Law notes:
 *  - This partition runs right AFTER the --scope filter, on the already
 *    filtered ranked set: gates and reporters keep seeing exactly the
 *    slice they report (the 5.1 order law), with the vendored rows
 *    pulled out — the doc's stated purpose is that scope/multi-root
 *    denominators stop being vendor-polluted.
 *  - Presentation-capped, never counting-capped (the idiom_census /
 *    history MAX_ROWS posture): `clusters`/`lines` totals cover every
 *    demoted cluster; `rows` truncates at MAX_ROWS with `omitted`
 *    naming the truncation.
 *  - Members keep their order inside a row and rows keep rank order —
 *    no rescoring, no re-sorting, deterministic under any input order
 *    the Ranker already settled.
 *  - Unarmed runs and vendored-free corpora return the input set
 *    untouched plus a zero-shaped section (null only when the pass was
 *    never armed — the caller owns that tri-state; here an armed split
 *    over a clean corpus is honestly {0,0,0,[]}).
 */
final class VendoredSection
{
    /** Rows presented in the section (presentation bound, never a counting bound). */
    public const MAX_ROWS = 50;

    /** Member locations named per row before '…' truncation is folded into the cap. */
    public const MAX_LOCATIONS = 8;

    private function __construct()
    {
    }

    /**
     * @param list<Cluster> $clusters rank-ordered scope-filtered set
     * @return array{main: list<Cluster>, section: array<string, mixed>}
     */
    public static function split(array $clusters, VendorMarker $marker): array
    {
        $main    = [];
        $rows    = [];
        $total   = 0;
        $lines   = 0;
        $omitted = 0;

        foreach ($clusters as $cluster) {
            if (!self::isFullyVendored($cluster)) {
                $main[] = $cluster;
                continue;
            }
            $clusterLines = $cluster->totalLines();
            $total++;
            $lines += $clusterLines;
            if (count($rows) >= self::MAX_ROWS) {
                $omitted++;
                continue;
            }
            $rows[] = self::row($cluster, $clusterLines, $marker);
        }

        return [
            'main'    => $main,
            'section' => [
                'clusters' => $total,
                'lines'    => $lines,
                'omitted'  => $omitted,
                'rows'     => $rows,
            ],
        ];
    }

    /**
     * A cluster is vendored↔vendored iff it has members and EVERY
     * member file is marked. The stamping restamp runs before this
     * split (ReportStage), so the field speaks for THIS run's roots.
     */
    public static function isFullyVendored(Cluster $cluster): bool
    {
        if ($cluster->members === []) {
            return false;
        }
        foreach ($cluster->members as $member) {
            if (!$member->vendored) {
                return false;
            }
        }
        return true;
    }

    /**
     * One section row: identity (id/kind), size (members/files/lines)
     * and evidence (the distinct marker labels + the first member
     * locations). The whole row is pass-through of state the run
     * already holds — no re-clustering, no rescoring, no file reads
     * beyond what the marker memo already covers.
     *
     * @return array<string, mixed>
     */
    private static function row(Cluster $cluster, int $clusterLines, VendorMarker $marker): array
    {
        $files    = [];
        $signals  = [];
        $locations = [];
        foreach ($cluster->members as $member) {
            $files[$member->file] = true;
            $label = $marker->describeLabel($member->file);
            if ($label !== '') {
                $signals[$label] = true;
            }
            if (count($locations) < self::MAX_LOCATIONS) {
                $locations[] = $member->location();
            }
        }
        $signalList = array_keys($signals);
        sort($signalList);

        return [
            'id'        => $cluster->id,
            'kind'      => $cluster->members[0]->kind,
            'members'   => count($cluster->members),
            'files'     => count($files),
            'lines'     => $clusterLines,
            'signals'   => $signalList,
            'locations' => $locations,
        ];
    }
}
