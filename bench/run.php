<?php
/**
 * Benchmark runner — runs every available duplicate-detection tool
 * against every corpus under `bench/corpora/` and writes a single
 * results JSON plus a markdown summary at `bench/results/latest.md`.
 *
 * Tools (auto-skipped when missing):
 *   - phpdup   — this repo's bin/phpdup
 *   - phpcpd   — bench/tools/phpcpd.phar (auto-downloaded by run-all.sh)
 *                or the system `phpcpd` binary
 *   - pmd-cpd  — system `pmd cpd` (rare on dev hosts)
 *   - jscpd    — bench/tools/node_modules/.bin/jscpd or system `jscpd`
 *   - simian   — system `simian` (commercial, almost never present)
 *
 * Usage:
 *   php bench/run.php                    # all corpora, all tools
 *   php bench/run.php --corpus=NAME      # single corpus
 *   php bench/run.php --label=initial    # tag the run filename
 *
 * Each tool gets a per-run wall-time cap so the harness can't be
 * deadlocked by a runaway invocation.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Phpdup\Testing\BenchmarkResultsRenderer;

$root        = dirname(__DIR__);
$corporaDir  = $root . '/bench/corpora';
$resultsDir  = $root . '/bench/results';
$toolsDir    = $root . '/bench/tools';
$nodeBin     = $toolsDir . '/node_modules/.bin';
@mkdir($resultsDir, 0o775, true);

$args  = parseArgs($argv);
$only  = $args['corpus'] ?? null;
$label = $args['label']  ?? 'run-' . date('Ymd-His');
$cap   = (int)($args['timeout'] ?? 180);

$corpora = listCorpora($corporaDir, $only);
if ($corpora === []) {
    if (!is_dir($corporaDir) || $corpora === []) {
        echo "[fallback] no bench/corpora/ entries — using tests/Fixtures as the sole corpus\n";
        $corpora = [['name' => 'tests-fixtures', 'path' => $root . '/tests/Fixtures']];
    }
}

$tools = discoverTools($root, $toolsDir, $nodeBin);
echo "[info] tools available: " . implode(', ', array_map(fn(array $t): string => $t['name'], $tools)) . "\n";
echo "[info] corpora: " . implode(', ', array_column($corpora, 'name')) . "\n";

$rows = [];
foreach ($corpora as $corpus) {
    $files = countPhpFiles($corpus['path']);
    foreach ($tools as $tool) {
        echo "[run] {$tool['name']} on {$corpus['name']} ($files PHP files)\n";
        $row = runTool($tool, $corpus, $cap);
        $row['corpus'] = $corpus['name'];
        $row['files']  = $files;
        $rows[] = $row;
        echo sprintf(
            "      wall=%s  rss=%s  clusters=%s\n",
            $row['wall_s'] === null ? '—' : sprintf('%.2fs', $row['wall_s']),
            $row['peak_mb'] === null ? '—' : sprintf('%.1fMB', $row['peak_mb']),
            $row['clusters'] === null ? '—' : (string)$row['clusters'],
        );
    }
}

$jsonOut = $resultsDir . '/' . $label . '.json';
file_put_contents($jsonOut, json_encode([
    'generated_at' => gmdate('c'),
    'host'         => php_uname('n'),
    'php_version'  => PHP_VERSION,
    'rows'         => $rows,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "[ok] wrote {$jsonOut}\n";

$renderer = new BenchmarkResultsRenderer();
$md = $renderer->renderRunMarkdown(array_map(static function (array $r): array {
    return [
        'corpus'   => $r['corpus'],
        'files'    => $r['files'] ?? null,
        'tool'     => $r['tool'],
        'wall_s'   => $r['wall_s'],
        'peak_mb'  => $r['peak_mb'],
        'clusters' => $r['clusters'],
        'extra'    => $r['extra'] ?? [],
    ];
}, $rows));
file_put_contents($resultsDir . '/latest.md', $md);
echo "[ok] wrote {$resultsDir}/latest.md\n";

// ---------- helpers ----------

/**
 * @param list<string> $argv
 * @return array<string, string>
 */
function parseArgs(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $a) {
        if (str_starts_with($a, '--') && str_contains($a, '=')) {
            [$k, $v] = explode('=', substr($a, 2), 2);
            $out[$k] = $v;
        }
    }
    return $out;
}

