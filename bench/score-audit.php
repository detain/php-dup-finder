<?php

declare(strict_types=1);

/**
 * bench/score-audit.php — mechanical audit scorer: audit-truth payload ×
 * phpdup JSON report -> ranged-evidence recall / file-graze relevance /
 * top-N relevance@k, with per-detection provenance.
 *
 * Matching law (f15 s5 + once-106 cure): clusters and truth findings are
 * linked ONLY by member overlap — cluster ids are display-only, never match
 * keys. The tolerance core mirrors src/Testing/BenchmarkScoreCalculator:
 * two members match when they share the file AND both endpoints are within
 * ±LINE_TOLERANCE lines (NOT the retired floor(line/tol) bucketing — the
 * bucket formula forgave 1-line parity errors while rejecting genuine ±2
 * shifts). The src matcher is private (maxToleranceMatching); this file
 * re-derives the same predicate + Kuhn one-to-one max-matching locally.
 * If the src formula moves, this file must move with it (seam request:
 * expose a public matcher — follow-on row, reviews/exec/once-S85.impl.md).
 *
 * Metric honesty (once-106 / PD-BENCH-05, PD-BENCH-06):
 *  - RECALL is ranged-only: a finding counts toward the recall obligation
 *    only when it has >=1 ADMITTED ranged member. An admitted member is one
 *    with a start line whose `verified` flag is not false — verified:false
 *    tokens are truth-side path errors (file absent at the scan pin) and are
 *    EXCLUDED from denominators, reported separately as a truth gap.
 *  - Rangeless citations (file-claim cites, `start:null`) no longer produce
 *    detections. A cluster merely touching a cited file is a FILE-LEVEL
 *    GRAZE, reported in its own honestly-labelled population — never blended
 *    into recall.
 *  - The old headline "precision" measured "no cluster failed to touch some
 *    cited filename" — that is a relevance/touch rate, not precision. It is
 *    renamed to `file-touch relevance`; the precision-shaped number is
 *    `ranged-evidence relevance` (share of in-window clusters carrying at
 *    least one line-evidence link).
 *  - LLM-pass-origin members (origin "llm", per bench/audit-truth/LLM-PASS.md
 *    stage-2 rules) are marked: per-detection provenance records when a
 *    detection's matched members are LLM-origin, and they carry the same
 *    verified-gate before they can create a recall obligation.
 *
 * Link rule: cluster c detects ranged truth finding f when the maximum
 * bipartite (one-to-one) tolerance matching between c's ranged members and
 * f's ADMITTED ranged members reaches k(f):
 *   k = ceil(|admitted ranged unique| / 2) when that count is >= 6,
 *       else max(1, min(2, count)) — clamped so 1-member cites stay
 *       detectable. Big-family k honestly exposes pair-grained
 *       fragmentation (a 27-file family needs ~14 members in one cluster)
 *       — that is a measured gap, not a scorer bug.
 *
 * Schema tolerance (owner ruling 6 item 5 + f15 s5): report schema_version
 * "N" must carry major == JsonSchemaSpec::SCHEMA_VERSION's major (hard
 * error), differing minor only warns and continues (N/N-1 bump survival).
 *
 * PRIVACY: consumes derived truth JSON from /tmp paths; writes only to
 * --out (default stdout). Nothing here is ever committed under repo truth
 * paths (audit-truth privacy gate).
 *
 * Usage:
 *   php bench/score-audit.php --truth=/tmp/.../my.truth.json \
 *       --report=/tmp/.../run.json [--prefix=include/Api/Vps,include/Api/Quickservers] \
 *       [--root=/tmp/.../tree] [--top=50] [--tolerance=2] [--out=/tmp/.../score.md]
 * Exit: 0 scored, 2 input/schema-major error.
 */

/** Read the mirrored ±line tolerance from src when autoloadable. */
function auditToleranceDefault(): int
{
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
        if (defined('Phpdup\\Testing\\BenchmarkScoreCalculator::LINE_TOLERANCE')) {
            return (int) constant('Phpdup\\Testing\\BenchmarkScoreCalculator::LINE_TOLERANCE');
        }
    }
    return 2;
}

