<?php
declare(strict_types=1);

namespace Phpdup\Refactor;

use Phpdup\Census\HelperCopy;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;

/**
 * Item 5.3 — placement advisor: WHERE the extraction should live.
 *
 * SignatureBuilder says what the shared function looks like; the
 * filing-measured gap was that nobody said where it goes. This advisor
 * classifies each finished cluster into a placement shape purely from
 * post-RefactorStage state: Hole kinds + observed value sets + the 5.1
 * repo/scope labels on members. Deliberately NO AST reload (lazy-AST
 * runs keep member ASTs unloaded; holes are the evidence that survived)
 * and NO call-graph probe — the advisor answers host topology
 * (namespace LCA, same-class co-residence, composer host packages) and
 * never pretends to know which call sites dominate.
 *
 * Two modes, one seam. The DEFAULT is the conservative mode above: it
 * is what every run without the 3.2 adoption ledger produces, byte-for
 * byte including the original key set. {@see self::armAdoptionProbe()}
 * arms the full 5.3 depth for the run — ReportStage threads the
 * helper_adoption ledger rows there and nowhere else, unarmed runs arm
 * with null — which unlocks three doc-letter arms:
 *
 *  (a) the canonical-target probe: when the 3.2 ledger shows one small
 *      helper whose mined pattern sits (as inline copies) inside EVERY
 *      member of the cluster, the extraction already has a canonical
 *      implementation — name it (conditional key 'canonical') instead
 *      of inventing a new one. The ledger def site may itself be a
 *      cluster member ('is_member': keep it, drop the clones).
 *  (b) the form the doc letter names: 'trait' when all members share
 *      one namespaced class (f12's 1,133 single-class clusters — the
 *      lift keeps every call site), 'base_class' when sibling classes
 *      repeat one same-named method under a shared namespace root
 *      (pull-up). Both ride the shape slot; the hole evidence stays in
 *      the rationale. 'move' and 'data_table' keep precedence — the
 *      doc's all-literal rule says "table-driven", not a lift.
 *  (c) the fallbacks: directory LCA when the members share no
 *      namespace root (conditional key 'lca_directory'), and the
 *      'create a shared package' guidance when the host-package
 *      intersection is computed and empty (rationale sentence; the
 *      empty list itself keeps saying so).
 *
 * Shapes (priority order, first match wins; '*' marks armed-only forms):
 *   move                  no holes          — byte-equivalent clones; relocate verbatim
 *   data_table            all literal holes — differs only by data; lookup + one driver
 *   trait*                one class         — members co-reside; a trait lift keeps call sites
 *   base_class*           sibling pull-up   — same-named methods across classes under one root
 *   expression_function   any subtree hole  — differing code regions, expression params
 *   defaulted_function    any optional_block — near-subset; defaulted bool params
 *   parameterized_function otherwise        — identifier/name slots; ordinary parameters
 *
 * host_packages is honest-or-nothing: it is computed only when members
 * span two or more scan roots, by intersecting require ∪ require-dev
 * package names from each root's composer.json (capped read). Anything
 * less (single root, unreadable manifest, malformed JSON) yields null
 * with the reason in the rationale — never a guessed dependency.
 *
 * output-046: those keys arrive from the UNTRUSTED tree's composer.json,
 * so they carry composer's own package-name charset — nothing outside it
 * reaches the advice, let alone a report sink. A planted
 * `vendor/na<newline>me` or ESC-bearing key is dropped as unnameable.
 */
final class PlacementAdvisor
{
    /**
     * The 3.2 helper-adoption rows armed for the current run; null =
     * conservative mode. Written ONLY by {@see self::armAdoptionProbe()}
     * (ReportStage re-arms on every pipeline run, unarmed included), so
     * a long-lived process never carries a stale ledger across runs.
     *
     * @var list<HelperCopy>|null
     */
    private static ?array $adoptionRows = null;

    /** @var int Bytes to read per composer.json before refusing to parse. */
    private const MANIFEST_MAX_BYTES = 64_000;

