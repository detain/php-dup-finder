<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Util\PathCanonical;

/**
 * Canonical finding identity (item 4.4, the DECIDED recipe [f11]§4.4-design;
 * re-canonicalized in the phpver-fix, see below).
 *
 * A finding id is `F` + the first 16 hex digits of
 * sha256(implode('|', per-member sha256(canonicalFile . '|' . structuralHash),
 * sorted)). Every ingredient is content: no scan index, no edge-arrival
 * order, no line position and no union-find root can move an id — so
 * serial vs parallel runs, cache round-trips and unrelated edits
 * elsewhere in the tree all agree on the identity of the same finding
 * (hazard-4's dual X…/C… streams retire in favour of this one).
 *
 * Portability law (phpver-fix): the original recipe hashed the raw
 * member path — absolute in most invocations — so the same cluster
 * minted different ids on the laptop that generated a golden snapshot
 * and on a CI runner reading it (first observed: golden F72d077a… vs
 * runner F4f7377c… for byte-identical clusters, purely from
 * /home/sites vs /home/runner path prefixes). With scan roots passed
 * at the mint sites, each member contributes its path relative to the
 * longest matching configured root (util PathCanonical), and the
 * structuralHash beneath it now renders float literals as IEEE-754
 * bytes rather than the version-varying decimal cast. Ids are stable
 * across machines for a given scan; an unrooted path (single-file run,
 * synthetic test block) falls back to its separator-folded form — raw
 * bytes never carry a drive prefix (C:\…) into a portable id.
 *
 * Cross-run portability (once-044, note to S50): root matching folds
 * separators and ASCII case inside PathCanonical before stripping, so
 * the same tree scanned with natively different join spellings
 * (Windows C:\proj\… vs. a runner /proj/…, single-file roots, roots
 * quoted in another case) yields the same canonical member paths — and
 * therefore the same ids — making ids minted on one OS consumable on
 * another. Slash-form scans are byte-for-byte unchanged.
 *
 * Growth or shrink of the member set deliberately changes the id: a
 * previously accepted divergence whose cluster changes shape resurfaces
 * once instead of riding along on a stale acceptance. That is the
 * fail-open direction ruling §5e chose.
 */
final class FindingId
{
    /**
     * Identity of a materialized cluster — the content set of its members.
     *
     * @param list<string> $scanRoots configured scan roots (raw form)
     */
    public static function for(Cluster $cluster, array $scanRoots = []): string
    {
        return self::forMembers($cluster->members, $scanRoots);
    }

    /**
     * Identity of a member set, before a Cluster wrapper exists (the
     * three mint sites inside Clusterer::cluster() pass members the
     * instant the group is known, along with the configured roots).
     *
     * @param list<Block> $members
     * @param list<string> $scanRoots
     */
    public static function forMembers(array $members, array $scanRoots = []): string
    {
        $digests = [];
        foreach ($members as $member) {
            $digests[] = hash('sha256', PathCanonical::underRoots($member->file, $scanRoots) . '|' . $member->structuralHash);
        }
        sort($digests, SORT_STRING);

        return 'F' . substr(hash('sha256', implode('|', $digests)), 0, 16);
    }
}
