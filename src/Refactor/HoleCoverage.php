<?php
declare(strict_types=1);

namespace Phpdup\Refactor;

use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use PhpParser\Node;

/**
 * How much of a cluster's generalized template is hole.
 *
 * Item 5.4 replaces the plan's old ">40% holes → drop" heuristic with an
 * honest measurement plus a knob: {@see \Phpdup\Cli\Config::$maxHoleCoverage}
 * names the coverage fraction at which a cluster is still worth a full
 * signature, and beyond it reporters mark the cluster `re_expressed` with
 * the longest hole-free statement run as its `hole_fragment`. Nothing is
 * ever dropped — the evidence is surfaced degraded, never silently deleted.
 *
 * Coverage counts AST units (nodes, plus non-node array slots, plus scalar
 * leaves at observed positions) inside hole subtrees over all units of the
 * template. Hole paths come from {@see Cluster::$holePaths}, which are
 * template-relative, so this needs no AST reload beyond what the cluster
 * already carries (null AST → null coverage, e.g. residue-only clusters).
 */
final class HoleCoverage
{
    /**
     * Fraction (0..1) of the template's AST units covered by hole subtrees,
     * or null when the cluster carries no generalized AST.
     */
    public static function coverage(Cluster $cluster): ?float
    {
        $template = $cluster->generalizedAst;
        if (!$template instanceof Node) {
            return null;
        }
        $holes    = self::holeKeys($cluster);
        [$total, $covered] = self::tally($template, [], $holes);
        return $total > 0 ? $covered / $total : 0.0;
    }

    /**
     * Top-level statements of the template body (whenever the root node
     * carries a `stmts` list). Read via get_object_vars so the dynamic
     * php-parser property stays honest to static analysis.
     *
     * @return list<mixed>
     */
    public static function rootStmts(Cluster $cluster): array
    {
        $template = $cluster->generalizedAst;
        if (!$template instanceof Node) {
            return [];
        }
        $stmts = get_object_vars($template)['stmts'] ?? null;
        return is_array($stmts) ? array_values($stmts) : [];
    }

    /**
     * Longest hole-free run among the template's top-level body statements.
     *
     * @return null|array{start:int,length:int} null when the template root
     *         has no `stmts` body list, or every top-level statement is
     *         touched by a hole.
     */
    public static function fragmentSpan(Cluster $cluster): ?array
    {
        if (!$cluster->generalizedAst instanceof Node) {
            return null;
        }
        $stmts = self::rootStmts($cluster);
        if ($stmts === []) {
            return null;
        }
        $holes  = self::holeKeys($cluster);
        $start  = 0;
        $length = 0;
        $run    = 0;
        $best   = 0;
        foreach (array_keys($stmts) as $idx) {
            $coveredHere = false;
            $prefix      = 'stmts/' . $idx;
            foreach ($holes as $key => $_) {
                if ($key === $prefix || str_starts_with((string)$key, $prefix . '/')) {
                    $coveredHere = true;
                    break;
                }
            }
            if ($coveredHere || isset($holes[''])) {
                $run = 0;
                $start = (int)$idx + 1;
            } else {
                $run++;
                if ($run > $length) {
                    $length = $run;
                    $best   = $start;
                }
            }
        }
        return $length > 0 ? ['start' => $best, 'length' => $length] : null;
    }

    /**
     * @return array<string, true> hole-path keys ("stmts/2/expr/var/name" style)
     */
    public static function holeKeys(Cluster $cluster): array
    {
        $keys = [];
        foreach ($cluster->holePaths as $path) {
            $keys[implode('/', array_map('strval', $path))] = true;
        }
        return $keys;
    }

    /**
     * The cluster's canonical template member (item 5.4a): the medoid the
     * greedy-ball split centered on when one was recorded, else the
     * historical members[0]. This is the cluster's REPRESENTATIVE — not
     * necessarily the exact AST root AntiUnifier generalized from (its
     * pickBestSeed optimizes hole count and may land on a different
     * member); seed identity is intentionally not persisted (no Cluster
     * field). Every template consumer — diff anchor, JSON evidence keys —
     * resolves through here so the report surfaces cannot disagree about
     * what stands for the cluster.
     */
    public static function canonicalTemplate(Cluster $cluster): ?Block
    {
        $members = $cluster->members;
        if ($members === []) {
            return null;
        }
        if ($cluster->medoidId !== null) {
            foreach ($members as $m) {
                if ($m->id === $cluster->medoidId) {
                    return $m;
                }
            }
        }
        return $members[0];
    }

    /**
     * output-S26 (php-dup-finder-output-018): does any hole in this
     * cluster carry degraded evidence — a row that is a structural gap
     * or an unverifiable presence claim, rather than an observation?
     *
     * Apply-side consumers gate on this before trusting a hole's value
     * set (the S45 apply-args / S51 refusal chain): with the pad
     * invariant (every hole has one row per member) the desync lies can
     * no longer hide as SHORT lists — they surface here as flagged
     * placeholder rows.
     */
    public static function isDegraded(Cluster $cluster): bool
    {
        foreach ($cluster->holes as $hole) {
            if ($hole->isDegraded()) {
                return true;
            }
        }
        return false;
    }

    /**
     * The cluster's degraded holes, in cluster hole order.
     *
     * @return list<Hole>
     */
    public static function degradedHoles(Cluster $cluster): array
    {
        $out = [];
        foreach ($cluster->holes as $hole) {
            if ($hole->isDegraded()) {
                $out[] = $hole;
            }
        }
        return $out;
    }

    /**
     * @param list<int|string>    $path
     * @param array<string, true> $holes
     * @return array{int,int} [total units, units inside holes]
     */
    private static function tally(mixed $value, array $path, array $holes): array
    {
        $key = self::pathKey($path);
        if (isset($holes[$key])) {
            $units = self::units($value);
            return [$units, $units];
        }
        if ($value instanceof Node) {
            $total   = 1;
            $covered = 0;
            foreach ($value->getSubNodeNames() as $sub) {
                [$t, $c]      = self::tally($value->$sub, [...$path, $sub], $holes);
                $total       += $t;
                $covered     += $c;
            }
            return [$total, $covered];
        }
        if (is_array($value)) {
            $total   = 0;
            $covered = 0;
            foreach ($value as $idx => $item) {
                [$t, $c]  = self::tally($item, [...$path, $idx], $holes);
                $total   += $t;
                $covered += $c;
            }
            return [$total, $covered];
        }
        if ($value === null) {
            return [0, 0];
        }
        return [1, 0];
    }

    /** Count of AST units at an arbitrary slot (node subtree, list, scalar). */
    private static function units(mixed $value): int
    {
        if ($value instanceof Node) {
            $total = 1;
            foreach ($value->getSubNodeNames() as $sub) {
                $total += self::units($value->$sub);
            }
            return $total;
        }
        if (is_array($value)) {
            $total = 0;
            foreach ($value as $item) {
                $total += self::units($item);
            }
            return $total;
        }
        return $value === null ? 0 : 1;
    }

    /** @param list<int|string> $path */
    private static function pathKey(array $path): string
    {
        return implode('/', array_map('strval', $path));
    }
}
