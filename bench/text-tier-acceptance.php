<?php
/**
 * Text-tier acceptance — keyed recall for the native token tier
 * (item 7.1) with the interim jscpd comparator leg.
 *
 * Runs the real `bin/phpdup --kinds text` over the planted
 * `bench/corpora/text-twins` corpus (see bench/corpora.php
 * generateTextTwins) and scores the report family-granular against the
 * corpus answer key with the SAME containment-aware calculator the
 * bench harness uses (BenchmarkScoreCalculator). The GATE is native:
 * every keyed family must be detected and no cluster may land on the
 * negative controls — a miss exits 1.
 *
 * The jscpd columns are INFORMATIONAL, never gates (the interim
 * comparator exists to show what the native tier catches that a
 * per-format competitor does not):
 *   - jscpd-default : stock invocation (no blanking flags),
 *   - jscpd-renamed : + --ignore-identifiers --ignore-literals (the
 *     tier-equivalent Type-2 stance, where the 5.x CLI offers it).
 * A missing/unusable binary (no --jscpd, no $JSCPD_BIN, no vendored or
 * PATH jscpd, a <5 version, a failed invocation) prints an honest
 * `[skip] …` notice and leaves the columns null — the leg passes green
 * on native truth alone. jscpd family detection is pair-based: a family
 * counts as caught when the report contains at least one clone pair
 * between two of its keyed files (a per-range score is additionally
 * emitted via the transitive-closure scorer).
 *
 * Usage:
 *   php bench/text-tier-acceptance.php                      # auto-discover comparator
 *   php bench/text-tier-acceptance.php --jscpd=/path/jscpd  # explicit binary
 *   php bench/text-tier-acceptance.php --no-jscpd           # comparator leg off
 *   php bench/text-tier-acceptance.php --corpus=DIR         # alternative corpus root
 *   php bench/text-tier-acceptance.php --out=evidence.json  # machine-readable evidence
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

define('PHPDUP_CORPORA_LIB', true);
require_once __DIR__ . '/corpora.php';

use Phpdup\Testing\BenchmarkScoreCalculator;

$root = dirname(__DIR__);
$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--')) {
        [$k, $v] = array_pad(explode('=', substr($a, 2), 2), 2, '1');
        $args[$k] = $v;
    }
}

$corpusDir = $args['corpus'] ?? ($root . '/bench/corpora/text-twins');
$tmp       = sys_get_temp_dir() . '/phpdup-text-tier-' . bin2hex(random_bytes(5));
mkdir($tmp, 0o777, true);

// Every $fail exit also sweeps the scratch dir — the early pin/report
// failures used to litter /tmp on each CI retry (m1, JLEG r1).
$fail = static function (string $msg) use ($tmp): never {
    fwrite(STDERR, "[FAIL] {$msg}\n");
    rmrf($tmp);
    exit(1);
};

// ---------- 0. corpus materialization + pin enforcement ----------

$manifest = $corpusDir . '/.ground-truth.json';
if (!is_file($manifest) || collectCorpusTextPaths($corpusDir) === []) {
    if ($corpusDir !== $root . '/bench/corpora/text-twins') {
        $fail("corpus {$corpusDir} has no planted tree/manifest and is not the in-repo text-twins root");
    }
    generateTextTwins($corpusDir);
}
$pins       = readPins($root . '/bench/corpus-pins.txt');
$pinFails   = [];
verifyTextTwins($pins, $corpusDir, $pinFails);
if ($pinFails !== []) {
    $fail('text-twins pin verification failed: ' . implode('; ', $pinFails));
}

// ---------- 1. gate: native text-tier recall against the key ----------

$gt       = json_decode((string)file_get_contents($manifest), true);
$isArr    = is_array($gt) && is_array($gt['clusters'] ?? null);
if (!$isArr) {
    $fail('answer key unreadable at ' . $manifest);
}
$groundTruth = array_values(array_map(static fn(array $f): array => $f, $gt['clusters']));
$negatives   = array_map('basename', (array)($gt['negative_files'] ?? []));
$families    = array_map(
    static fn(array $f): array => ['desc' => (string)($f['desc'] ?? ''), 'files' => array_map('basename', (array)($f['files'] ?? []))],
    (array)($gt['families'] ?? [])
);

// The answer-key manifest lives INSIDE the corpus dir; it is bench
// metadata, not planted code — hold it out of every detector's view
// (jscpd gets the equivalent -i glob below).
$scanRoot = $tmp . '/scan';
mkdir($scanRoot, 0o777, true);
foreach (collectCorpusTextPaths($corpusDir) as $rel) {
    copy($corpusDir . '/' . $rel, $scanRoot . '/' . $rel);
}

$phpdupOut = $tmp . '/phpdup.json';
$cmd = sprintf(
    'php %s analyze %s --kinds text --min-impact 0 --workers 1 --plain --no-cache --no-incremental --json %s',
    escapeshellarg($root . '/bin/phpdup'),
    escapeshellarg($scanRoot),
    escapeshellarg($phpdupOut),
);
exec($cmd . ' >/dev/null 2>&1', $_, $rc);
if (!is_file($phpdupOut)) {
    $fail("native run produced no report (exit {$rc}) — cmd: {$cmd}");
}
$report = json_decode((string)file_get_contents($phpdupOut), true);
if (!is_array($report)) {
    $fail('native report unparseable');
}

$reported = [];
foreach ((array)($report['clusters'] ?? []) as $c) {
    $group = [];
    foreach ((array)($c['members'] ?? []) as $m) {
        $group[] = [
            'file'  => basename((string)($m['file'] ?? '')),
            'start' => (int)($m['start_line'] ?? $m['start'] ?? 0),
            'end'   => (int)($m['end_line'] ?? $m['end'] ?? 0),
        ];
        if (isset($m['kind']) && (string)$m['kind'] !== 'text') {
            $fail('non-text member surfaced by a --kinds text run: ' . json_encode($m));
        }
    }
    if ($group !== []) {
        $reported[] = $group;
    }
}

$calc   = new BenchmarkScoreCalculator();
$native = $calc->score($reported, $groundTruth);

/** @var array<int,string> $missByGi keyed by family index */
$missByGi = [];
foreach ($groundTruth as $gi => $fam) {
    if (!familyCaught($calc, $reported, $fam)) {
        $missByGi[$gi] = ($families[$gi]['desc'] ?? 'family ' . $gi) . ' [' . implode(', ', array_column($fam, 'file')) . ']';
    }
}
$fnNames = array_values($missByGi);
$fpFiles = [];
foreach ($reported as $r) {
    $explained = false;
    foreach ($groundTruth as $fam) {
        if (clusterExplained($calc, $r, $fam)) {
            $explained = true;
            break;
        }
    }
    if (!$explained) {
        $fpFiles[] = implode('/', array_column($r, 'file'));
    }
}