    /**
     * Composer package-name charset (output-046): vendor/name over the
     * bytes composer itself admits. Anything else (control bytes, spaces,
     * tabs — tab is legal in filenames but not in package names) is not a
     * package name and never enters the advice.
     */
    private const PACKAGE_NAME_PATTERN = '#^[A-Za-z0-9._~/-]+$#';

    /** @var array<string, list<string>|false> root => package list, false = unreadable */
    private array $manifestCache = [];

    /** @param list<string> $roots the run's scan paths (one or more directories or single PHP files) as given. */
    public function __construct(private readonly array $roots = [])
    {
    }

    /**
     * Arm (or, with null, disarm) the full 5.3 depth for the run.
     *
     * The reporters construct advisors inside their payload builders, so
     * the ledger crosses as this documented per-run arm rather than a
     * constructor argument. ReportStage::run() is the ONLY production
     * writer and it calls this on every pipeline run — unarmed runs
     * pass null, which restores the byte-frozen conservative payloads
     * (goldens, CI smoke, library routes). A test that arms must
     * disarm in tearDown: the static is process-wide by design.
     *
     * @param list<HelperCopy>|null $rows the 3.2 adoption ledger rows, or null
     */
    public static function armAdoptionProbe(?array $rows): void
    {
        self::$adoptionRows = $rows;
    }

    /**
     * @return array{shape:string, rationale:string, same_host:bool, lca_namespace:string, host_packages:list<string>|null, canonical?:array{label:string,file:string,line:int,inline_copies:int,is_member:bool}, lca_directory?:string}
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

        $armed    = self::$adoptionRows !== null;
        $sameHost = $this->sameHost($cluster);
        $lca      = $this->lcaNamespace($cluster);
        // Armed-only first-class forms (doc arm b); '' keeps the
        // function-family shapes byte-for-byte as before.
        $form = $armed ? $this->hostForm($cluster, $sameHost, $lca) : '';

        [$shape, $why] = $this->classify($kinds, $distinctCells, $cluster, $form);
        $canonical    = $armed ? $this->canonicalTarget($cluster) : null;
        $lcaDirectory = $armed && $lca === '' ? $this->lcaDirectory($cluster) : '';
        [$packages, $packageNote] = $this->hostPackages($cluster, $armed);

        $rationale = $why;
        if ($canonical !== null) {
            $rationale .= $canonical['is_member']
                ? " Canonical target: {$canonical['label']} ({$canonical['file']}:{$canonical['line']}) is itself one of the members — keep it, replace the clones with calls to it."
                : " Canonical target: {$canonical['label']} ({$canonical['file']}:{$canonical['line']}) already implements this body ({$canonical['inline_copies']} inline copies in the 3.2 ledger) — call it instead of extracting a new one.";
        }
        if ($sameHost && $shape !== 'move' && $shape !== 'trait') {
            $rationale .= '; members already share one class, so a base-class or trait lift keeps call sites in place.';
        }
        if ($lca !== '' && $shape !== 'move') {
            $rationale .= " Shared namespace root: {$lca}.";
        }
        if ($lcaDirectory !== '') {
            $rationale .= " Members share no namespace root; lowest common directory: {$lcaDirectory}.";
        }
        if ($gapped) {
            $rationale .= '; some member evidence was a structural gap (see holes[].degraded) — verify the odd member before extracting.';
        }
        if ($packageNote !== '') {
            $rationale .= " {$packageNote}";
        }

        $advice = [
            'shape' => $shape,
            'rationale' => $rationale,
            'same_host' => $sameHost,
            'lca_namespace' => $lca,
            'host_packages' => $packages,
        ];
        // Conditional trailing keys (MINE-D §4 posture): present only
        // when armed AND the evidence names a target — default payloads
        // keep their byte-frozen five-key placement shape.
        if ($canonical !== null) {
            $advice['canonical'] = $canonical;
        }
        if ($lcaDirectory !== '') {
            $advice['lca_directory'] = $lcaDirectory;
        }
        return $advice;
    }

    /**
     * The doc-letter FORM for an armed run: a member-lift topology the
     * hole-kind taxonomy cannot see. '' = no first-class lift applies,
     * keep the function family.
     */
    private function hostForm(Cluster $cluster, bool $sameHost, string $lca): string
    {
        if ($sameHost) {
            return 'trait';
        }
        $classes = [];
        $names   = [];
        foreach ($cluster->members as $m) {
            if ($m->kind !== 'method' || $m->name === null || $m->name === '') {
                return '';
            }
            $names[$m->name] = true;
            $classes[($m->namespace ?? '') . '\\' . ($m->class ?? '')] = true;
        }
        // Pull-up evidence: sibling classes repeating ONE same-named
        // method under a shared namespace root — the base lands there.
        if (count($classes) < 2 || count($names) !== 1 || $lca === '') {
            return '';
        }
        return 'base_class';
    }