/** @return list<array{name:string,path:string}> */
function listCorpora(string $dir, ?string $only): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (!is_dir($path)) {
            continue;
        }
        if ($only !== null && $entry !== $only) {
            continue;
        }
        $out[] = ['name' => $entry, 'path' => $path];
    }
    return $out;
}

function countPhpFiles(string $dir): int
{
    $rc = 0;
    $out = [];
    @exec('find ' . escapeshellarg($dir) . ' -type f -name "*.php" 2>/dev/null | wc -l', $out, $rc);
    return (int)trim($out[0] ?? '0');
}

/** @return list<array{name:string, cmd:list<string>, post:callable}> */
function discoverTools(string $root, string $toolsDir, string $nodeBin): array
{
    $tools = [];
    // phpdup — always present (this repo).
    $tools[] = [
        'name'    => 'phpdup',
        'present' => true,
        'cmd_for' => static function (string $corpus, string $tmp) use ($root): array {
            $jsonOut = $tmp . '/phpdup.json';
            return [
                'argv'     => ['php', $root . '/bin/phpdup', 'analyze', $corpus,
                               '--json', $jsonOut, '--plain', '--min-impact', '0',
                               '--max-df', '0.5', '--no-cache', '--no-incremental'],
                'json_out' => $jsonOut,
            ];
        },
        'parse'   => static function (string $stdout, ?string $jsonOut, array $extra): array {
            if ($jsonOut === null || !is_file($jsonOut)) {
                return ['clusters' => null, 'extra' => $extra];
            }
            $data = json_decode((string)file_get_contents($jsonOut), true);
            $clusters = is_array($data) ? ($data['summary']['clusters'] ?? null) : null;
            $impact   = is_array($data) ? ($data['summary']['total_impact'] ?? null) : null;
            $tags = [];
            if (is_array($data) && isset($data['clusters']) && is_array($data['clusters'])) {
                foreach ($data['clusters'] as $c) {
                    foreach ((array)($c['pattern_tags'] ?? []) as $t) {
                        $tags[$t] = ($tags[$t] ?? 0) + 1;
                    }
                }
            }
            arsort($tags);
            $extra['impact'] = $impact;
            if ($tags !== []) {
                $top = array_slice(array_keys($tags), 0, 3);
                $extra['top_tags'] = implode('/', $top);
            }
            $extra['members'] = is_array($data) ? extractPhpdupMembers($data) : null;
            return ['clusters' => is_int($clusters) ? $clusters : null, 'extra' => $extra];
        },
    ];

    // phpcpd via phar in bench/tools or on PATH.
    $phpcpdPhar = $toolsDir . '/phpcpd.phar';
    $phpcpdBin  = commandPath('phpcpd');
    if (is_file($phpcpdPhar)) {
        $launch = ['php', $phpcpdPhar];
    } elseif ($phpcpdBin !== null) {
        $launch = [$phpcpdBin];
    } else {
        $launch = null;
    }
    if ($launch !== null) {
        $tools[] = [
            'name'    => 'phpcpd',
            'present' => true,
            'cmd_for' => static function (string $corpus, string $tmp) use ($launch): array {
                $xml = $tmp . '/phpcpd.xml';
                return [
                    'argv'     => array_merge($launch, [
                        '--fuzzy',
                        '--min-lines', '5',
                        '--min-tokens', '50',
                        '--log-pmd', $xml,
                        $corpus,
                    ]),
                    'json_out' => $xml,
                ];
            },
            'parse'   => static function (string $stdout, ?string $xmlOut, array $extra): array {
                if ($xmlOut === null || !is_file($xmlOut)) {
                    return ['clusters' => null, 'extra' => $extra];
                }
                $xml = @simplexml_load_string((string)file_get_contents($xmlOut));
                if (!$xml instanceof SimpleXMLElement) {
                    return ['clusters' => null, 'extra' => $extra];
                }
                $clusters = 0;
                $members = [];
                foreach ($xml->duplication as $dup) {
                    $clusters++;
                    $group = [];
                    $lines = (int)$dup['lines'];
                    foreach ($dup->file as $f) {
                        $start = (int)$f['line'];
                        $group[] = [
                            'file'  => (string)$f['path'],
                            'start' => $start,
                            'end'   => $start + max(0, $lines - 1),
                        ];
                    }
                    $members[] = $group;
                }
                $extra['members'] = $members;
                return ['clusters' => $clusters, 'extra' => $extra];
            },
        ];
    }

    // pmd-cpd via system.
    $pmd = commandPath('pmd');
    if ($pmd !== null) {
        $tools[] = [
            'name'    => 'pmd-cpd',
            'present' => true,
            'cmd_for' => static function (string $corpus, string $tmp) use ($pmd): array {
                $xml = $tmp . '/pmd-cpd.xml';
                return [
                    'argv'     => [$pmd, 'cpd', '--language', 'php',
                                   '--minimum-tokens', '50',
                                   '--format', 'xml',
                                   '--dir', $corpus,
                                   '--no-fail-on-violation'],
                    'json_out' => $xml,
                    'capture_stdout_to' => $xml,
                ];
            },
            'parse'   => static function (string $stdout, ?string $xmlOut, array $extra): array {
                if ($xmlOut === null || !is_file($xmlOut)) {
                    return ['clusters' => null, 'extra' => $extra];
                }
                $xml = @simplexml_load_string((string)file_get_contents($xmlOut));
                if (!$xml instanceof SimpleXMLElement) {
                    return ['clusters' => null, 'extra' => $extra];
                }
                $members = [];
                foreach ($xml->duplication as $dup) {
                    $group = [];
                    $lines = (int)$dup['lines'];
                    foreach ($dup->file as $f) {
                        $start = (int)$f['line'];
                        $group[] = [
                            'file'  => (string)$f['path'],
                            'start' => $start,
                            'end'   => $start + max(0, $lines - 1),
                        ];
                    }
                    $members[] = $group;
                }
                $extra['members'] = $members;
                return ['clusters' => count($members), 'extra' => $extra];
            },
        ];
    }

    // jscpd via local node_modules or PATH.
    $jscpd = is_executable($nodeBin . '/jscpd') ? $nodeBin . '/jscpd' : commandPath('jscpd');
    if ($jscpd !== null) {
        $tools[] = [
            'name'    => 'jscpd',
            'present' => true,
            'cmd_for' => static function (string $corpus, string $tmp) use ($jscpd): array {
                $jsonDir = $tmp . '/jscpd';
                @mkdir($jsonDir, 0o775, true);
                return [
                    'argv'     => [$jscpd,
                                   '--formats-exts', 'php:php',
                                   '--reporters', 'json',
                                   '--silent',
                                   '--output', $jsonDir,
                                   $corpus],
                    'json_out' => $jsonDir . '/jscpd-report.json',
                ];
            },
            'parse'   => static function (string $stdout, ?string $jsonOut, array $extra): array {
                if ($jsonOut === null || !is_file($jsonOut)) {
                    return ['clusters' => null, 'extra' => $extra];
                }
                $data = json_decode((string)file_get_contents($jsonOut), true);
                if (!is_array($data)) {
                    return ['clusters' => null, 'extra' => $extra];
                }
                $dups = $data['duplicates'] ?? [];
                $members = [];
                foreach ($dups as $d) {
                    $a = $d['firstFile'] ?? null;
                    $b = $d['secondFile'] ?? null;
                    if (!is_array($a) || !is_array($b)) {
                        continue;
                    }
                    $members[] = [
                        ['file' => (string)($a['name'] ?? ''), 'start' => (int)($a['start'] ?? 0), 'end' => (int)($a['end'] ?? 0)],
                        ['file' => (string)($b['name'] ?? ''), 'start' => (int)($b['start'] ?? 0), 'end' => (int)($b['end'] ?? 0)],
                    ];
                }
                $extra['members'] = $members;
                // jscpd reports pairwise duplicates, not transitive
                // clusters; we keep both views. The "clusters" column
                // is the pair count, which is what the tool calls a
                // "clone".
                return ['clusters' => count($members), 'extra' => $extra];
            },
        ];
    }

    // simian (commercial, rarely present).
    $simian = commandPath('simian');
    if ($simian !== null) {
        $tools[] = [
            'name'    => 'simian',
            'present' => true,
            'cmd_for' => static function (string $corpus, string $tmp) use ($simian): array {
                $log = $tmp . '/simian.txt';
                return [
                    'argv'     => [$simian, '-language=php', '-includes=' . $corpus . '/**/*.php', '-formatter=plain'],
                    'json_out' => $log,
                    'capture_stdout_to' => $log,
                ];
            },
            'parse'   => static function (string $stdout, ?string $logOut, array $extra): array {
                $clusters = preg_match_all('/Found \d+ duplicate lines/', $stdout) ?: null;
                return ['clusters' => is_int($clusters) ? $clusters : null, 'extra' => $extra];
            },
        ];
    }

    return $tools;
}

