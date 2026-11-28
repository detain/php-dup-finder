<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Census\NestedFamilySuppression;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;

/**
 * Item 6.3 — the subsumption fold (improve_logic.md §6.3 letter: "When
 * every member of cluster B lies inside a member of cluster A, IN THE
 * SAME PAIRING, fold B under A. Report maximal clones only.").
 *
 * Placement: {@see \Phpdup\Pipeline\Stages\ReportStage} runs this
 * pre-Ranker — after the item-1.4 medoid-ball split (which happens in
 * ClusterStage), per the split-first-subsume-after seam
 * (improve_logic_info.md §6.3): a guard-chain sub-group may split into
 * its own cluster and THEN subsume under the tighter outer family.
 *
 * The pairing law is what makes this honest. "Every member of B inside
 * SOME member of A" per-member would false-fold a cluster of sibling
 * ifs whose two witnesses sit inside ONE method member of A — the pair
 * structure says the two sibling sites are one family's copies, and
 * folding them under a family with fewer witnesses would silently
 * multiply nothing. So B folds under A iff an INJECTIVE member map
 * exists: every B member assigned to a DISTINCT A member that strictly
 * contains it (same file, outer range covers inner, coverage strict so
 * equal ranges — one block seen through two kinds — never witness a
 * fold). Maximum bipartite matching over the per-file containment edges
 * decides existence; injectivity also makes |A| >= |B| a theorem, not a
 * filter.
 *
 * Suppression itself is NOT re-implemented here: the maximal-family
 * decision, chain folding (C ⊆ B ⊆ A folds C's attribution to A; a
 * suppressed parent still suppresses its children in one shot) and the
 * deterministic survivor order all come from the §5c shared rule
 * {@see NestedFamilySuppression::keepMaximal()} — the one implementation
 * the 2.1 ≡ 3.1 ≡ 6.3 ≡ 7.2c law permits. The 6.3 support map is the
 * cluster member count and the edge map is the witnessed injective
 * pairs; by the theorem above the parent-support inequality holds for
 * every lawful 6.3 edge, so keepMaximal adds exactly the maximality,
 * chain and ordering semantics the shared law owns — nothing else.
 *
 * Chains that fold "to maximal ancestor": every subsumed cluster is
 * attributed to a SURVIVING (maximal) ancestor via its direct injective
 * edge — composition of injective containment maps guarantees each
 * suppressed cluster has a direct edge to every maximal cluster above
 * it, so attribution never has to walk and can never dangle. A dangle
 * (suppressed cluster without a surviving parent) is a producer bug and
 * fails loudly.
 *
 * Demand posture: ReportStage arms this ONLY on idiom_mining runs (the
 * census-class knob the fold's statement-fragment nesting rides; no new
 * key). Default runs never call fold(), never mutate a cluster field,
 * and keep every frozen byte.
 */
final class SubsumptionFolder
{
    /**
     * Fold pass. Mutates the annotation fields in place (the shared
     * Cluster objects — same mutation style as Ranker scoring) and
     * returns the split.
     *
     * @param list<Cluster> $clusters
     * @return array{maximal: list<Cluster>, subsumed: list<Cluster>}
     *         maximal keeps the input order minus the folded families;
     *         subsumed lists every folded cluster (annotated) in input
     *         order — dropped from the rank/report payload, reachable
     *         for follow-on surfaces (audit-md, evidence packs).
     */
    public function fold(array $clusters): array
    {
        $support = [];
        foreach ($clusters as $c) {
            if (isset($support[$c->id])) {
                // Content-derived ids are unique per run (Clusterer's
                // §5e mint); a collision means two clusters claim one
                // key and the edge map below could fuse them. Fail loud.
                throw new \LogicException("duplicate cluster id in subsumption fold: {$c->id}");
            }
            $support[$c->id] = count($c->members);
        }

        $edges = $this->subsumptionEdges($clusters);

        // The §5c shared suppression rule — the ONLY maximal-family
        // computation this item is allowed (see class docblock).
        $survivors = NestedFamilySuppression::keepMaximal($support, $edges);
        $surviving = array_fill_keys($survivors, true);

        // Attribute each suppressed cluster to a surviving maximal
        // ancestor; sort by id so the pick is deterministic whatever
        // order the witnesses were found in.
        $attributed = []; // parent id => list<child id>
        $subsumed    = [];
        foreach ($clusters as $c) {
            if ($c->members === []) {
                continue; // member-less cluster: nothing to subsume, never subsumed
            }
            if (isset($surviving[$c->id])) {
                continue;
            }
            $parents = [];
            foreach ($edges as $parentId => $children) {
                if (isset($children[$c->id]) && isset($surviving[$parentId])) {
                    $parents[] = $parentId;
                }
            }
            if ($parents === []) {
                // Unreachable by the composition theorem (docblock);
                // a dangle here would silently drop a finding, so die.
                throw new \LogicException("subsumed cluster {$c->id} has no surviving maximal ancestor");
            }
            sort($parents);
            $c->subsumedBy = $parents[0];
            $attributed[$parents[0]][] = $c->id;
            $subsumed[] = $c;
        }
        foreach ($clusters as $c) {
            $list = $attributed[$c->id] ?? [];
            sort($list);
            $c->subsumes = $list;
        }

        $maximal = [];
        foreach ($clusters as $c) {
            if (!isset($surviving[$c->id])) {
                continue;
            }
            $maximal[] = $c;
        }
        return ['maximal' => $maximal, 'subsumed' => $subsumed];
    }

