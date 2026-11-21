<?php
/**
 * Detection-rate scoring for the synthetic-fuzz corpus.
 *
 * Reads `bench/corpora/synthetic-fuzz/.ground-truth.json` — one
 * ground-truth cluster per planted clone family — and the latest run
 * JSON under `bench/results/`, then computes per-tool precision /
 * recall / F1 and writes a markdown summary to
 * `bench/results/detection-rate.md`.
 *
 * Scoring is family-granular and containment-aware (see
 * {@see \Phpdup\Testing\BenchmarkScoreCalculator}): a reported cluster
 * is a true positive when OVERLAP_FLOOR of its members are explainable
 * by one family (matched/nested/covers under the advertised ±2-line
 * rule — nested genuine detections are not unconditional FPs), and a
 * family is detected when one cluster tolerance-matches OVERLAP_FLOOR
 * of its planted occurrences one-to-one. Competitor output arrives
 * pair-grained and is already closed into families by bench/run.php;
 * `negative_files` in the manifest are planted code with no true clone
 * class — clusters over them are unexplainable and pay the precision
 * price. All member paths are canonicalized to corpus-relative form
 * (ground truth is stored relative; tools emit absolute paths).
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
$groundTruth = [];
if (is_array($gt) && isset($gt['clusters']) && is_array($gt['clusters'])) {
    foreach ($gt['clusters'] as $family) {
        $groundTruth[] = normalizeGroup($family);
    }
}

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
            'file'  => corpusRelative((string)($m['file'] ?? '')),
            'start' => (int)($m['start'] ?? 0),
            'end'   => (int)($m['end'] ?? 0),
        ];
    }
    return $out;
}

/**
 * Canonicalize a member path against the (flat) synthetic corpus dir:
 * ground truth is stored corpus-relative, tools emit whatever they were
 * handed (absolute scan paths, cwd-relative paths, basenames). Stripping
 * up to the last `synthetic-fuzz/` segment collapses the absolute and
 * dir-relative forms; anything without the marker in a flat corpus is a
 * basename already.
 */
function corpusRelative(string $path): string
{
    $marker = 'synthetic-fuzz/';
    $pos = strrpos($path, $marker);
    if ($pos !== false) {
        return substr($path, $pos + strlen($marker));
    }
    return basename($path);
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
