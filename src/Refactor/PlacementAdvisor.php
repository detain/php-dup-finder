<?php
declare(strict_types=1);

namespace Phpdup\Refactor;

use Phpdup\Clustering\Cluster;
use Phpdup\Cli\Config;

/**
 * Item 5.3 — placement advisor: WHERE the extraction should live.
 *
 * SignatureBuilder says what the shared function looks like; the
 * filing-measured gap was that nobody said where it goes. This advisor
 * classifies each finished cluster into a placement shape purely from
 * post-RefactorStage state: Hole kinds + observed value sets + the 5.1
 * repo/scope labels on members. Deliberately NO AST reload (lazy-AST
 * runs keep member ASTs unloaded; holes are the evidence that survived)
 * and NO call-graph probe — the 3.2 canonical-usage probe this was
 * drafted against does not exist in the tree, so the advisor runs in a
 * documented conservative mode: it answers host topology (namespace
 * LCA, same-class co-residence, composer host packages) and never
 * pretends to know which call sites dominate.
 *
 * Shapes (priority order, first match wins):
 *   move                  no holes          — byte-equivalent clones; relocate verbatim
 *   expression_function   any subtree hole  — differing code regions, expression params
 *   data_table            all literal holes — differs only by data; lookup + one driver
 *   defaulted_function    any optional_block — near-subset; defaulted bool params
 *   parameterized_function otherwise        — identifier/name slots; ordinary parameters
 *
 * host_packages is honest-or-nothing: it is computed only when members
 * span two or more scan roots, by intersecting require ∪ require-dev
 * package names from each root's composer.json (capped read). Anything
 * less (single root, unreadable manifest, malformed JSON) yields null
 * with the reason in the rationale — never a guessed dependency.
 */
final class PlacementAdvisor
{
    /** @var int Bytes to read per composer.json before refusing to parse. */
    private const MANIFEST_MAX_BYTES = 64_000;

    /** @var array<string, list<string>|false> root => package list, false = unreadable */
    private array $manifestCache = [];

    /** @param list<string> $roots Config::paths as given to the run. */
    public function __construct(private readonly array $roots = [])
    {
    }

    /**
     * @return array{shape:string, rationale:string, same_host:bool, lca_namespace:string, host_packages:list<string>|null}
     */
    public function advise(Cluster $cluster): array
    {
        $kinds = [];
        $distinctCells = 0;
        $gapped = false;
        foreach ($cluster->holes as $h) {
            $kinds[$h->kind] = true;
            $distinctCells += $h->uniqueValueCount();
            $gapped = $gapped || $h->degraded;
        }

        [$shape, $why] = $this->classify($kinds, $distinctCells, $cluster);
        $sameHost = $this->sameHost($cluster);
        $lca      = $this->lcaNamespace($cluster);
        [$packages, $packageNote] = $this->hostPackages($cluster);

        $rationale = $why;
        if ($sameHost && $shape !== 'move') {
            $rationale .= '; members already share one class, so a base-class or trait lift keeps call sites in place.';
        }
        if ($lca !== '' && $shape !== 'move') {
            $rationale .= " Shared namespace root: {$lca}.";
        }
        if ($gapped) {
            $rationale .= '; some member evidence was a structural gap (see holes[].degraded) — verify the odd member before extracting.';
        }
        if ($packageNote !== '') {
            $rationale .= " {$packageNote}";
        }

        return [
            'shape' => $shape,
            'rationale' => $rationale,
            'same_host' => $sameHost,
            'lca_namespace' => $lca,
            'host_packages' => $packages,
        ];
    }

    /**
     * @param array<string,true> $kinds
     * @return array{0:string,1:string}
     */
    private function classify(array $kinds, int $distinctCells, Cluster $cluster): array
    {
        $holeCount = count($cluster->holes);
        if ($holeCount === 0) {
            return ['move', 'No holes: the members are text-equivalent after normalization, so this is a verbatim relocation, not a rewrite.'];
        }
        if (isset($kinds['subtree'])) {
            $sub = $this->countKind($cluster, 'subtree');
            return ['expression_function', "{$sub} of {$holeCount} holes replace whole subtrees — extract as a function taking the differing regions as expression-typed parameters."];
        }
        if (count($kinds) === 1 && isset($kinds['literal'])) {
            return ['data_table', "Every hole is a literal ({$distinctCells} distinct observed values) — the pair differs by data, not control flow; a lookup table plus one driver function replaces the cluster."];
        }
        if (isset($kinds['optional_block'])) {
            $opt = $this->countKind($cluster, 'optional_block');
            return ['defaulted_function', "{$opt} optional segment(s) differ in presence, not content — one function with defaulted parameters covers the family."];
        }
        return ['parameterized_function', "{$holeCount} hole(s) over identifiers/names — an ordinary parameterized extraction."];
    }