printf(
    "native gate : %d/%d keyed families, reported %d clusters, precision %.2f, recall %.2f\n",
    count($groundTruth) - count($fnNames),
    count($groundTruth),
    count($reported),
    $native['precision'],
    $native['recall'],
);
foreach ($families as $gi => $fam) {
    $caught = !isset($missByGi[$gi]);
    printf("  %-4s %-8s %s\n", 'F' . ($gi + 1), $caught ? 'CAUGHT' : 'MISS', $fam['desc'] . ' — ' . implode(', ', $fam['files']));
}
foreach ($fnNames as $miss) {
    printf("  miss : %s\n", $miss);
}
foreach ($fpFiles as $fp) {
    printf("  false-positive cluster (negative/unplanted code): %s\n", $fp);
}
foreach ($negatives as $neg) {
    foreach ($reported as $r) {
        if (in_array($neg, array_column($r, 'file'), true)) {
            $fail("negative control {$neg} appears in a reported cluster (keyed recall must be clean)");
        }
    }
}

// ---------- 2. informational: interim jscpd comparator ----------

$jscpdLegs = ['default' => null, 'renamed' => null];
$jscpdPath = resolveJscpd($args, $root);
$skipNotice = null;
if (($args['no-jscpd'] ?? null) === '1') {
    $skipNotice = '--no-jscpd given';
} elseif ($jscpdPath === null) {
    $skipNotice = 'no jscpd binary (--jscpd/$JSCPD_BIN/bench/tools/PATH all absent)';
} else {
    $version = jscpdMajorMinor($jscpdPath);
    if ($version === null) {
        $skipNotice = "jscpd at {$jscpdPath} did not answer --version";
    } elseif ($version[0] < 5) {
        $skipNotice = sprintf('jscpd %d.%d found at %s — text comparator leg needs the >=5 (Rust) CLI; the npm 4.x vendored leg stays the PHP-corpus comparator', $version[0], $version[1], $jscpdPath);
    } else {
        $jscpdLegs = jscpdColumns($jscpdPath, $scanRoot, $tmp, $groundTruth, $families);
    }
}
if ($skipNotice !== null) {
    printf("[skip] jscpd comparator leg: %s — informational columns null, gate unaffected\n", $skipNotice);
} else {
    foreach ($jscpdLegs as $mode => $leg) {
        if ($leg === null) {
            printf("  jscpd(%s): invocation failed — column stays null (informational, never a gate)\n", $mode);
            continue;
        }
        printf(
            "  jscpd(%s): %d/%d keyed families caught · pairs=%d · closure recall=%.2f f1=%.2f · blind spots: %s\n",
            $mode,
            $leg['caught'],
            count($groundTruth),
            $leg['pairs'],
            $leg['score']['recall'],
            $leg['score']['f1'],
            $leg['missed'] === [] ? 'none' : implode('; ', $leg['missed']),
        );
    }
}

