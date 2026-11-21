<?php
/**
 * phpdup vs alternatives — wall time, peak RSS, cluster-family counts.
 *
 * Usage:
 *   php bench/comparative.php <path-to-corpus> [--timeout=180] [--json=FILE] [--sha=<phpdup git sha>]
 *
 * Tools probed (skipped with an honest status row when missing):
 *   - phpdup           (this repo)
 *   - phpcpd           (sebastianbergmann/phpcpd)
 *   - phpmd-cpd        (PMD's PHP CPD via the `pmd` jar)
 *
 * Honesty rules (once-107 / PD-BENCH-09, -10, -11):
 *   - Every count is UNIT-TYPED per tool. The old single "Clusters"
 *     column mixed pipeline clusters, regex-parsed stdout fragments and
 *     console separator counts — numbers no reader may divide by each
 *     other. Competitor counts now come from structured output only
 *     (phpcpd/pmd `--log-pmd`/`--format xml`), reported as raw duplicate
 *     PAIRS plus the transitive-closure family count via
 *     BenchmarkScoreCalculator::closureFromPairs() — the same law
 *     bench/run.php applies.
 *   - Timeouts and crashed runs are shown as `timeout` / `error exit-N` —
 *     never as a measurement (and never silently scored as 0.00: rows
 *     with an error carry count=null, no wall, no peak, and a status
 *     reason). "Ran successfully" is a PER-TOOL ok-exit set
 *     (once-S85 r1 M-1): phpcpd/pmd keep the `exit 1 = duplicates
 *     found` convention; phpdup is [0] only — its contract (docs/CLI.md
 *     "Exit codes") exits 0 WITH findings and 1 on internal/runtime
 *     errors (unknown options included), so rc=1 can never launder a
 *     dead phpdup run into an "ok" row.
 *   - The per-tool threshold sets differ and change the work being
 *     timed; the fairness footnote below the table is MANDATORY reading
 *     (phpdup runs --max-df 0.5 = 50x the production default 0.01).
 *   - --json writes a provenance-bearing raw artifact (host, php,
 *     phpdup sha, argv per row) with NO wall-clock timestamps —
 *     committed figures must be re-derivable from it (Artifacts are
 *     UTC-stable).
 *
 * The phpdup row is always included; alternative tools that aren't on
 * $PATH show '—' with status `not installed` so the harness still
 * produces a comparable snapshot.
 */
declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

const CMP_TIMEOUT_DEFAULT = 180;

if ($argc < 2) {
    fwrite(STDERR, "Usage: php bench/comparative.php <corpus-dir> [--timeout=180] [--json=FILE] [--sha=<git sha>]\n");
    exit(2);
}
$corpus = $argv[1];
if (!is_dir($corpus)) {
    fwrite(STDERR, "corpus not found: {$corpus}\n");
    exit(2);
}

/* PHP's getopt() stops option parsing at the first non-option argument, and
 * the corpus positional comes first — parse the long flags manually. */
$opts = [];
foreach (array_slice($argv, 2) as $a) {
    if (str_starts_with($a, '--') && str_contains($a, '=')) {
        [$k, $v] = explode('=', substr($a, 2), 2);
        $opts[$k] = $v;
    }
}
$cap  = isset($opts['timeout']) && is_numeric($opts['timeout']) ? max(1, (int) $opts['timeout']) : CMP_TIMEOUT_DEFAULT;
$jsonOut = isset($opts['json']) && is_string($opts['json']) ? $opts['json'] : null;