/* ===================== tolerance matcher (mirrored, re-derived) =====================
 * Same predicates as Phpdup\Testing\BenchmarkScoreCalculator::withinTolerance()
 * and ::maxToleranceMatching() (both private there — S09 handoff §1 directs this
 * re-derivation instead of a src seam). */

/**
 * @param array{file:string,start:int,end:int} $x
 * @param array{file:string,start:int,end:int} $y
 */
function auditWithinTolerance(array $x, array $y, int $tol): bool
{
    return $x['file'] === $y['file']
        && abs($x['start'] - $y['start']) <= $tol
        && abs($x['end'] - $y['end']) <= $tol;
}

/**
 * Maximum bipartite matching (Kuhn augmenting paths) under the tolerance
 * predicate. Deterministic: fixed left/right order, first-found augmenting
 * path. Mirrors src ::maxToleranceMatching.
 *
 * @param list<array{file:string,start:int,end:int}> $left
 * @param list<array{file:string,start:int,end:int}> $right
 */
function auditMaxToleranceMatching(array $left, array $right, int $tol): int
{
    $owner = array_fill(0, count($right), null);
    $matches = 0;
    foreach (array_keys($left) as $li) {
        $seen = [];
        if (auditAugment($li, $left, $right, $owner, $seen, $tol)) {
            $matches++;
        }
    }
    return $matches;
}

/**
 * @param list<array{file:string,start:int,end:int}> $left
 * @param list<array{file:string,start:int,end:int}> $right
 * @param array<int, int|null> $owner
 * @param array<int, bool> $seen
 */
function auditAugment(int $li, array $left, array $right, array &$owner, array &$seen, int $tol): bool
{
    foreach (array_keys($right) as $ri) {
        if (isset($seen[$ri])) {
            continue;
        }
        if (!auditWithinTolerance($left[$li], $right[$ri], $tol)) {
            continue;
        }
        $seen[$ri] = true;
        if ($owner[$ri] === null || auditAugment($owner[$ri], $left, $right, $owner, $seen, $tol)) {
            $owner[$ri] = $li;
            return true;
        }
    }
    return false;
}

/**
 * Unique ranged members keyed by exact (file,start,end) triple — mirrors src
 * ::uniqueMembers/::exactKey (no quantization in the key; the tolerance lives
 * only in the predicate above).
 *
 * @param array<int, array<string, mixed>> $members
 * @return list<array{file:string,start:int,end:int}>
 */
function auditUniqueRanged(array $members): array
{
    $seen = [];
    $out = [];
    foreach ($members as $m) {
        $file = (string) ($m['file'] ?? '');
        if ($file === '' || !isset($m['start']) || !is_numeric($m['start'])) {
            continue;
        }
        $start = (int) $m['start'];
        $end = isset($m['end']) && is_numeric($m['end']) ? (int) $m['end'] : $start;
        $key = $file . ':' . $start . '-' . $end;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = ['file' => $file, 'start' => $start, 'end' => $end];
    }
    return $out;
}

/* ===================== inputs ===================== */

$opts = getopt('h', ['truth:', 'report:', 'prefix:', 'root:', 'top:', 'tolerance:', 'out::']);
if ($opts === false || isset($opts['h'])
    || !isset($opts['truth']) || !is_string($opts['truth'])
    || !isset($opts['report']) || !is_string($opts['report'])
) {
    fwrite(STDERR, "usage: php bench/score-audit.php --truth=FILE --report=FILE [--prefix=A,B] [--root=DIR] [--top=50] [--tolerance=2] [--out=FILE]\n");
    exit(2);
}

$truthRaw = file_get_contents($opts['truth']);
$reportRaw = file_get_contents($opts['report']);
if ($truthRaw === false || $reportRaw === false) {
    fwrite(STDERR, "error: cannot read inputs\n");
    exit(2);
}
$truth = json_decode($truthRaw, true);
$report = json_decode($reportRaw, true);
if (!is_array($truth) || ($truth['schema'] ?? '') !== 'audit-truth/1' || !isset($truth['findings'][0])) {
    fwrite(STDERR, "error: truth payload is not an audit-truth/1 document\n");
    exit(2);
}
if (!is_array($report) || !isset($report['clusters']) || !is_array($report['clusters'])) {
    fwrite(STDERR, "error: report payload has no clusters[]\n");
    exit(2);
}

