<?php

declare(strict_types=1);

/**
 * bench/score-audit.php — mechanical audit scorer: audit-truth payload ×
 * phpdup JSON report -> recall / precision / top-N precision@k.
 *
 * Matching law (f15 s5, brief): clusters and truth findings are linked ONLY
 * by member overlap — cluster ids are display-only, never match keys. The
 * ±line bucketing rides src/Testing/BenchmarkScoreCalculator semantics:
 * bucket = floor(line / max(1, tolerance)), member key =
 * "file:bucketStart-bucketEnd", set intersection over member keys. We mirror
 * that formula here (tolerance is a PARAMETER, default 2) because src/ is
 * off-limits to this lane and calling into its private key() is impossible;
 * if the src formula moves, this file must move with it.
 *
 * Link rule: cluster c detects truth finding f when
 *   |keys(c) INTERSECT keys(f)| >= k(f),  k(f) = 2 for families under 6
 *   ranged members, ceil(|members|/2) for big families — "k=2 / half for
 *   big families" per spec. Big-family thresholds honestly expose today's
 *   pair-grained fragmentation (a 27-file family needs 14 members in one
 *   cluster) — that is a measured gap, not a scorer bug.
 * Rangeless truth members (file-claim cites) fall back to file-level
 * presence (k=1, flagged file_level in output).
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

/** @return array<string, string|false>|false */
function auditOpts(int $argc, array $argv): array|false
{
    $opts = getopt(
        'h',
        ['truth:', 'report:', 'prefix:', 'root:', 'top:', 'tolerance:', 'out::']
    );
    if ($opts === false || isset($opts['h'])) {
        return false;
    }
    foreach (['truth', 'report'] as $req) {
        if (!isset($opts[$req]) || !is_string($opts[$req])) {
            fwrite(STDERR, "error: --{$req} is required\n");
            return false;
        }
    }
    return $opts;
}

/** Read the expected schema from the shipped constant when autoloadable. */
function auditSchemaTarget(): array
{
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
        if (defined('Phpdup\\Reporting\\JsonSchemaSpec::SCHEMA_VERSION')) {
            $v = (string) constant('Phpdup\\Reporting\\JsonSchemaSpec::SCHEMA_VERSION');
            return [$v, 'JsonSchemaSpec'];
        }
    }
    return ['1.1', 'embedded-fallback'];
}

/**
 * @param array<int, array<string, mixed>> $members
 * @return array<string, true> bucket-key set
 */
function auditMemberKeys(array $members, string $tolerance): array
{
    $keys = [];
    $width = max(1, (int) $tolerance);
    foreach ($members as $m) {
        $file = (string) ($m['file'] ?? '');
        if ($file === '' || !isset($m['start']) || !is_numeric($m['start'])) {
            continue; // rangeless -> file-level path only
        }
        $start = (int) $m['start'];
        $end = isset($m['end']) && is_numeric($m['end']) ? (int) $m['end'] : $start;
        $bs = (int) floor(max(1, $start) / $width);
        $be = (int) floor(max(1, $end) / $width);
        $keys[$file . ':' . $bs . '-' . $be] = true;
    }
    return $keys;
}

/** @param array<int, array<string, mixed>> $members @return array<string, true> */
function auditFileSet(array $members): array
{
    $files = [];
    foreach ($members as $m) {
        $file = (string) ($m['file'] ?? '');
        if ($file !== '') {
            $files[$file] = true;
        }
    }
    return $files;
}