function commandPath(string $name): ?string
{
    $out = trim((string)@shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
    return $out === '' ? null : $out;
}

/**
 * @param array{name:string, cmd_for:callable, parse:callable} $tool
 * @param array{name:string, path:string} $corpus
 * @return array{tool:string, wall_s:?float, peak_mb:?float, clusters:?int, extra:array<string,mixed>, error:?string}
 */
function runTool(array $tool, array $corpus, int $timeoutSec): array
{
    $tmp = sys_get_temp_dir() . '/phpdup-bench-' . bin2hex(random_bytes(4));
    @mkdir($tmp, 0o775, true);

    /** @var array{argv:list<string>, json_out:?string, capture_stdout_to?:string} $cfg */
    $cfg = ($tool['cmd_for'])($corpus['path'], $tmp);
    $argv = $cfg['argv'];
    $jsonOut = $cfg['json_out'] ?? null;

    // Wrap command in `timeout` + `/usr/bin/time -v` so we can pull
    // peak RSS without a portable PHP-level alternative.
    $stderr = $tmp . '/stderr.log';
    $stdout = $cfg['capture_stdout_to'] ?? ($tmp . '/stdout.log');
    $cmd = sprintf(
        'timeout %d /usr/bin/time -v %s > %s 2> %s',
        $timeoutSec,
        implode(' ', array_map('escapeshellarg', $argv)),
        escapeshellarg($stdout),
        escapeshellarg($stderr),
    );
    $start = microtime(true);
    $rc = 0;
    @exec($cmd, $_, $rc);
    $wall = microtime(true) - $start;

    $stderrText = (string)@file_get_contents($stderr);
    $stdoutText = (string)@file_get_contents($stdout);

    $peakMb = null;
    if (preg_match('/Maximum resident set size \(kbytes\): (\d+)/', $stderrText, $m)) {
        $peakMb = round(((int)$m[1]) / 1024, 1);
    }

    $extra = [];
    $error = null;
    if ($rc === 124) {
        $error = "timeout-{$timeoutSec}s";
    } elseif ($rc !== 0 && $rc !== 1) {
        // exit 1 is often "found duplicates" — not an error.
        $error = "exit-{$rc}";
    }
    $parsed = ($tool['parse'])($stdoutText, $jsonOut, $extra);

    // Cleanup temp output files but keep the json_out's data already
    // parsed; the dir itself goes.
    @passthru('rm -rf ' . escapeshellarg($tmp));

    return [
        'tool'     => $tool['name'],
        'wall_s'   => round($wall, 3),
        'peak_mb'  => $peakMb,
        'clusters' => $parsed['clusters'],
        'extra'    => $parsed['extra'] ?? [],
        'error'    => $error,
    ];
}

/**
 * @param array<string, mixed> $report
 * @return list<list<array{file:string,start:int,end:int}>>
 */
function extractPhpdupMembers(array $report): array
{
    $clusters = $report['clusters'] ?? [];
    if (!is_array($clusters)) {
        return [];
    }
    $out = [];
    foreach ($clusters as $c) {
        $members = $c['members'] ?? [];
        if (!is_array($members)) {
            continue;
        }
        $group = [];
        foreach ($members as $m) {
            $group[] = [
                'file'  => (string)($m['file'] ?? ''),
                'start' => (int)($m['start_line'] ?? $m['start'] ?? 0),
                'end'   => (int)($m['end_line']   ?? $m['end']   ?? 0),
            ];
        }
        if ($group !== []) {
            $out[] = $group;
        }
    }
    return $out;
}