$autoload = __DIR__ . '/../vendor/autoload.php';
$schemaTarget = '1.1';
$schemaSource = 'embedded-fallback';
if (is_file($autoload)) {
    require_once $autoload;
    if (defined('Phpdup\\Reporting\\JsonSchemaSpec::SCHEMA_VERSION')) {
        $schemaTarget = (string) constant('Phpdup\\Reporting\\JsonSchemaSpec::SCHEMA_VERSION');
        $schemaSource = 'JsonSchemaSpec';
    }
}
$reportSchema = (string) ($report['schema_version'] ?? '');
$rmaj = explode('.', $reportSchema)[0];
$tmaj = explode('.', $schemaTarget)[0];
if ($rmaj !== $tmaj) {
    fwrite(STDERR, "error: report schema major '{$reportSchema}' incompatible with target '{$schemaTarget}' (from {$schemaSource})\n");
    exit(2);
}
$schemaWarn = '';
if ($reportSchema !== $schemaTarget) {
    $schemaWarn = "warning: report schema_version {$reportSchema} != target {$schemaTarget} (from {$schemaSource}) — N/N-1 tolerance, continuing\n";
    fwrite(STDERR, $schemaWarn);
}

$tol = auditToleranceDefault();
if (isset($opts['tolerance']) && is_numeric($opts['tolerance'])) {
    $tol = max(0, (int) $opts['tolerance']);
}
$topN = isset($opts['top']) && is_numeric($opts['top']) ? max(1, (int) $opts['top']) : 50;
$root = isset($opts['root']) && is_string($opts['root']) ? rtrim($opts['root'], '/') : null;
$prefixes = isset($opts['prefix']) && is_string($opts['prefix']) && $opts['prefix'] !== ''
    ? array_values(array_filter(array_map('trim', explode(',', $opts['prefix'])))) : [];

/** Normalize a report-side absolute-ish path to truth-side repo-relative form. */
$auditNormalizePath = static function (string $file, array $truthFiles) use ($root): string {
    if ($root !== null && str_starts_with($file, $root . '/')) {
        $file = substr($file, strlen($root . '/'));
    }
    if (isset($truthFiles[$file])) {
        return $file;
    }
    foreach ($truthFiles as $tf => $_) {
        if (str_ends_with($file, '/' . $tf)) {
            return $tf;
        }
    }
    return $file;
};

/* ===================== truth populations (once-106 cure) ===================== */