/** Normalize a report-side absolute-ish path to truth-side repo-relative form. */
function auditNormalizePath(string $file, array $truthFiles, ?string $root): string
{
    if ($root !== null && str_starts_with($file, rtrim($root, '/') . '/')) {
        $file = substr($file, strlen(rtrim($root, '/') . '/'));
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
}

$opts = auditOpts($argc, $argv);
if ($opts === false) {
    fwrite(STDERR, "usage: php bench/score-audit.php --truth=FILE --report=FILE [--prefix=A,B] [--root=DIR] [--top=50] [--tolerance=2] [--out=FILE]\n");
    exit(2);
}

$truthRaw = file_get_contents((string) $opts['truth']);
$reportRaw = file_get_contents((string) $opts['report']);
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

[$schemaTarget, $schemaSource] = auditSchemaTarget();
$reportSchema = (string) ($report['schema_version'] ?? '');
[$rmaj] = array_pad(explode('.', $reportSchema, 2), 2, '');
[$tmaj, $tmin] = array_pad(explode('.', $schemaTarget, 2), 2, '');
if ($rmaj !== $tmaj) {
    fwrite(STDERR, "error: report schema major '{$reportSchema}' incompatible with target '{$schemaTarget}' (from {$schemaSource})\n");
    exit(2);
}
$schemaWarn = '';
if ($reportSchema !== $schemaTarget) {
    $schemaWarn = "warning: report schema_version {$reportSchema} != target {$schemaTarget} (from {$schemaSource}) — N/N-1 tolerance, continuing\n";
    fwrite(STDERR, $schemaWarn);
}

$tolerance = isset($opts['tolerance']) && is_numeric($opts['tolerance']) ? (string) (int) $opts['tolerance'] : '2';
$topN = isset($opts['top']) && is_numeric($opts['top']) ? max(1, (int) $opts['top']) : 50;
$root = isset($opts['root']) && is_string($opts['root']) ? rtrim($opts['root'], '/') : null;
$prefixes = isset($opts['prefix']) && is_string($opts['prefix']) && $opts['prefix'] !== ''
    ? array_values(array_filter(array_map('trim', explode(',', $opts['prefix'])))) : [];

/** Truth window: findings with >=1 member file matching a prefix (or all). */
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
            if (str_starts_with($file, $p)) {
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
    $ranged = auditMemberKeys($keep, $tolerance);
    $files = auditFileSet($keep);
    $rangedCount = count($ranged);
    $findings[] = [
        'id' => (string) $f['id'],
        'class' => (string) ($f['class'] ?? '?'),
        'title' => (string) ($f['title'] ?? ''),
        'keys' => $ranged,
        'files' => $files,
        'member_count' => count($keep),
        'k' => $rangedCount >= 6 ? (int) ceil($rangedCount / 2) : max(1, min(2, $rangedCount)),
        'file_level' => $rangedCount === 0,
    ];
}
if ($findings === []) {
    fwrite(STDERR, "error: no truth findings match the window\n");
    exit(2);
}

/** Report clusters normalized to truth-side paths. */
$clusters = [];
foreach ($report['clusters'] as $index => $c) {
    $members = [];
    foreach ((array) ($c['members'] ?? []) as $m) {
        $file = isset($m['file']) ? (string) $m['file'] : '';
        if ($file === '') {
            continue;
        }
        $members[] = [
            'file' => auditNormalizePath($file, $truthFiles, $root),
            'start' => $m['start'] ?? null,
            'end' => $m['end'] ?? null,
        ];
    }
    $clusters[] = [
        'index' => $index,
        'id' => (string) ($c['id'] ?? 'c' . $index),
        'kind' => (string) ($c['kind'] ?? ''),
        'size' => count($members),
        'impact' => $c['impact'] ?? null,
        'keys' => auditMemberKeys($members, $tolerance),
        'files' => auditFileSet($members),
    ];
}

/** Link clusters <-> findings by member overlap only. */
$detected = [];
$bestOverlap = [];
$clusterSignal = [];
foreach ($findings as $fi => $f) {
    $bestOverlap[$fi] = 0;
}
foreach ($clusters as $ci => $c) {
    $linked = [];
    foreach ($findings as $fi => $f) {
        $overlap = 0;
        if ($f['file_level']) {
            foreach ($f['files'] as $tf => $_) {
                if (isset($c['files'][$tf])) {
                    $overlap = 1;
                    break;
                }
            }
            if ($overlap >= $f['k']) {
                $linked[] = $fi;
                $detected[$fi] = true;
            }
            continue;
        }
        foreach ($f['keys'] as $key => $_) {
            if (isset($c['keys'][$key])) {
                $overlap++;
            }
        }
        if ($overlap > $bestOverlap[$fi]) {
            $bestOverlap[$fi] = $overlap;
        }
        if ($overlap >= $f['k']) {
            $linked[] = $fi;
            $detected[$fi] = true;
        }
    }
    $clusterSignal[$ci] = $linked;
}

$inWindow = [];
foreach ($clusters as $ci => $c) {
    foreach ($c['files'] as $file => $_) {
        foreach ($prefixes as $p) {
            if (str_starts_with($file, $p)) {
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
$signalClusters = array_values(array_filter($windowClusters, static fn (int $ci): bool => $clusterSignal[$ci] !== []));
$noiseClusters = array_values(array_diff($windowClusters, $signalClusters));

$topSlice = array_slice($clusters, 0, $topN);
$topSignal = 0;
$topRows = [];
foreach ($topSlice as $c) {
    $ci = (int) $c['index'];
    $isSignal = $clusterSignal[$ci] !== [];
    if ($isSignal) {
        $topSignal++;
    }
    $touches = isset($inWindow[$ci]) ? 'window' : 'outside';
    $label = $isSignal ? 'signal' : ($touches === 'outside' ? 'outside-window' : 'noise');
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

$truthCount = count($findings);
$detectedCount = count($detected);
$recall = $truthCount === 0 ? 0.0 : $detectedCount / $truthCount;
$precision = count($windowClusters) === 0 ? 0.0 : count($signalClusters) / count($windowClusters);
$precisionTop = count($topSlice) === 0 ? 0.0 : $topSignal / count($topSlice);

$fnLines = [];
foreach ($findings as $fi => $f) {
    if (isset($detected[$fi])) {
        continue;
    }
    $fnLines[] = sprintf(
        '- `%s` [%s] %s — members=%d k=%d%s best-overlap=%d',
        $f['id'],
        $f['class'],
        $f['title'],
        $f['member_count'],
        $f['k'],
        $f['file_level'] ? ' (file-level rule)' : '',
        $bestOverlap[$fi]
    );
}

$md = [];
$md[] = '# Audit score — ' . basename((string) $opts['truth']) . ' × ' . basename((string) $opts['report']);
$md[] = '';
$md[] = '- truth corpus: `' . (string) ($truth['corpus'] ?? '?') . '` (report sha `' . substr((string) ($truth['source_sha256'] ?? ''), 0, 12) . '…)';
$md[] = '- report: phpdup ' . (string) ($report['phpdup_version'] ?? '?') . ' schema `' . $reportSchema . '` (target `' . $schemaTarget . '` via ' . $schemaSource . ')';
if ($schemaWarn !== '') {
    $md[] = '- **schema warning**: ' . trim($schemaWarn);
}
$md[] = '- window: ' . ($prefixes === [] ? 'ALL truth findings' : 'prefix `' . implode('`, `', $prefixes) . '`');
$md[] = '- tolerance: ' . $tolerance . ' line buckets (BenchmarkScoreCalculator semantics, mirrored — see header)';
$md[] = '- rule: detect when shared bucket-keys >= k; k=ceil(|members|/2) when >=6 else max(1, min(2, |members|)) (clamped so 1-member cites stay detectable); file-level fallback for rangeless findings; cluster↔finding links by member overlap ONLY (never cluster id)';
$md[] = '';
$md[] = '## Totals';
$md[] = '';
$md[] = '| metric | value |';
$md[] = '|---|---|';
$md[] = '| truth findings in window | ' . $truthCount . ' |';
$md[] = '| truth members in window | ' . array_sum(array_map(static fn (array $f): int => $f['member_count'], $findings)) . ' |';
$md[] = '| reported clusters | ' . count($clusters) . ' |';
$md[] = '| clusters touching window | ' . count($windowClusters) . ' |';
$md[] = '| — signal | ' . count($signalClusters) . ' |';
$md[] = '| — noise | ' . count($noiseClusters) . ' |';
$md[] = '| detected findings (TP) | ' . $detectedCount . ' |';
$md[] = '| missed findings (FN) | ' . ($truthCount - $detectedCount) . ' |';
$md[] = sprintf('| **recall** | **%.1f%%** |', $recall * 100);
$md[] = sprintf('| **precision (in-window)** | **%.1f%%** |', $precision * 100);
$md[] = sprintf('| **precision@top%d** | **%.1f%%** (%d/%d) |', $topN, $precisionTop * 100, $topSignal, count($topSlice));
$md[] = '';
$md[] = '## Top-' . $topN . ' slice (report order = ranked)';
$md[] = '';
$md[] = '| # | cluster id | kind | members | impact | label | window | sample files |';
$md[] = '|---|---|---|---|---|---|---|---|';
foreach ($topRows as $r) {
    $md[] = $r;
}
$md[] = '';
$md[] = '## Missed truth findings (false negatives)';
$md[] = '';
if ($fnLines === []) {
    $md[] = '(none)';
} else {
    $md[] = implode("\n", $fnLines);
}
$md[] = '';
$md[] = 'Notes: cluster ids appear for display only; every TP/FP/FN judgement above came from member-overlap keys.';
$md[] = 'Big-family k=half exposes pair-grained fragmentation honestly (f15 risk: prose-family recall penalty is truth-side; this is detector-side).';

$out = (string) ($opts['out'] ?? '');
if ($out !== '' && is_string($opts['out'])) {
    if (file_put_contents($out, implode("\n", $md) . "\n") === false) {
        fwrite(STDERR, "error: cannot write --out\n");
        exit(2);
    }
    fwrite(STDERR, "score-audit: wrote {$out}\n");
} else {
    echo implode("\n", $md), "\n";
}
exit(0);
