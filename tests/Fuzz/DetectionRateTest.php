<?php
declare(strict_types=1);

namespace Phpdup\Tests\Fuzz;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use Phpdup\Testing\BenchmarkScoreCalculator;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Detection-rate floor over the canonical SYNTHETIC BENCH CORPUS
 * (php-dup-finder-once-108: the previous "detection rate" test measured
 * no rate — ≥1 cluster over three generated files, ground truth never
 * read, precision uncomputed).
 *
 * What "measures something" means here:
 *  - the corpus is the bench planter's own output (imported from
 *    bench/corpora.php under PHPDUP_CORPORA_LIB — the exact code the
 *    nightly bench ships, no second dialect of fixtures);
 *  - the ground-truth manifest is READ and validated (4 clone families
 *    × 5 members + negative controls, corpus-relative paths only — the
 *    portability fence that stops absolute /home paths silently
 *    zero-scoring foreign checkouts);
 *  - the planter must reproduce the committed manifest byte-for-byte
 *    and the generated corpus must match its digests in
 *    bench/corpus-pins.txt (supply-chain fence for this file);
 *  - the FULL pipeline runs over the corpus and the report is scored
 *    with the same family-granular, containment-aware calculator the
 *    bench itself uses ({@see BenchmarkScoreCalculator}), so recall,
 *    precision and F1 are REAL computed rates with non-empty
 *    denominators on both sides.
 *
 * Floors below are measured on the live tree 2026-11-22 (24 files /
 * 69 blocks / 10 clusters): recall 1.00 (4/4 families), precision 1.00
 * (0 false positives — clusters over the negative controls would pay
 * here), F1 1.00. They keep headroom for scoring-rule shifts but are
 * NOT vacuous: breaking the extractor (e.g. emptying
 * BlockExtractor::ALL_KINDS in a scratch copy) collapses recall to 0.00
 * and this test reddens — mutation-proven, see
 * reviews/exec/once-S86.impl.md.
 *
 * @group fuzz
 */
final class DetectionRateTest extends TestCase
{
    /** Minimum share of ground-truth families a run must detect. */
    private const RECALL_FLOOR = 0.90;
    /** Maximum tolerance for clusters unexplainable by any family. */
    private const PRECISION_FLOOR = 0.85;
    /** Combined bar on the same score tuple. */
    private const F1_FLOOR = 0.85;

    public function testGroundTruthManifestIsRealAndPortable(): void
    {
        $gtFile = self::repoPath('bench/corpora/synthetic-fuzz/.ground-truth.json');
        self::assertFileExists($gtFile, 'committed ground truth must ship with the repo');
        $gt = json_decode((string)file_get_contents($gtFile), true);
        self::assertIsArray($gt);

        $clusters = $gt['clusters'] ?? null;
        self::assertIsArray($clusters, 'manifest must carry the ground-truth clusters');
        self::assertCount(4, $clusters, 'planter plants exactly four clone families');

        $seenFiles = [];
        foreach ($clusters as $i => $family) {
            self::assertIsArray($family);
            self::assertCount(5, $family, "family {$i} must plant five members");
            foreach ($family as $member) {
                $file = (string)($member['file'] ?? '');
                // Portability fence: corpus-relative plain filenames only.
                // An absolute or host-coupled path here would silently
                // zero-score every other checkout (once-108).
                self::assertNotSame('', $file);
                self::assertStringNotContainsString('/', $file, "GT path '{$file}' must be corpus-relative");
                self::assertStringNotContainsString('\\', $file);
                self::assertGreaterThan(0, (int)($member['start'] ?? 0));
                self::assertGreaterThanOrEqual((int)$member['start'], (int)$member['end']);
                $seenFiles[$file] = true;
            }
        }
        self::assertCount(20, $seenFiles, 'families must cover 20 distinct files');

        $negatives = $gt['negative_files'] ?? null;
        self::assertIsArray($negatives);
        self::assertNotEmpty($negatives, 'corpus needs negative controls for precision to have teeth');
        foreach ($negatives as $neg) {
            self::assertStringNotContainsString('/', (string)$neg);
            self::assertArrayNotHasKey((string)$neg, $seenFiles, 'negative controls carry no true clone class');
        }
    }

    public function testPlanterReproducesTheCommittedCorpusAndPins(): void
    {
        self::corporaLib();
        $committedGt = (string)file_get_contents(self::repoPath('bench/corpora/synthetic-fuzz/.ground-truth.json'));
        $pins = readPins(self::repoPath('bench/corpus-pins.txt'));
        $pin = $pins['synthetic-fuzz'] ?? [];
        self::assertNotEmpty($pin, 'bench/corpus-pins.txt must pin the synthetic corpus');

        $dir = self::tempDir();
        try {
            self::plantSyntheticCorpus($dir);
            self::assertSame(
                $committedGt,
                (string)file_get_contents($dir . '/.ground-truth.json'),
                'planter drift: regenerated ground truth differs from the committed manifest '
                    . '(update bench/corpora/synthetic-fuzz/.ground-truth.json AND bench/corpus-pins.txt together, deliberately)'
            );
            self::assertSame($pin['ground-truth-sha256'], hash_file('sha256', $dir . '/.ground-truth.json'));
            self::assertSame($pin['php-filelist-sha256'], phpFileListDigest($dir));
            self::assertSame($pin['php-content-sha256'], phpContentDigest($dir));
            self::assertSame(24, count(collectPhpPaths($dir)));
        } finally {
            self::removeDir($dir);
        }
    }