$findings = [];
$truthFiles = [];
foreach ($truth['findings'] as $f) {
    $members = is_array($f['members'] ?? null) ? $f['members'] : [];
    $keep = [];
    foreach ($members as $m) {
        $file = (string) ($m['file'] ?? '');
        if ($file === '') {
            continue;
        }
        if ($prefixes === []) {
            $keep[] = $m;
            continue;
        }
        foreach ($prefixes as $p) {
            // bench m4: honour path-segment boundaries — the prefix
            // `include/Api/Vps` must not admit `include/Api/VpsFoo.php`.
            $rooted = str_ends_with($p, '/') ? $p : $p . '/';
            if ($file === $p || str_starts_with($file, $rooted)) {
                $keep[] = $m;
                break;
            }
        }
    }
    if ($keep === []) {
        continue;
    }
    foreach ($keep as $m) {
        $truthFiles[(string) $m['file']] = true;
    }
    // Admissions: verified !== false gates every recall obligation
    // (verified:false = path absent at the scan pin — truth-side error,
    // never chargeable to the detector). Rangeless stays rangeless.
    $admittedRanged = [];
    $admittedRangeless = 0;
    $excludedUnverifiedRanged = 0;
    $llmAdmittedRanged = 0;
    foreach ($keep as $m) {
        $isRanged = isset($m['start']) && is_numeric($m['start']);
        if (($m['verified'] ?? null) === false) {
            if ($isRanged) {
                $excludedUnverifiedRanged++;
            }
            continue;
        }
        if ($isRanged) {
            $admittedRanged[] = $m;
            if ((string) ($m['origin'] ?? '') === 'llm') {
                $llmAdmittedRanged++;
            }
        } else {
            $admittedRangeless++;
        }
    }
    $ranged = auditUniqueRanged($admittedRanged);
    $rangedCount = count($ranged);
    $files = [];
    foreach ($keep as $m) {
        $files[(string) $m['file']] = true;
    }
    $pop = $rangedCount > 0 ? 'ranged' : ($admittedRangeless > 0 ? 'file-only' : 'truth-gap');
    $findings[] = [
        'id' => (string) $f['id'],
        'class' => (string) ($f['class'] ?? '?'),
        'title' => (string) ($f['title'] ?? ''),
        'population' => $pop,
        'ranged' => $ranged,
        'ranged_count' => $rangedCount,
        'files' => $files,
        'member_count' => count($keep),
        'rangeless_admitted' => $admittedRangeless,
        'excluded_unverified' => $excludedUnverifiedRanged,
        'llm_admitted' => $llmAdmittedRanged,
        'k' => $rangedCount >= 6 ? (int) ceil($rangedCount / 2) : max(1, min(2, $rangedCount)),
    ];
}
if ($findings === []) {
    fwrite(STDERR, "error: no truth findings match the window\n");
    exit(2);
}

/* ===================== clusters ===================== */

$clusters = [];
foreach ($report['clusters'] as $index => $c) {
    $members = [];
    foreach ((array) ($c['members'] ?? []) as $m) {
        $file = isset($m['file']) ? (string) $m['file'] : '';
        if ($file === '') {
            continue;
        }
        $members[] = [
            'file' => $auditNormalizePath($file, $truthFiles),
            'start' => $m['start'] ?? null,
            'end' => $m['end'] ?? null,
        ];
    }
    $files = [];
    foreach ($members as $m) {
        $files[(string) $m['file']] = true;
    }
    $clusters[] = [
        'index' => $index,
        'id' => (string) ($c['id'] ?? 'c' . $index),
        'kind' => (string) ($c['kind'] ?? ''),
        'size' => count($members),
        'impact' => $c['impact'] ?? null,
        'ranged' => auditUniqueRanged($members),
        'files' => $files,
    ];
}

/* ===================== links: ranged = tolerance Kuhn; file-only = graze ===================== */

$detected = [];   // fi => ['rule' => 'ranged-kuhn', 'ci' => int, 'matched' => int]
$bestMatch = [];  // fi => best tolerance-Kuhn match count seen
$grazed = [];     // fi => first cluster index that touches a cited file (NOT a detection)
$clusterRangedLink = []; // ci => [fi...] links with line evidence
foreach ($findings as $fi => $f) {
    $bestMatch[$fi] = 0;
}
foreach ($clusters as $ci => $c) {
    foreach ($findings as $fi => $f) {
        if ($f['population'] === 'ranged') {
            $matched = auditMaxToleranceMatching($c['ranged'], $f['ranged'], $tol);
            if ($matched > $bestMatch[$fi]) {
                $bestMatch[$fi] = $matched;
            }
            if ($matched >= $f['k'] && !isset($detected[$fi])) {
                $detected[$fi] = ['rule' => 'ranged-kuhn', 'ci' => $ci, 'matched' => $matched];
            }
            if ($matched >= 1) {
                $clusterRangedLink[$ci][] = $fi;
            }
            continue;
        }
        // file-only / truth-gap: graze tracking only, never a detection
        foreach ($f['files'] as $tf => $_) {
            if (isset($c['files'][$tf])) {
                if (!isset($grazed[$fi])) {
                    $grazed[$fi] = $ci;
                }
                break;
            }
        }
    }
}

