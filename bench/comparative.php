<?php
/**
 * phpdup vs alternatives — wall time, peak RSS, cluster count.
 *
 * Usage:
 *   php bench/comparative.php <path-to-corpus>
 *
 * Tools probed (skipped if missing):
 *   - phpdup           (this repo)
 *   - phpcpd           (sebastianbergmann/phpcpd)
 *   - phpmd-cpd        (PMD's PHP CPD via the `pmd` jar)
 *
 * Renders a markdown table to stdout. The phpdup row is always
 * included; alternative tools that aren't on $PATH show '—' so
 * the harness still produces a comparable snapshot.
 */
declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: php bench/comparative.php <corpus-dir>\n");
    exit(2);
}
$corpus = $argv[1];
if (!is_dir($corpus)) {
    fwrite(STDERR, "corpus not found: {$corpus}\n");
    exit(2);
}

$root = dirname(__DIR__);
$rows = [];

$rows[] = runTool(
    name: 'phpdup',
    cmd:  ['php', $root . '/bin/phpdup', 'analyze', $corpus, '--json', tmp('phpdup'), '--plain', '--min-impact', '0'],
    parseClusters: static fn(string $stdout, string $jsonFile)
        => is_file($jsonFile) ? (json_decode((string)file_get_contents($jsonFile), true)['summary']['clusters'] ?? null) : null,
    jsonFile: tmp('phpdup'),
);

if (commandExists('phpcpd')) {
    $rows[] = runTool(
        name: 'phpcpd',
        cmd:  ['phpcpd', '--no-progress-bar', $corpus],
        parseClusters: static function (string $stdout) {
            // phpcpd prints "Found N exact clones …"
            return preg_match('/Found (\d+) /', $stdout, $m) ? (int)$m[1] : null;
        },
    );
} else {
    $rows[] = ['name' => 'phpcpd', 'wall_s' => '—', 'peak_mb' => '—', 'clusters' => '—'];
}

if (commandExists('pmd')) {
    $rows[] = runTool(
        name: 'pmd-cpd',
        cmd:  ['pmd', 'cpd', '--language', 'php', '--minimum-tokens', '50', '--dir', $corpus],
        parseClusters: static function (string $stdout) {
            return substr_count($stdout, '=====================================================================');
        },
    );
} else {
    $rows[] = ['name' => 'pmd-cpd', 'wall_s' => '—', 'peak_mb' => '—', 'clusters' => '—'];
}

echo "| Tool | Wall (s) | Peak RSS (MB) | Clusters |\n";
echo "|---|---:|---:|---:|\n";
foreach ($rows as $r) {
    printf("| %s | %s | %s | %s |\n", $r['name'], $r['wall_s'], $r['peak_mb'], $r['clusters']);
}

/**
 * @param list<string> $cmd
 * @param callable(string,string=): ?int $parseClusters
 * @return array{name:string, wall_s:string, peak_mb:string, clusters:string}
 */
function runTool(string $name, array $cmd, callable $parseClusters, ?string $jsonFile = null): array
{
    $start = microtime(true);
    $tmp = tempnam(sys_get_temp_dir(), 'phpdup-bench-');
    $cmdline = '/usr/bin/time -v ' . implode(' ', array_map('escapeshellarg', $cmd)) . ' > ' . escapeshellarg($tmp) . ' 2>&1';
    @shell_exec($cmdline);
    $wall = microtime(true) - $start;
    $output = (string)@file_get_contents($tmp);
    @unlink($tmp);

    $peakMb = preg_match('/Maximum resident set size \(kbytes\): (\d+)/', $output, $m)
        ? round(((int)$m[1]) / 1024, 1)
        : '—';
    $clusters = $jsonFile === null
        ? $parseClusters($output)
        : $parseClusters($output, $jsonFile);

    return [
        'name'     => $name,
        'wall_s'   => sprintf('%.2f', $wall),
        'peak_mb'  => is_string($peakMb) ? $peakMb : (string)$peakMb,
        'clusters' => $clusters === null ? '—' : (string)$clusters,
    ];
}

function commandExists(string $name): bool
{
    return @shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null') !== null
        && trim((string)shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null')) !== '';
}

function tmp(string $tag): string
{
    return sys_get_temp_dir() . "/phpdup-bench-{$tag}-" . getmypid() . '.json';
}