    /**
     * @param array<string,true> $kinds
     * @param string $form '' | 'trait' | 'base_class' (armed host topology)
     * @return array{0:string,1:string}
     */
    private function classify(array $kinds, int $distinctCells, Cluster $cluster, string $form = ''): array
    {
        $holeCount = count($cluster->holes);
        if ($holeCount === 0) {
            return ['move', 'No holes: the members are text-equivalent after normalization, so this is a verbatim relocation, not a rewrite.'];
        }
        if (isset($kinds['subtree'])) {
            $sub    = $this->countKind($cluster, 'subtree');
            $carrier = $form === 'trait' ? 'trait' : ($form === 'base_class' ? 'base class' : 'function');
            return [$form !== '' ? $form : 'expression_function', "{$sub} of {$holeCount} holes replace whole subtrees — extract as a {$carrier} taking the differing regions as expression-typed parameters."];
        }
        if (count($kinds) === 1 && isset($kinds['literal'])) {
            // The doc's all-literal rule outranks any member-lift form:
            // "emit the observed values as a data table and say table-driven".
            return ['data_table', "Every hole is a literal ({$distinctCells} distinct observed values) — the pair differs by data, not control flow; a lookup table plus one driver function replaces the cluster."];
        }
        if (isset($kinds['optional_block'])) {
            $opt     = $this->countKind($cluster, 'optional_block');
            $carrier = $form === 'trait' ? 'trait' : ($form === 'base_class' ? 'base class' : 'function');
            return [$form !== '' ? $form : 'defaulted_function', "{$opt} optional segment(s) differ in presence, not content — one {$carrier} with defaulted parameters covers the family."];
        }
        $carrier = $form === 'trait' ? 'trait' : ($form === 'base_class' ? 'base class' : 'function');
        $why = $form === ''
            ? "{$holeCount} hole(s) over identifiers/names — an ordinary parameterized extraction."
            : "{$holeCount} hole(s) over identifiers/names — an ordinary parameterized {$carrier} lift.";
        return [$form !== '' ? $form : 'parameterized_function', $why];
    }

    /**
     * Doc arm (a): the existing canonical implementation behind this
     * cluster, derived purely from the 3.2 adoption rows. A row names a
     * candidate only when it accounts for EVERY member — either the row's
     * helper definition sits inside that member, or the member's file
     * carries recorded inline copies of the helper's pattern (and, when
     * the ledger's copy samples are complete, a sample line falls inside
     * the member's own range). Strongest evidence wins: most inline
     * copies, then label/file/line ascending (deterministic).
     *
     * @return array{label:string,file:string,line:int,inline_copies:int,is_member:bool}|null
     */
    private function canonicalTarget(Cluster $cluster): ?array
    {
        if ($cluster->members === []) {
            return null;
        }
        $best    = null;
        $bestKey = null;
        foreach (self::$adoptionRows ?? [] as $row) {
            if ($row->inlineSites < 1) {
                continue;
            }
            $covered = $this->rowCoversCluster($cluster, $row);
            if ($covered === null) {
                continue;
            }
            $key = [-$row->inlineSites, $row->helperLabel, $row->defFile, $row->defStart];
            if ($bestKey === null || $key < $bestKey) {
                $bestKey = $key;
                $best    = [
                    'label'         => $row->helperLabel,
                    'file'          => $row->defFile,
                    'line'          => $row->defStart,
                    'inline_copies' => $row->inlineSites,
                    'is_member'     => $covered,
                ];
            }
        }
        return $best;
    }