$inWindow = [];
foreach ($clusters as $ci => $c) {
    foreach ($c['files'] as $file => $_) {
        foreach ($prefixes as $p) {
            // L-3 (once-S85 r1): the cluster-window admission uses the SAME
            // path-segment-boundary law (bench m4) as the truth-side member
            // admission above — a raw str_starts_with let a broader --root
            // admit `include/Api/VpsFoo.php` clusters into the denominator.
            $rooted = str_ends_with($p, '/') ? $p : $p . '/';
            if ($file === $p || str_starts_with($file, $rooted)) {
                $inWindow[$ci] = true;
                continue 3;
            }
        }
    }
    if ($prefixes === []) {
        $inWindow[$ci] = true;
    }
}
$windowClusters = array_keys($inWindow);
// signal = carries >=1 line-evidence (ranged) link; relevance-by-touch also
// counts clusters that merely graze cited files (the old "precision").
$signalClusters = array_values(array_filter($windowClusters, static fn (int $ci): bool => isset($clusterRangedLink[$ci]) && $clusterRangedLink[$ci] !== []));
$touchClusters = [];
foreach ($windowClusters as $ci) {
    foreach ($clusters[$ci]['files'] as $file => $_) {
        if (isset($truthFiles[$file])) {
            $touchClusters[$ci] = true;
            break;
        }
    }
}

$topSlice = array_slice($clusters, 0, $topN);
$topSignal = 0;
$topRows = [];
foreach ($topSlice as $c) {
    $ci = (int) $c['index'];
    $hasRanged = isset($clusterRangedLink[$ci]) && $clusterRangedLink[$ci] !== [];
    if ($hasRanged) {
        $topSignal++;
    }
    $touches = isset($inWindow[$ci]) ? 'window' : 'outside';
    $label = $hasRanged
        ? 'line-evidence'
        : (isset($touchClusters[$ci]) ? 'file-touch-only' : ($touches === 'outside' ? 'outside-window' : 'noise'));
    $sampleFiles = array_slice(array_keys($c['files']), 0, 2);
    $topRows[] = sprintf(
        '| %d | %s | %s | %d | %s | %s | %s | %s |',
        $ci + 1,
        $c['id'],
        $c['kind'],
        (int) $c['size'],
        $c['impact'] === null ? '-' : number_format((float) $c['impact']),
        $label,
        $touches,
        implode(', ', array_map(static fn (string $f): string => basename($f), $sampleFiles))
    );
}

/* ===================== metrics ===================== */

$rangedFindings = array_values(array_filter($findings, static fn (array $f): bool => $f['population'] === 'ranged'));
$fileOnlyFindings = array_values(array_filter($findings, static fn (array $f): bool => $f['population'] === 'file-only'));
$truthGapFindings = array_values(array_filter($findings, static fn (array $f): bool => $f['population'] === 'truth-gap'));

$rangedTotal = count($rangedFindings);
$rangedDetected = 0;
$llmAnchored = 0;
foreach ($findings as $fi => $f) {
    if ($f['population'] !== 'ranged' || !isset($detected[$fi])) {
        continue;
    }
    $rangedDetected++;
    if ($f['llm_admitted'] > 0 && $f['llm_admitted'] >= $detected[$fi]['matched']) {
        $llmAnchored++;
    }
}
$recall = $rangedTotal === 0 ? null : $rangedDetected / $rangedTotal;
$rangedEvidenceRelevance = count($windowClusters) === 0 ? null : count($signalClusters) / count($windowClusters);
$fileTouchRelevance = count($windowClusters) === 0 ? null : count($touchClusters) / count($windowClusters);
$relevanceTop = count($topSlice) === 0 ? null : $topSignal / count($topSlice);
$grazedFileOnly = 0;
foreach (array_keys($grazed) as $fi) {
    if ($findings[$fi]['population'] === 'file-only') {
        $grazedFileOnly++;
    }
}

$fmtPct = static fn (?float $v): string => $v === null ? 'n/a' : sprintf('%.1f%%', $v * 100);

