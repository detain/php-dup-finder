<?php
/**
 * Detection-rate scoring for the synthetic-fuzz corpus.
 *
 * Reads `bench/corpora/synthetic-fuzz/.ground-truth.json` and the
 * latest run JSON under `bench/results/`, then computes per-tool
 * precision / recall / F1 and writes a markdown summary to
 * `bench/results/detection-rate.md`.
 *
 * Two clusters match when their member sets overlap by Jaccard
 * ≥ 0.6 with a ±2-line tolerance on block boundaries (see
 * {@see \Phpdup\Testing\BenchmarkScoreCalculator}).
 *
 * Usage:
 *   php bench/score.php                           # latest run
 *   php bench/score.php bench/results/run-X.json  # specific run
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Phpdup\Testing\BenchmarkResultsRenderer;
use Phpdup\Testing\BenchmarkScoreCalculator;

$root = dirname(__DIR__);
$resultsDir = $root . '/bench/results';
$gtFile = $root . '/bench/corpora/synthetic-fuzz/.ground-truth.json';

if (!is_file($gtFile)) {
    fwrite(STDERR, "[skip] no ground-truth manifest at {$gtFile} — run bench/corpora.php first\n");
    file_put_contents($resultsDir . '/detection-rate.md', (new BenchmarkResultsRenderer())->renderDetectionRateMarkdown([]));
    exit(0);
}

$gt = json_decode((string)file_get_contents($gtFile), true);
$groundTruth = is_array($gt) && isset($gt['clusters']) && is_array($gt['clusters'])
    ? $gt['clusters']
    : [];

$runFile = $argv[1] ?? findLatestRun($resultsDir);
if ($runFile === null || !is_file($runFile)) {
    fwrite(STDERR, "[skip] no run JSON found under {$resultsDir}\n");
    file_put_contents($resultsDir . '/detection-rate.md', (new BenchmarkResultsRenderer())->renderDetectionRateMarkdown([]));
    exit(0);
}
echo "[info] scoring against {$runFile}\n";

$run = json_decode((string)file_get_contents($runFile), true);
if (!is_array($run) || !isset($run['rows']) || !is_array($run['rows'])) {
    fwrite(STDERR, "[err] run JSON shape unexpected\n");
    exit(2);
}

$calc = new BenchmarkScoreCalculator();
$scores = [];
foreach ($run['rows'] as $row) {
    if (($row['corpus'] ?? '') !== 'synthetic-fuzz') {
        continue;
    }
    $reported = $row['extra']['members'] ?? [];
    if (!is_array($reported)) {
        $reported = [];
    }
    $reported = array_map(static fn($g): array => normalizeGroup($g), $reported);
    $score = $calc->score($reported, $groundTruth);
    $scores[] = [
        'tool'         => (string)$row['tool'],
        'precision'    => $score['precision'],
        'recall'       => $score['recall'],
        'f1'           => $score['f1'],
        'tp'           => $score['tp'],
        'fp'           => $score['fp'],
        'fn'           => $score['fn'],
        'reported'     => count($reported),
        'ground_truth' => count($groundTruth),
    ];
}

$out = (new BenchmarkResultsRenderer())->renderDetectionRateMarkdown($scores);
file_put_contents($resultsDir . '/detection-rate.md', $out);
echo "[ok] wrote {$resultsDir}/detection-rate.md\n";

/** @return list<array{file:string, start:int, end:int}> */
function normalizeGroup($g): array
{
    if (!is_array($g)) {
        return [];
    }
    $out = [];
    foreach ($g as $m) {
        if (!is_array($m)) {
            continue;
        }
        $out[] = [
            'file'  => (string)($m['file'] ?? ''),
            'start' => (int)($m['start'] ?? 0),
            'end'   => (int)($m['end'] ?? 0),
        ];
    }
    return $out;
}

function findLatestRun(string $dir): ?string
{
    if (!is_dir($dir)) {
        return null;
    }
    $files = glob($dir . '/run-*.json') ?: glob($dir . '/*.json') ?: [];
    if ($files === []) {
        return null;
    }
    usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    return $files[0];
}