    /**
     * @return bool|null true when the helper definition itself is one of
     *                   the members, false when all members are external
     *                   copies, null when the row does not account for
     *                   the whole cluster (stay conservative — no name).
     */
    private function rowCoversCluster(Cluster $cluster, HelperCopy $row): ?bool
    {
        $defIsMember      = false;
        $samplesComplete  = count($row->copyLocations) >= $row->inlineSites;
        foreach ($cluster->members as $m) {
            if ($m->file === $row->defFile && $m->range->start <= $row->defStart && $m->range->end >= $row->defEnd) {
                $defIsMember = true;
                continue;
            }
            if (($row->inlineFiles[$m->file] ?? 0) < 1) {
                return null;
            }
            // With the full sample set the join is line-exact; a capped
            // sample may omit this member's site, so file-level
            // attribution is the honest ceiling there.
            if ($samplesComplete && !$this->hasCopyInside($m, $row)) {
                return null;
            }
        }
        return $defIsMember;
    }

    private function hasCopyInside(Block $member, HelperCopy $row): bool
    {
        foreach ($row->copyLocations as $location) {
            $sep = strrpos($location, ':');
            if ($sep === false) {
                continue;
            }
            if (substr($location, 0, $sep) !== $member->file) {
                continue;
            }
            $line = (int) substr($location, $sep + 1);
            if ($line >= $member->range->start && $line <= $member->range->end) {
                return true;
            }
        }
        return false;
    }

    /**
     * Doc arm (b) fallback: the lowest common directory of the member
     * files, used when the namespaces share no root ('' = nothing to
     * say: divergent roots or no members).
     */
    private function lcaDirectory(Cluster $cluster): string
    {
        $dirs = [];
        foreach ($cluster->members as $m) {
            $dirs[] = explode('/', str_replace('\\', '/', dirname($m->file)));
        }
        if ($dirs === []) {
            return '';
        }
        $first  = array_shift($dirs);
        $common = [];
        foreach ($first as $i => $seg) {
            foreach ($dirs as $rest) {
                if (($rest[$i] ?? null) !== $seg) {
                    return implode('/', $common);
                }
            }
            $common[] = $seg;
        }
        return implode('/', $common);
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
    private function hostPackages(Cluster $cluster, bool $armed = false): array
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
                return [null, "Cannot name a shared host package: {$root} has no parseable composer.json (conservative mode, item 5.3)."];
            }
            $sets[] = $pkgs;
        }
        $inter = array_shift($sets);
        foreach ($sets as $s) {
            $inter = array_values(array_intersect($inter, $s));
        }
        sort($inter);
        // Doc arm (c) fallback: the empty intersection used to be silent
        // ('host_packages': [] and nothing else). Armed runs say what
        // the doc letter says — create the shared package.
        $note = $armed && $inter === []
            ? 'No package is required by every host root — create a shared package (both sides require it) and place the extraction there (item 5.3 fallback).'
            : '';
        return [$inter, $note];
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
            static fn($p) => is_string($p)
                && $p !== ''
                && $p !== 'php'
                && !str_starts_with($p, 'ext-')
                && preg_match(self::PACKAGE_NAME_PATTERN, $p) === 1,
        )));
        $this->manifestCache[$root] = $pkgs;
        return $pkgs;
    }

    /**
     * Match a member path to the configured root it was scanned under.
     * Directory roots win by path prefix; a single-FILE root is legal
     * input (FileScanner::scan() yields the given root verbatim for it),
     * so an exact string match — modulo the trailing slash the scanner
     * itself trims — also resolves. Without that arm, `analyze foo.php`
     * made every member of its own root look like a stray and the advice
     * claimed it "does not resolve under any configured scan root".
     */
    private function rootFor(string $file): ?string
    {
        foreach ($this->roots as $root) {
            $base = rtrim($root, '/');
            if ($file === $base || str_starts_with($file, $base . '/')) {
                return $root;
            }
        }
        return null;
    }
}