/* per-detection provenance table */
$provRows = [];
foreach ($findings as $fi => $f) {
    if (isset($detected[$fi])) {
        $d = $detected[$fi];
        $provRows[] = sprintf(
            '| %s | %s | detected | ranged-kuhn (one-to-one ±%d) | %s | %d/%d | %d | %d |',
            $f['id'],
            $f['population'],
            $tol,
            $clusters[$d['ci']]['id'],
            $d['matched'],
            $f['k'],
            $f['ranged_count'],
            $f['llm_admitted']
        );
    } elseif ($f['population'] === 'ranged') {
        $provRows[] = sprintf(
            '| %s | %s | MISSED | best tolerance match < k | — | %d/%d | %d | %d |',
            $f['id'],
            $f['population'],
            $bestMatch[$fi],
            $f['k'],
            $f['ranged_count'],
            $f['llm_admitted']
        );
    } elseif (isset($grazed[$fi])) {
        $provRows[] = sprintf(
            '| %s | %s | NOT A DETECTION | file-level graze (zero line evidence) | %s | — | 0 | %d |',
            $f['id'],
            $f['population'],
            $clusters[$grazed[$fi]]['id'],
            $f['llm_admitted']
        );
    } else {
        $provRows[] = sprintf(
            '| %s | %s | untouched | %s | — | — | %d | %d |',
            $f['id'],
            $f['population'],
            $f['population'] === 'truth-gap' ? 'no admitted ranged token survives the verified gate (truth-side path gap — excluded from denominators)' : 'no cluster touches a cited file',
            $f['ranged_count'],
            $f['llm_admitted']
        );
    }
}

$fnLines = [];
foreach ($findings as $fi => $f) {
    if ($f['population'] !== 'ranged' || isset($detected[$fi])) {
        continue;
    }
    $fnLines[] = sprintf(
        '- `%s` [%s] %s — admitted-ranged=%d k=%d best-tolerance-match=%d%s',
        $f['id'],
        $f['class'],
        $f['title'],
        $f['ranged_count'],
        $f['k'],
        $bestMatch[$fi],
        $f['llm_admitted'] > 0 ? ' (llm-origin members: ' . $f['llm_admitted'] . ')' : ''
    );
}