    /**
     * Every injective-containment pair: parentClusterId => childClusterId
     * => matched count (unused by the >= decision; kept for witness
     * explanations, same payload shape as the miner's edge rows).
     *
     * @param list<Cluster> $clusters
     * @return array<string, array<string, int>>
     */
    private function subsumptionEdges(array $clusters): array
    {
        // Candidate pairs: a child needs >=1 member contained in a
        // parent's member, so only clusters sharing a file can pair.
        // Witness both directions here; the map keys orient parent→child
        // only after matching confirms the saturating (injective) one.
        $candidates = []; // "childIdx|parentIdx" => true
        $byFile = [];
        foreach ($clusters as $i => $c) {
            foreach ($c->members as $m) {
                $byFile[$m->file][] = $i;
            }
        }
        foreach ($byFile as $idxs) {
            $idxs = array_values(array_unique($idxs));
            $n = count($idxs);
            for ($x = 0; $x < $n; $x++) {
                for ($y = 0; $y < $n; $y++) {
                    if ($idxs[$x] === $idxs[$y]) {
                        continue;
                    }
                    $child  = $clusters[$idxs[$x]];
                    $parent = $clusters[$idxs[$y]];
                    if (count($child->members) > count($parent->members)) {
                        continue; // injectivity is impossible (theorem)
                    }
                    foreach ($child->members as $cb) {
                        foreach ($parent->members as $pb) {
                            if ($this->strictlyContains($pb, $cb)) {
                                $candidates[$idxs[$x] . '|' . $idxs[$y]] = true;
                                continue 2;
                            }
                        }
                    }
                }
            }
        }

        $edges = [];
        foreach (array_keys($candidates) as $key) {
            [$childIdx, $parentIdx] = array_map('intval', explode('|', $key));
            $child  = $clusters[$childIdx];
            $parent = $clusters[$parentIdx];
            $matched = $this->maxInjectiveMatching($child->members, $parent->members);
            if ($matched !== count($child->members)) {
                continue; // some child member has no distinct outer witness
            }
            $edges[$parent->id][$child->id] = $matched;
        }
        return $edges;
    }

    /**
     * Strict line-range containment within the same file. Equal ranges
     * (the same block seen as two kinds) never contain each other.
     */
    private function strictlyContains(Block $outer, Block $inner): bool
    {
        if ($outer->file !== $inner->file) {
            return false;
        }
        if ($outer === $inner) {
            return false;
        }
        if ($outer->range->start > $inner->range->start || $inner->range->end > $outer->range->end) {
            return false;
        }
        return $outer->range->start < $inner->range->start || $inner->range->end < $outer->range->end;
    }

    /**
     * Kuhn augmenting-path matching: maximum number of child members
     * that can be assigned to pairwise-distinct containing parent
     * members. Sizes are cluster members (small by construction); the
     * O(V*E) bound is irrelevant next to the clustering that made them.
     *
     * @param list<Block> $child
     * @param list<Block> $parent
     */
    private function maxInjectiveMatching(array $child, array $parent): int
    {
        /** @var array<int, int> $matchParent parent index => matched child index */
        $matchParent = [];
        $total = 0;
        foreach ($child as $ci => $cb) {
            $seen = [];
            if ($this->augment($child, $parent, $ci, $matchParent, $seen)) {
                $total++;
            }
            unset($seen);
        }
        return $total;
    }

    /**
     * One DFS over child $ci's unmatched-assignment options, stealing
     * along alternating paths. $seen guards per-augment cycles.
     *
     * @param list<Block> $child
     * @param list<Block> $parent
     * @param array<int, int> $matchParent
     * @param array<int, bool> $seen
     */
    private function augment(array $child, array $parent, int $ci, array &$matchParent, array &$seen): bool
    {
        $cb = $child[$ci];
        foreach ($parent as $pi => $pb) {
            if (isset($seen[$pi]) || !$this->strictlyContains($pb, $cb)) {
                continue;
            }
            $seen[$pi] = true;
            if (!isset($matchParent[$pi]) || $this->augment($child, $parent, $matchParent[$pi], $matchParent, $seen)) {
                $matchParent[$pi] = $ci;
                return true;
            }
        }
        return false;
    }
}