$root = dirname(__DIR__);
$phpdupSha = isset($opts['sha']) && is_string($opts['sha']) && $opts['sha'] !== ''
    ? trim($opts['sha'])
    : trim((string) @shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse HEAD 2>/dev/null'));
$corpusFiles = countPhpFiles($corpus);

$fairnessNotes = [
    'phpdup runs --min-impact 0 --max-df 0.5 --no-cache --no-incremental: max-df 0.5 is 50x the production default (0.01, src/Cli/Config.php), so the candidate-pair volume timed here is NOT the production workload.',
    'phpcpd runs --fuzzy --min-lines 5 --min-tokens 50; pmd-cpd runs cpd --minimum-tokens 50. Different tools under different floors count different workloads: wall times are per-tool work profiles, not a like-for-like race.',
    'Single-shot samples: no repeats, no variance, whatever host load applies at run time. Treat as order-of-magnitude only.',
    'Competitor counts are duplicate PAIRS from structured XML plus the transitive-closure family count (same normalization bench/run.php applies); phpdup emits pipeline clusters directly.',
];

$rows = [];

/* ---------- phpdup ---------- */
$phpdupJson = cmpTmp('phpdup');
$rows[] = runTool(
    name: 'phpdup',
    cmd:  ['php', $root . '/bin/phpdup', 'analyze', $corpus, '--json', $phpdupJson,
           '--plain', '--min-impact', '0', '--max-df', '0.5', '--no-cache', '--no-incremental'],
    cap: $cap,
    parse: static function (string $stdout, ?string $file) use ($root): array {
        if ($file === null || !is_file($file)) {
            return ['count' => null, 'unit' => 'pipeline clusters', 'extra' => []];
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || !isset($data['summary']['clusters'])) {
            return ['count' => null, 'unit' => 'pipeline clusters', 'extra' => []];
        }
        $members = [];
        foreach ((array) ($data['clusters'] ?? []) as $c) {
            $g = [];
            foreach ((array) ($c['members'] ?? []) as $m) {
                $g[] = [
                    'file'  => (string) ($m['file'] ?? ''),
                    'start' => (int) ($m['start_line'] ?? $m['start'] ?? 0),
                    'end'   => (int) ($m['end_line'] ?? $m['end'] ?? 0),
                ];
            }
            if ($g !== []) {
                $members[] = $g;
            }
        }
        return [
            'count' => (int) $data['summary']['clusters'],
            'unit'  => 'pipeline clusters',
            'extra' => ['member_groups' => count($members)],
        ];
    },
    okRc: [0], // phpdup: 0 = ok (findings included); 1 = runtime error, NOT "duplicates found" (M-1)
    jsonFile: $phpdupJson,
);

/* ---------- phpcpd ---------- */
if (commandExists('phpcpd')) {
    $cpdXml = cmpTmp('phpcpd');
    $rows[] = runTool(
        name: 'phpcpd',
        cmd:  ['phpcpd', '--fuzzy', '--min-lines', '5', '--min-tokens', '50', '--log-pmd', $cpdXml, $corpus],
        cap: $cap,
        parse: cmpPairXmlParser('phpcpd duplicate pairs', 'phpcpd closed clone families'),
        okRc: [0, 1], // phpcpd convention: 1 = duplicates found
        jsonFile: $cpdXml,
    );
} else {
    $rows[] = notInstalledRow('phpcpd');
}

/* ---------- pmd-cpd ---------- */
if (commandExists('pmd')) {
    $pmdXml = cmpTmp('pmd-cpd');
    $rows[] = runTool(
        name: 'pmd-cpd',
        cmd:  ['pmd', 'cpd', '--language', 'php', '--minimum-tokens', '50', '--format', 'xml', '--dir', $corpus, '--no-fail-on-violation'],
        cap: $cap,
        parse: cmpPairXmlParser('pmd-cpd duplicate pairs', 'pmd-cpd closed clone families'),
        okRc: [0, 1], // pmd convention retained (run with --no-fail-on-violation; 1 kept benign for duplicates rows)
        stdoutFile: $pmdXml,
    );
} else {
    $rows[] = notInstalledRow('pmd-cpd');
}

/* ---------- render ---------- */

echo "| Tool | Status | Wall (s) | Peak RSS (MB) | Count (unit) |\n";
echo "|---|---|---:|---:|---|\n";
foreach ($rows as $r) {
    $count = $r['count'] === null
        ? '— (not measured)'
        : $r['count'] . ' (' . $r['unit'] . ')'
            . (isset($r['extra']['pairs']) ? '; raw pairs: ' . $r['extra']['pairs'] . ' → closed families' : '');
    printf(
        "| %s | %s | %s | %s | %s |\n",
        $r['name'],
        $r['status'],
        $r['error'] !== null && $r['error'] !== '' ? '—' : $r['wall_s'],
        $r['peak_mb'],
        $count
    );
}
echo "\nFairness notes (mandatory reading — timings compare DIFFERENT workloads per tool):\n";
foreach ($fairnessNotes as $i => $n) {
    echo '  ' . ($i + 1) . '. ' . $n . "\n";
}
echo "\nCorpus: {$corpus} ({$corpusFiles} PHP files) · single-shot samples\n";

/* ---------- raw JSON artifact (committed provenance; no timestamps) ---------- */

if ($jsonOut !== null) {
    $payload = [
        'corpus'         => basename($corpus),
        'corpus_arg'     => $corpus,   // as invoked — run from the repo root with a relative path and this artifact stays portable (PD-BENCH-13 lesson)
        'corpus_php_files' => $corpusFiles,
        'host'           => php_uname('n'),
        'php_version'    => PHP_VERSION,
        'phpdup_sha'     => $phpdupSha !== '' ? $phpdupSha : 'unknown (non-git runner tree)',
        'timeout_s'      => $cap,
        'fairness_notes' => $fairnessNotes,
        'rows'           => array_map(static fn (array $r): array => [
            'tool'      => $r['name'],
            'status'    => $r['status'],
            'error'     => $r['error'],
            'wall_s'    => $r['wall_num'],
            'peak_mb'   => $r['peak_num'],
            'count'     => $r['count'],
            'unit'      => $r['unit'],
            'extra'     => $r['extra'],
            // Provenance argv, host-path sanitized: repo root -> <repo>,
            // temp dir -> <tmp> (absolute local paths never enter committed
            // artifacts — PD-BENCH-13 lesson).
            'argv'      => $r['argv'] === null ? null : array_map(
                fn (string $a): string => str_replace([$root, sys_get_temp_dir()], ['<repo>', '<tmp>'], $a),
                $r['argv']
            ),
        ], $rows),
    ];
    if (file_put_contents($jsonOut, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
        fwrite(STDERR, "comparative: cannot write --json\n");
        exit(2);
    }
    fwrite(STDERR, "comparative: wrote {$jsonOut}\n");
}
exit(0);

/* ---------- helpers ---------- */

/**
 * @return array<string, mixed> a fully-formed row for an absent tool
 */
function notInstalledRow(string $name): array
{
    return [
        'name' => $name, 'status' => 'not installed', 'error' => null,
        'wall_s' => '—', 'wall_num' => null, 'peak_mb' => '—', 'peak_num' => null,
        'count' => null, 'unit' => 'n/a', 'extra' => [], 'argv' => null,
    ];
}

/**
 * Parse a PMD-CPD-shape XML (phpcpd --log-pmd and pmd cpd --format xml
 * share it): <duplication> elements are PAIR records — the raw pair count
 * and the transitive-closure family count are both reported (once-107:
 * never a stdout-regex count, never one unit-blind column).
 */
function cmpPairXmlParser(string $pairUnit, string $familyUnit): callable
{
    return static function (string $stdout, ?string $file) use ($pairUnit, $familyUnit): array {
        if ($file === null || !is_file($file)) {
            return ['count' => null, 'unit' => $familyUnit, 'extra' => []];
        }
        $xml = @simplexml_load_string((string) file_get_contents($file));
        if (!$xml instanceof SimpleXMLElement) {
            return ['count' => null, 'unit' => $familyUnit, 'extra' => []];
        }
        $pairs = [];
        foreach ($xml->duplication as $dup) {
            $lines = (int) $dup['lines'];
            $group = [];
            foreach ($dup->file as $f) {
                $start = (int) $f['line'];
                $group[] = [
                    'file'  => (string) $f['path'],
                    'start' => $start,
                    'end'   => $start + max(0, $lines - 1),
                ];
            }
            if ($group !== []) {
                $pairs[] = $group;
            }
        }
        if (!class_exists(\Phpdup\Testing\BenchmarkScoreCalculator::class)) {
            return ['count' => null, 'unit' => $familyUnit . ' (closure helper unavailable)', 'extra' => []];
        }
        $families = \Phpdup\Testing\BenchmarkScoreCalculator::closureFromPairs($pairs);
        return [
            'count' => count($families),
            'unit'  => $familyUnit,
            'extra' => ['pairs' => count($pairs), 'pair_unit' => $pairUnit],
        ];
    };
}

/**
 * @param list<string> $cmd
 * @param list<int> $okRc exit codes that mean "this tool ran successfully"
 *                       — per-tool sets, never a blanket rc<=1 (M-1)
 * @param ?string $stdoutFile file the tool's stdout was redirected to (also
 *                            fed to the parser as $file when set)
 * @return array<string, mixed>
 */
function runTool(string $name, array $cmd, callable $parse, int $cap, array $okRc, ?string $jsonFile = null, ?string $stdoutFile = null): array
{
    $file = $jsonFile ?? $stdoutFile;
    // The shell always needs a concrete stdout sink; only pmd feeds the
    // parser through stdout capture.
    $stdoutSink = $stdoutFile ?? cmpTmp('stdout-capture');
    $out = tempnam(sys_get_temp_dir(), 'phpdup-cmp-');
    $cmdline = sprintf(
        'timeout %d /usr/bin/time -v %s > %s 2> %s',
        $cap,
        implode(' ', array_map('escapeshellarg', $cmd)),
        escapeshellarg($stdoutSink),
        escapeshellarg((string) $out)
    );
    $start = microtime(true);
    $rc = 0;
    @exec($cmdline, $_, $rc);
    $wall = microtime(true) - $start;
    $timeLog = (string) @file_get_contents((string) $out);
    @unlink($out);

    $peakNum = null;
    if (preg_match('/Maximum resident set size \(kbytes\): (\d+)/', $timeLog, $m)) {
        $peakNum = round(((int) $m[1]) / 1024, 1);
    }
    $stdoutText = $stdoutFile !== null ? (string) @file_get_contents($stdoutFile) : '';

    $error = null;
    $status = 'ok';
    if ($rc === 124 || $rc === 137) {
        // timeout(1) sends 124 on TERM, 137 on KILL — either way the
        // measurement does not exist: show `timeout`, not a fake sample.
        $status = 'timeout';
        $error = "timeout-{$cap}s";
    } elseif (!in_array($rc, $okRc, true)) {
        // M-1 (once-S85 r1): "exit 1 = duplicates found" is the phpcpd/pmd
        // convention, NOT phpdup's — phpdup exits 0 even with findings and
        // 1 only on internal/runtime errors (docs/CLI.md "Exit codes"), so
        // a blanket rc<=1 would launder a crashed phpdup run into `ok`.
        // Anything outside the tool's ok set is a failed run.
        $status = "error exit-{$rc}";
        $error = "exit-{$rc}";
    }

    $parsed = $parse($stdoutText, $file);
    if ($error !== null) {
        // Nothing measurable may survive an error/timeout row.
        $parsed = ['count' => null, 'unit' => $parsed['unit'] ?? 'n/a', 'extra' => []];
        // L-2 (once-S85 r1): the same rule covers wall AND peak — a partial
        // peak of a killed/failed run is not a measurement, and the old
        // `wall_s => $status` fallback was dead anyway (the renderer and
        // the JSON artifact never print it on error rows).
        $peakNum = null;
    }

    return [
        'name'     => $name,
        'status'   => $status,
        'error'    => $error,
        'wall_s'   => $error === null ? sprintf('%.2f', $wall) : '—',
        'wall_num' => $error === null ? round($wall, 3) : null,
        'peak_mb'  => $peakNum === null ? '—' : (string) $peakNum,
        'peak_num' => $peakNum,
        'count'    => $parsed['count'],
        'unit'     => $parsed['unit'],
        'extra'    => $parsed['extra'] ?? [],
        'argv'     => $cmd,
    ];
}

function commandExists(string $name): bool
{
    return trim((string) @shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null')) !== '';
}

function cmpTmp(string $tag): string
{
    return sys_get_temp_dir() . "/phpdup-cmp-{$tag}-" . getmypid() . '-' . bin2hex(random_bytes(4));
}

function countPhpFiles(string $dir): int
{
    $out = [];
    $rc = 0;
    @exec('find ' . escapeshellarg($dir) . ' -type f -name "*.php" 2>/dev/null | wc -l', $out, $rc);
    return (int) trim($out[0] ?? '0');
}