$md = [];
$md[] = '# Audit score — ' . basename($opts['truth']) . ' × ' . basename($opts['report']);
$md[] = '';
$md[] = '- truth corpus: `' . (string) ($truth['corpus'] ?? '?') . '` (report sha `' . substr((string) ($truth['source_sha256'] ?? ''), 0, 12) . '…`)';
$md[] = '- report: phpdup ' . (string) ($report['phpdup_version'] ?? '?') . ' schema `' . $reportSchema . '` (target `' . $schemaTarget . '` via ' . $schemaSource . ')';
if ($schemaWarn !== '') {
    $md[] = '- **schema warning**: ' . trim($schemaWarn);
}
$md[] = '- window: ' . ($prefixes === [] ? 'ALL truth findings' : 'prefix `' . implode('`, `', $prefixes) . '`');
$md[] = '- tolerance: ±' . $tol . ' lines, one-to-one Kuhn matching — BenchmarkScoreCalculator predicate re-derived locally (retired: floor(line/tol) bucketing, PD-BENCH-04)';
$md[] = '- admission gate: members with `verified:false` (path absent at the scan pin) are EXCLUDED from ranged denominators; rangeless cites create graze records, never detections; origin `llm` members must pass the same gate (counts below)';
$md[] = '- rule: ranged finding detected when tolerance-Kuhn matches >= k; k=ceil(|admitted ranged unique|/2) when >=6 else max(1, min(2, |admitted ranged|)) (clamped so 1-member cites stay detectable); cluster↔finding links by member overlap ONLY (never cluster id)';
$md[] = '';
$md[] = '## Totals';
$md[] = '';
$md[] = '| metric | value |';
$md[] = '|---|---|';
$md[] = '| truth findings in window | ' . count($findings) . ' |';
$md[] = '| — ranged (recall obligation) | ' . $rangedTotal . ' |';
$md[] = '| — file-only cites (graze population, NOT recall) | ' . count($fileOnlyFindings) . ' |';
$md[] = '| — truth-gap (ranged tokens all failed the verified gate) | ' . count($truthGapFindings) . ' |';
$md[] = '| truth members in window | ' . array_sum(array_map(static fn (array $f): int => $f['member_count'], $findings)) . ' |';
$md[] = '| — excluded verified:false tokens (never scored) | ' . array_sum(array_map(static fn (array $f): int => $f['excluded_unverified'], $findings)) . ' |';
$md[] = '| admitted ranged members (deduped) | ' . array_sum(array_map(static fn (array $f): int => $f['ranged_count'], $findings)) . ' |';
$md[] = '| admitted llm-origin ranged members | ' . array_sum(array_map(static fn (array $f): int => $f['llm_admitted'], $findings)) . ' |';
$md[] = '| reported clusters | ' . count($clusters) . ' |';
$md[] = '| clusters touching window | ' . count($windowClusters) . ' |';
$md[] = '| — line-evidence (>=1 ranged link) | ' . count($signalClusters) . ' |';
$md[] = '| — cited-file touch (incl. graze-only) | ' . count($touchClusters) . ' |';
$md[] = '| ranged findings detected (line evidence) | ' . $rangedDetected . ' |';
$md[] = '| ranged findings missed | ' . ($rangedTotal - $rangedDetected) . ' |';
$md[] = '| **recall (ranged-evidence only)** | **' . $fmtPct($recall) . '** (' . $rangedDetected . '/' . $rangedTotal . ') |';
$md[] = sprintf('| file-level grazes among file-only cites | %d/%d (NOT counted as detections) |', $grazedFileOnly, count($fileOnlyFindings));
$md[] = '| **line-evidence relevance (of window clusters)** | **' . $fmtPct($rangedEvidenceRelevance) . '** — the honest precision-shaped number (clusters carrying >=1 ranged link / window clusters) |';
$md[] = '| file-touch relevance (was: "precision") | ' . $fmtPct($fileTouchRelevance) . ' — rename per once-106: this is "some cluster touched a cited file", NOT a detection-quality metric |';
$md[] = sprintf('| line-evidence relevance@top%d | %s (%d/%d) |', $topN, $fmtPct($relevanceTop), $topSignal, count($topSlice));
if ($llmAnchored > 0) {
    $md[] = '| detections whose matched members are all/many llm-origin | ' . $llmAnchored . ' (see provenance col llm) |';
}
$md[] = '';
$md[] = '## Per-finding provenance (rule + evidence + anchors)';
$md[] = '';
$md[] = '| finding | population | verdict | rule | cluster | matched/k | admitted ranged | llm-origin members |';
$md[] = '|---|---|---|---|---|---|---|---|';
foreach ($provRows as $r) {
    $md[] = $r;
}
$md[] = '';
$md[] = '## Top-' . $topN . ' slice (report order = ranked)';
$md[] = '';
$md[] = '| # | cluster id | kind | members | impact | label | window | sample files |';
$md[] = '|---|---|---|---|---|---|---|---|';
foreach ($topRows as $r) {
    $md[] = $r;
}
$md[] = '';
$md[] = '## Missed ranged truth findings (false negatives)';
$md[] = '';
if ($fnLines === []) {
    $md[] = '(none)';
} else {
    $md[] = implode("\n", $fnLines);
}
$md[] = '';
$md[] = 'Notes: cluster ids appear for display only; every TP/FP/FN/graze judgement above came from member-overlap matching.';
$md[] = 'Big-family k=half exposes pair-grained fragmentation honestly (f15 risk: prose-family recall penalty is truth-side; this is detector-side).';
$md[] = 'File-only cites and truth-gap findings carry NO recall obligation in this scorer — once-106/PD-BENCH-05 cure (the old single blended "recall" let 5/5 rangeless file-level grazes pose as detections).';

$out = (string) ($opts['out'] ?? '');
if ($out !== '') {
    if (file_put_contents($out, implode("\n", $md) . "\n") === false) {
        fwrite(STDERR, "error: cannot write --out\n");
        exit(2);
    }
    fwrite(STDERR, "score-audit: wrote {$out}\n");
} else {
    echo implode("\n", $md), "\n";
}
exit(0);