    public function testDetectionRateMeetsRecallAndPrecisionFloors(): void
    {
        $dir = self::tempDir();
        try {
            self::plantSyntheticCorpus($dir);

            $manifest = json_decode((string)file_get_contents($dir . '/.ground-truth.json'), true);
            self::assertIsArray($manifest);
            $groundTruth = array_map(
                static fn(array $family): array => array_map(
                    static fn(array $m): array => [
                        'file'  => basename((string)$m['file']),
                        'start' => (int)$m['start'],
                        'end'   => (int)$m['end'],
                    ],
                    $family
                ),
                $manifest['clusters']
            );

            $report = self::analyze($dir);
            self::assertSame(24, $report->files, 'corpus must be fully scanned');
            self::assertGreaterThan(0, $report->blocks, 'extractor must produce blocks to score');
            self::assertSame(0, $report->parseErrors, 'corpus files must parse cleanly');

            $reported = array_map(
                static fn($cluster): array => array_map(
                    static fn($block): array => [
                        'file'  => basename($block->file),
                        'start' => $block->range->start,
                        'end'   => $block->range->end,
                    ],
                    $cluster->members
                ),
                $report->clusters
            );

            $score = (new BenchmarkScoreCalculator())->score($reported, $groundTruth);

            // Rates must be REAL: both denominators non-zero, every report
            // accounted for as TP or FP, every family as detected or missed.
            self::assertNotEmpty($reported, 'phpdup reported zero clusters on planted type-2 clones');
            self::assertCount(4, $groundTruth);
            self::assertGreaterThanOrEqual(self::RECALL_FLOOR, $score['recall'], sprintf(
                'family recall %.2f (%d/%d detected, %d missed) below floor %.2f — detection regression',
                $score['recall'],
                count($groundTruth) - $score['fn'],
                count($groundTruth),
                $score['fn'],
                self::RECALL_FLOOR
            ));
            self::assertGreaterThanOrEqual(self::PRECISION_FLOOR, $score['precision'], sprintf(
                'precision %.2f (%d/%d clusters explainable) below floor %.2f — false-positive regression',
                $score['precision'],
                $score['tp'],
                count($reported),
                self::PRECISION_FLOOR
            ));
            self::assertGreaterThanOrEqual(self::F1_FLOOR, $score['f1'], sprintf(
                'F1 %.2f below floor %.2f (P %.2f / R %.2f)',
                $score['f1'],
                self::F1_FLOOR,
                $score['precision'],
                $score['recall']
            ));
        } finally {
            self::removeDir($dir);
        }
    }

    /** Import the bench planter/pin helpers without running materialization. */
    private static function corporaLib(): void
    {
        if (!defined('PHPDUP_CORPORA_LIB')) {
            define('PHPDUP_CORPORA_LIB', true);
        }
        require_once self::repoPath('bench/corpora.php');
    }

    private static function plantSyntheticCorpus(string $dir): void
    {
        self::corporaLib();
        ob_start();
        try {
            generateSynthetic($dir);
        } finally {
            ob_end_clean();
        }
    }

    private static function repoPath(string $rel): string
    {
        return dirname(__DIR__, 2) . '/' . $rel;
    }

    private static function tempDir(): string
    {
        return sys_get_temp_dir() . '/phpdup-s86-fuzz-' . getmypid() . '-' . substr(hash('sha256', uniqid('', true)), 0, 10);
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            @unlink($dir . '/' . $entry);
        }
        @rmdir($dir);
    }

    private static function analyze(string $dir): Report
    {
        $config = new Config(
            paths: [$dir],
            exclude: Config::defaults([])->exclude,
            minBlockSize: 4,
            minClusterImpact: 0,
            maxDocumentFrequency: 0.5,
            lazyAst: false,
        );
        $state = new PipelineState($config);
        $out   = new NullOutput();
        (new ScanningStage())->run($state, $out);
        (new PreprocessStage(useCache: false))->run($state, $out);
        (new ClusterStage(exactOnly: false, useClusterCache: false))->run($state, $out);
        (new RefactorStage(useCache: false))->run($state, $out);
        return new Report(
            files: count($state->files),
            blocks: count($state->blocks),
            parseErrors: $state->parseErrors,
            clusters: (new Ranker(0))->rank($state->clusters),
            config: $config,
        );
    }
}
