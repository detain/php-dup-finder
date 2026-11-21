<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;

/**
 * Canonical finding identity (item 4.4, the DECIDED recipe [f11]§4.4-design).
 *
 * A finding id is `F` + the first 16 hex digits of
 * sha256(implode('|', per-member sha256(file . '|' . structuralHash),
 * sorted)). Every ingredient is content: no scan index, no edge-arrival
 * order, no line position and no union-find root can move an id — so
 * serial vs parallel runs, cache round-trips and unrelated edits
 * elsewhere in the tree all agree on the identity of the same finding
 * (hazard-4's dual X…/C… streams retire in favour of this one).
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
     */
    public static function for(Cluster $cluster): string
    {
        return self::forMembers($cluster->members);
    }

    /**
     * Identity of a member set, before a Cluster wrapper exists (the
     * three mint sites inside Clusterer::cluster() pass members the
     * instant the group is known).
     *
     * @param list<Block> $members
     */
    public static function forMembers(array $members): string
    {
        $digests = [];
        foreach ($members as $member) {
            $digests[] = hash('sha256', $member->file . '|' . $member->structuralHash);
        }
        sort($digests, SORT_STRING);

        return 'F' . substr(hash('sha256', implode('|', $digests)), 0, 16);
    }
}