// ---------- 3. evidence + verdict ----------

$evidence = [
    'generated_at'   => gmdate('c'),
    'corpus'         => $corpusDir,
    'pins_verified'  => true,
    'native'         => [
        'families'    => count($groundTruth),
        'caught'      => count($groundTruth) - count($fnNames),
        'missed'      => $fnNames,
        'reported'    => count($reported),
        'false_positive_clusters' => $fpFiles,
        'precision'   => $native['precision'],
        'recall'      => $native['recall'],
        'f1'          => $native['f1'],
        'argv'        => $cmd,
    ],
    'jscpd'          => [
        'binary'      => $skipNotice === null ? $jscpdPath : null,
        'skip_notice' => $skipNotice,
        'legs'        => $jscpdLegs,
    ],
];
if (isset($args['out'])) {
    file_put_contents((string)$args['out'], json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    printf("[ok] evidence written to %s\n", (string)$args['out']);
}

if ($fnNames !== [] || $fpFiles !== []) {
    fwrite(STDERR, "[FAIL] native text-tier keyed recall must be exact and negatives clean\n");
    rmrf($tmp);
    exit(1);
}
echo "[GATE] native text-tier recall EXACT — interim comparator deltas are informational only\n";
rmrf($tmp);
exit(0);

// ---------- helpers ----------

/**
 * Family-granular detection probe built ONLY on the public score() API:
 * a family counts as caught when some single reported cluster scores
 * recall > 0 against it (the calculator's own one-to-one ±tolerance
 * rule, no private-method reflection).
 *
 * @param list<list<array{file:string,start:int,end:int}>> $reported
 * @param list<array{file:string,start:int,end:int}> $family
 */
function familyCaught(BenchmarkScoreCalculator $calc, array $reported, array $family): bool
{
    foreach ($reported as $r) {
        if ($calc->score([$r], [$family])['recall'] > 0.0) {
            return true;
        }
    }
    return false;
}

/**
 * Precision-side probe, again public-API-only: a reported cluster is
 * explained when scoring it alone against the family yields tp = 1.
 *
 * @param list<array{file:string,start:int,end:int}> $r
 * @param list<array{file:string,start:int,end:int}> $family
 */
function clusterExplained(BenchmarkScoreCalculator $calc, array $r, array $family): bool
{
    return $calc->score([$r], [$family])['tp'] === 1;
}

function resolveJscpd(array $args, string $root): ?string
{
    $cands = [];
    if (isset($args['jscpd'])) {
        $cands[] = (string)$args['jscpd'];
    }
    $env = getenv('JSCPD_BIN');
    if (is_string($env) && $env !== '') {
        $cands[] = $env;
    }
    $cands[] = $root . '/bench/tools/node_modules/.bin/jscpd';
    $out = [];
    $rc  = 0;
    exec('command -v jscpd 2>/dev/null', $out, $rc);
    if ($rc === 0 && ($out[0] ?? '') !== '') {
        $cands[] = trim($out[0]);
    }
    foreach ($cands as $c) {
        if (is_executable($c)) {
            return $c;
        }
    }
    return null;
}

/** @return ?array{0:int,1:int} */
function jscpdMajorMinor(string $bin): ?array
{
    $out = [];
    $rc  = 0;
    exec(escapeshellarg($bin) . ' --version 2>/dev/null', $out, $rc);
    if ($rc !== 0 || $out === []) {
        return null;
    }
    if (preg_match('/(\d+)\.(\d+)/', (string)($out[0] ?? ''), $m) === 1) {
        return [(int)$m[1], (int)$m[2]];
    }
    return null;
}

/**
 * Run both informational legs and score them.
 *
 * @param list<list<array{file:string,start:int,end:int}>> $groundTruth
 * @param list<array{desc:string,files:list<string>}> $families
 * @return array{default:?array<string,mixed>, renamed:?array<string,mixed>}
 */
function jscpdColumns(string $bin, string $scanRoot, string $tmp, array $groundTruth, array $families): array
{
    $legs = [];
    foreach (['default' => [], 'renamed' => ['--ignore-identifiers', '--ignore-literals']] as $mode => $extra) {
        $outDir = $tmp . '/jscpd-' . $mode;
        $cmd = sprintf(
            '%s %s %s --reporters json --output %s --no-colors -i %s',
            escapeshellarg($bin),
            escapeshellarg($scanRoot),
            implode(' ', array_map('escapeshellarg', $extra)),
            escapeshellarg($outDir),
            escapeshellarg('**/.ground-truth.json'),
        );
        exec($cmd . ' >/dev/null 2>&1', $_, $rc);
        $file = $outDir . '/jscpd-report.json';
        if (!is_file($file)) {
            $legs[$mode] = null; // honest null column — invocation failed
            continue;
        }
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data)) {
            $legs[$mode] = null;
            continue;
        }
        $pairs = [];
        foreach ((array)($data['duplicates'] ?? []) as $d) {
            $f1 = $d['firstFile'] ?? null;
            $f2 = $d['secondFile'] ?? null;
            if (!is_array($f1) || !is_array($f2)) {
                continue;
            }
            $a = jscpdMember($f1);
            $b = jscpdMember($f2);
            if ($a !== null && $b !== null) {
                $pairs[] = [$a, $b];
            }
        }
        $closed = BenchmarkScoreCalculator::closureFromPairs($pairs);
        $score  = (new BenchmarkScoreCalculator())->score($closed, $groundTruth);
        $caught = 0;
        $missed = [];
        foreach ($families as $gi => $fam) {
            $hit = false;
            foreach ($pairs as [$a, $b]) {
                if (in_array($a['file'], $fam['files'], true) && in_array($b['file'], $fam['files'], true) && $a['file'] !== $b['file']) {
                    $hit = true;
                    break;
                }
            }
            if ($hit) {
                $caught++;
            } else {
                $missed[] = $fam['desc'];
            }
        }
        $legs[$mode] = [
            'caught'  => $caught,
            'pairs'   => count($pairs),
            'score'   => $score,
            'missed'  => $missed,
            'argv'    => $cmd,
            'exit'    => $rc,
        ];
    }
    return $legs;
}

/** @return ?array{file:string,start:int,end:int} */
function jscpdMember(array $f): ?array
{
    $name = (string)($f['name'] ?? '');
    if ($name === '') {
        return null;
    }
    // v5: flat start/end lines (+startLoc/endLoc); v4: block.start.line.
    if (isset($f['start'], $f['end'])) {
        $start = is_array($f['start']) ? (int)($f['start']['line'] ?? 0) : (int)$f['start'];
        $end   = is_array($f['end']) ? (int)($f['end']['line'] ?? 0) : (int)$f['end'];
    } elseif (isset($f['block']['start']['line'])) {
        $start = (int)$f['block']['start']['line'];
        $end   = (int)($f['block']['end']['line'] ?? $start);
    } else {
        return null;
    }
    return ['file' => basename($name), 'start' => $start, 'end' => $end];
}

function rmrf(string $dir): void
{
    if (is_dir($dir)) {
        exec('rm -rf ' . escapeshellarg($dir));
    }
}
