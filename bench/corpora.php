<?php
/**
 * Corpus discovery & download for the bench harness.
 *
 * Fetches a curated set of public PHP repos (shallow clone) into
 * `bench/corpora/`. Each entry caps wall time individually and the
 * whole script obeys an overall 5-minute budget so it never hangs
 * CI. Already-downloaded corpora are skipped.
 *
 * Synthetic corpus is always (re)generated via
 * `Phpdup\Testing\FuzzCorpusGenerator` and a manifest of the
 * intended duplication topology is written next to the files so
 * `bench/score.php` can recover ground truth.
 *
 * Usage:
 *   php bench/corpora.php           # download missing, regenerate synthetic
 *   php bench/corpora.php --list    # print the planned corpora and exit
 *   php bench/corpora.php --refresh # force re-clone everything
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Phpdup\Testing\FuzzCorpusGenerator;

$corporaDir = dirname(__DIR__) . '/bench/corpora';
@mkdir($corporaDir, 0o775, true);

/** @var list<array{name:string, url:string, ref:string, sparse:?string, max_mb:int}> $plan */
$plan = [
    [
        'name'   => 'symfony-console',
        'url'    => 'https://github.com/symfony/console.git',
        'ref'    => 'v6.4.0',
        'sparse' => null,
        'max_mb' => 50,
    ],
    [
        'name'   => 'phpunit',
        'url'    => 'https://github.com/sebastianbergmann/phpunit.git',
        'ref'    => '11.5.0',
        'sparse' => 'src',
        'max_mb' => 50,
    ],
    [
        'name'   => 'laravel-framework-illuminate-http',
        'url'    => 'https://github.com/laravel/framework.git',
        'ref'    => 'v11.0.0',
        'sparse' => 'src/Illuminate/Http',
        'max_mb' => 30,
    ],
    [
        'name'   => 'wordpress-core-wp-includes',
        'url'    => 'https://github.com/WordPress/WordPress.git',
        'ref'    => '6.4.2',
        'sparse' => 'wp-includes',
        'max_mb' => 100,
    ],
];

if (in_array('--list', $argv, true)) {
    foreach ($plan as $p) {
        printf("%-40s %s @ %s%s\n", $p['name'], $p['url'], $p['ref'], $p['sparse'] ? " (sparse: {$p['sparse']})" : '');
    }
    echo "synthetic-fuzz                           (generated locally)\n";
    exit(0);
}

$forceRefresh = in_array('--refresh', $argv, true);
$globalBudget = 300; // seconds
$globalStart  = microtime(true);

foreach ($plan as $p) {
    $target = $corporaDir . '/' . $p['name'];
    if (!$forceRefresh && is_dir($target) && countPhpFiles($target) > 0) {
        echo "[skip] {$p['name']} already present\n";
        continue;
    }
    if ((microtime(true) - $globalStart) > $globalBudget) {
        echo "[budget] global download budget exhausted; stopping\n";
        break;
    }
    if ($forceRefresh && is_dir($target)) {
        passthru('rm -rf ' . escapeshellarg($target));
    }
    cloneRepo($p, $target, $globalBudget - (int)(microtime(true) - $globalStart));
}

generateSynthetic($corporaDir . '/synthetic-fuzz');

echo "[ok] corpora ready under {$corporaDir}\n";

/**
 * @param array{name:string, url:string, ref:string, sparse:?string, max_mb:int} $p
 */
function cloneRepo(array $p, string $target, int $budget): void
{
    $budget = max(10, $budget);
    echo "[clone] {$p['name']} ← {$p['url']} @ {$p['ref']} (≤{$budget}s)\n";
    $tmp = $target . '.tmp';
    @passthru('rm -rf ' . escapeshellarg($tmp));
    $cmd = sprintf(
        'timeout %d git clone --depth 1 --branch %s %s %s 2>&1',
        $budget,
        escapeshellarg($p['ref']),
        escapeshellarg($p['url']),
        escapeshellarg($tmp),
    );
    $rc = 0;
    passthru($cmd, $rc);
    if ($rc !== 0 || !is_dir($tmp)) {
        echo "[fail] {$p['name']} clone exit {$rc} — skipping\n";
        @passthru('rm -rf ' . escapeshellarg($tmp));
        return;
    }
    if ($p['sparse'] !== null) {
        $src = $tmp . '/' . $p['sparse'];
        if (!is_dir($src)) {
            echo "[warn] sparse path {$p['sparse']} missing in {$p['name']}; using whole repo\n";
            $src = $tmp;
        }
        @mkdir($target, 0o775, true);
        passthru('cp -r ' . escapeshellarg($src) . '/. ' . escapeshellarg($target));
        passthru('rm -rf ' . escapeshellarg($tmp));
    } else {
        rename($tmp, $target);
    }
    // Strip git metadata to keep the corpus directory diff-clean.
    passthru('rm -rf ' . escapeshellarg($target . '/.git'));
    $sizeMb = (int)round(diskUsageBytes($target) / (1024 * 1024));
    echo "[ok] {$p['name']} ({$sizeMb} MB, " . countPhpFiles($target) . " PHP files)\n";
    if ($sizeMb > $p['max_mb']) {
        echo "[warn] {$p['name']} exceeded soft cap {$p['max_mb']} MB\n";
    }
}

function countPhpFiles(string $dir): int
{
    if (!is_dir($dir)) {
        return 0;
    }
    $rc = 0;
    $out = [];
    exec('find ' . escapeshellarg($dir) . ' -type f -name "*.php" | wc -l', $out, $rc);
    return (int)trim($out[0] ?? '0');
}

function diskUsageBytes(string $dir): int
{
    $out = [];
    @exec('du -sb ' . escapeshellarg($dir) . ' 2>/dev/null', $out);
    if ($out === []) {
        return 0;
    }
    return (int)trim(explode("\t", $out[0])[0]);
}

function generateSynthetic(string $dir): void
{
    if (is_dir($dir)) {
        passthru('rm -rf ' . escapeshellarg($dir));
    }
    @mkdir($dir, 0o775, true);
    // Plan: 4 templates, 5 instantiations each → 20 files,
    // each template instantiation is one cluster of 5 members.
    $plan = [];
    foreach (['alpha', 'beta', 'gamma', 'delta'] as $template) {
        $plan[$template] = [];
        for ($i = 0; $i < 5; $i++) {
            $plan[$template][] = [
                'value'     => (string)($i * 7 + 3),
                'threshold' => (string)($i * 11 + 1),
                'callee'    => 'doThing' . chr(65 + $i),
            ];
        }
    }
    $manifest = (new FuzzCorpusGenerator(seed: 1337))->generate($dir, $plan);
    // The current FuzzCorpusGenerator emits a fixed-shape `process()`
    // method regardless of template name — only literals/identifiers
    // change. All 20 instantiations are therefore one type-2 cluster.
    // We record the expected line range (8-18 in the rendered file —
    // see FuzzCorpusGenerator::renderTemplate) for every member.
    $allMembers = [];
    foreach ($manifest as $entry) {
        $allMembers[] = [
            'file'  => $entry['file'],
            'start' => 8,
            'end'   => 18,
        ];
    }
    $groundTruth = $allMembers === [] ? [] : [$allMembers];
    file_put_contents(
        $dir . '/.ground-truth.json',
        json_encode([
            'description' => 'Synthetic fuzz corpus — every generated file body is a clone of every other under type-2 normalization.',
            'clusters'    => $groundTruth,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    );
    echo "[ok] synthetic-fuzz generated (" . count($manifest) . " files, " . count($groundTruth) . " ground-truth clusters)\n";
}