    private function countKind(Cluster $cluster, string $kind): int
    {
        $n = 0;
        foreach ($cluster->holes as $h) {
            if ($h->kind === $kind) {
                $n++;
            }
        }
        return $n;
    }

    /** Do all members live in the same class of the same namespace? */
    private function sameHost(Cluster $cluster): bool
    {
        $seen = [];
        foreach ($cluster->members as $m) {
            if ($m->class === null || $m->class === '' || $m->namespace === null || $m->namespace === '') {
                return false;
            }
            $seen[$m->namespace . '\\' . $m->class] = true;
        }
        return count($seen) === 1;
    }

    /** Longest common namespace prefix over member namespaces ('' = none). */
    private function lcaNamespace(Cluster $cluster): string
    {
        $lists = [];
        foreach ($cluster->members as $m) {
            // Null namespace (top-level code) is global scope: no common root.
            if ($m->namespace === null || $m->namespace === '') {
                return '';
            }
            $lists[] = explode('\\', $m->namespace);
        }
        if ($lists === []) {
            return '';
        }
        $first = array_shift($lists);
        $lca = [];
        foreach ($first as $i => $seg) {
            foreach ($lists as $rest) {
                if (($rest[$i] ?? null) !== $seg) {
                    return implode('\\', $lca);
                }
            }
            $lca[] = $seg;
        }
        return implode('\\', $lca);
    }

    /**
     * @return array{0:list<string>|null,1:string}
     */
    private function hostPackages(Cluster $cluster): array
    {
        // Library callers construct reports without scan roots: host
        // topology is simply not configured, so the advisor says nothing
        // instead of blaming members for not resolving.
        if ($this->roots === []) {
            return [null, ''];
        }
        $roots = [];
        foreach ($cluster->members as $m) {
            $root = $this->rootFor($m->file);
            if ($root === null) {
                return [null, 'Host-package advice is unavailable: a member does not resolve under any configured scan root.'];
            }
            $roots[$root] = true;
        }
        $rootList = array_keys($roots);
        if (count($rootList) < 2) {
            return [null, '']; // single-repo cluster: there is no intersection to compute — nothing to say.
        }
        $sets = [];
        foreach ($rootList as $root) {
            $pkgs = $this->packagesAt($root);
            if ($pkgs === null) {
                return [null, "Host-package advice degrades to null: {$root} has no parseable composer.json (conservative mode, item 5.3)."];
            }
            $sets[] = $pkgs;
        }
        $inter = array_shift($sets);
        foreach ($sets as $s) {
            $inter = array_values(array_intersect($inter, $s));
        }
        sort($inter);
        return [$inter, ''];
    }

    /** @return list<string>|null */
    private function packagesAt(string $root): ?array
    {
        if (array_key_exists($root, $this->manifestCache)) {
            return $this->manifestCache[$root] === false ? null : $this->manifestCache[$root];
        }
        $file = $root . '/composer.json';
        $raw = is_readable($file) ? (string)file_get_contents($file, false, null, 0, self::MANIFEST_MAX_BYTES + 1) : '';
        if ($raw === '' || strlen($raw) > self::MANIFEST_MAX_BYTES) {
            $this->manifestCache[$root] = false;
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->manifestCache[$root] = false;
            return null;
        }
        $pkgs = array_merge(
            array_keys((array)($decoded['require'] ?? [])),
            array_keys((array)($decoded['require-dev'] ?? [])),
        );
        $pkgs = array_values(array_unique(array_filter(
            $pkgs,
            static fn($p) => is_string($p) && $p !== '' && $p !== 'php' && !str_starts_with($p, 'ext-'),
        )));
        $this->manifestCache[$root] = $pkgs;
        return $pkgs;
    }

    private function rootFor(string $file): ?string
    {
        foreach ($this->roots as $root) {
            if (str_starts_with($file, rtrim($root, '/') . '/')) {
                return $root;
            }
        }
        return null;
    }
}
