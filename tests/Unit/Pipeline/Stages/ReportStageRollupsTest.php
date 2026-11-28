<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Pipeline\Stages;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ReportStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Reporting\JsonReporter;
use Phpdup\Reporting\JsonSchemaSpec;
use Phpdup\Reporting\Ranker;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Item 2.2 (ROLLUP): the corpus-awareness demand at the stage seam —
 * the idiom_census family (PreprocessStageCensusIngestTest §5) mirrored
 * onto the report-stage leg:
 *
 *  1. demand-off inertness — unarmed runs never construct the analyzer
 *     payload (rollups stays null through state->report, and the JSON
 *     summary carries NO key: the frozen-goldens posture from the
 *     other side of the seam);
 *  2. armed carry — the FULL ranked cluster set feeds the analyzer, the
 *     payload rides the §5f trailing-param seam into the Report, and
 *     JsonReporter appends the conditional block after idiom_census in
 *     the declared spec order (summaryShape(false, true) /
 *     rollupBlockShape());
 *  3. the f08 demotion exemption, measured end to end: a cluster of two
 *     HARD-demoted DTO serializers links nothing into the twin
 *     numerator — the pair exists as a cluster and is STILL not a twin
 *     file pair (a mega-cluster of property shufflers cannot fake the
 *     corpus into looking mirrored);
 *  4. an armed run over an empty corpus is the zero-shaped block, never
 *     null — "measured and empty" vs "not measured" stay distinguishable
 *     for consumers.
 */
final class ReportStageRollupsTest extends TestCase
{
    private const SHARED_BODY = <<<'PHP'
        $slug = strtolower(trim($key));
        if ($slug === '') { $slug = 'anon'; }
        return $slug;
    PHP;

    /** @var list<string> */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            ) as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($dir);
        }
        $this->tmpDirs = [];
    }

    /**
     * @param array<string,string> $files filename => contents
     * @param array<string,mixed>  $configOverrides
     */
    private function drive(array $files, array $configOverrides): PipelineState
    {
        $dir = sys_get_temp_dir() . '/phpdup_rollupstage_' . uniqid('', true);
        mkdir($dir, 0o775, true);
        $this->tmpDirs[] = $dir;
        foreach ($files as $name => $body) {
            file_put_contents($dir . '/' . $name, $body);
        }
        $config = new Config(...array_merge([
            'paths'            => [$dir],
            'exclude'          => [],
            'lazyAst'          => false,
            'minClusterImpact' => 1,
        ], $configOverrides));
        $state = new PipelineState($config);
        $out = new NullOutput();
        (new ScanningStage())->run($state, $out);
        (new PreprocessStage(useCache: false))->run($state, $out);
        (new ClusterStage(exactOnly: false, useClusterCache: false))->run($state, $out);
        (new RefactorStage(useCache: false))->run($state, $out);
        (new ReportStage(50, false))->run($state, $out);
        return $state;
    }

    /** @return array<string,string> the two-file mirror twin corpus */
    private function twinCorpus(): array
    {
        return [
            'gamma.php' => "<?php\nclass Gamma {\n    public function left(string \$key): string {\n        "
                . str_replace("\n", "\n        ", self::SHARED_BODY) . "\n    }\n}\n",
            'delta.php' => "<?php\nclass Delta {\n    public function right(string \$key): string {\n        "
                . str_replace("\n", "\n        ", self::SHARED_BODY) . "\n    }\n}\n",
        ];
    }

    public function testUnarmedRunCarriesNoRollupsThroughTheWholeSeam(): void
    {
        $state = $this->drive($this->twinCorpus(), ['corpusRollups' => false]);
        $this->assertNotNull($state->report);
        $this->assertNull($state->report->rollups, 'the analyzer must not run unarmed');
        $summary = (new JsonReporter())->build($state->report)['summary'];
        $this->assertArrayNotHasKey('rollups', $summary);
        $this->assertSame(array_keys(JsonSchemaSpec::summaryShape(false)), array_keys($summary));
    }

    public function testArmedRunCarriesRollupsIntoReportAndSummaryInSpecOrder(): void
    {
        $state = $this->drive($this->twinCorpus(), ['corpusRollups' => true]);
        $rollups = $state->report->rollups;
        $this->assertIsArray($rollups, 'the armed run must carry a payload');
        $this->assertSame(array_keys(JsonSchemaSpec::rollupBlockShape()), array_keys($rollups));

        $totals = $rollups['totals'];
        $this->assertSame(2, $totals['files']);
        $this->assertSame(1, $totals['twin_file_pairs'], 'the linked mirror pair is a twin file pair');
        $this->assertSame(0, $totals['declared_only_pairs']);
        $this->assertCount(2, $rollups['top_files']);

        // Distinct basenames in distinct classes: the twin can only be
        // LINKED (cluster-carried coverage), never seed-sourced.
        $pair = $rollups['twin_pairs'][0];
        $this->assertFalse($pair['seed_direct']);
        $this->assertSame(['delta.php', 'gamma.php'], [basename($pair['a_path']), basename($pair['b_path'])]);
        // TWO species link the pair: the shared method block AND the
        // identically-shaped class block (default kinds extract both) —
        // both clusters carry members on each side, so the numerator map
        // sees two ids.
        $this->assertCount(2, $pair['linked_cluster_ids']);
        // Same-range twins measure full coverage; Num::json collapses
        // whole floats to int (1.0 -> 1) — pinned form on purpose.
        $this->assertSame(1, $pair['a_to_b']);
        $this->assertSame(1, $pair['b_to_a']);
        $this->assertSame(1, $pair['coverage']);

        $summary = (new JsonReporter())->build($state->report)['summary'];
        $this->assertSame(
            array_merge(array_keys(JsonSchemaSpec::summaryShape(false)), ['rollups']),
            array_keys($summary),
            'armed summary = default key set + trailing rollups (census not armed here)',
        );
        $this->assertSame(array_keys(JsonSchemaSpec::summaryShape(false, true)), array_keys($summary));
    }

    public function testDemotedDtoClusterIsExemptFromTheArmedTwinNumerator(): void
    {
        $dto = static fn(string $cls): string => "<?php\nclass $cls {\n"
            . "    public function toArray(): array {\n        return [\"a\" => \$this->a, \"b\" => \$this->b];\n    }\n}\n";
        $state = $this->drive(
            ['mirror_a.php' => $dto('MirrorA'), 'mirror_b.php' => $dto('MirrorB')],
            ['corpusRollups' => true, 'allowedKinds' => ['method'], 'minBlockSize' => 1],
        );
        $rollups = $state->report->rollups;
        $this->assertIsArray($rollups);
        $clusterCount = (new JsonReporter())->build($state->report)['summary']['clusters'];
        // The evidence exists as a cluster (the pair DID link) and is
        // still exempt from the numerator (f08 §2: demoted clusters
        // measure nothing). Ranker::DEMOTION_HARD is what exempts it.
        $this->assertGreaterThanOrEqual(1, $clusterCount, 'the DTO pair must still cluster (evidence present, exemption proven)');
        $this->assertSame(0, $rollups['totals']['twin_file_pairs'], 'a 2×toArray mega-twin cannot fake the corpus into mirrored posture');
        $this->assertSame([], $rollups['twin_pairs']);
        $this->assertSame(0, $rollups['totals']['direct_comparisons'], 'different basenames never seed a rescue compare');
        $this->assertSame(2, $rollups['totals']['files'], 'both files are still counted in the census');
        $factor = (new Ranker(1))->declarativeDemotion($state->clusters[0]);
        $this->assertSame(Ranker::DEMOTION_HARD, $factor, 'the exemption rides exactly the shipped hard constant');
    }

    public function testArmedFilesWithoutBlocksIsTheZeroBlockNeverNull(): void
    {
        // ReportStage short-circuits "scanner found no files" before any
        // mount (the item-0.6 guard — no Report exists at all), so the
        // zero-shape is proven at the honest floor: files present, no
        // comparable blocks anywhere. Measured-and-empty still carries
        // the block; only UNARMED omits the key.
        $state = $this->drive(
            ['plain.php' => "<?php\ndefine('X', 1);\n"],
            ['corpusRollups' => true],
        );
        $rollups = $state->report->rollups;
        $this->assertIsArray($rollups);
        $this->assertSame(1, $rollups['totals']['files'], 'scanned files count in totals even without blocks');
        $this->assertSame(0, $rollups['totals']['blocks']);
        $this->assertGreaterThan(0, $rollups['totals']['file_lines'], 'token facts come from the file parse, blocks or not');
        $this->assertSame([], $rollups['top_files']);
        $this->assertSame(0, $rollups['files_truncated']);
        $summary = (new JsonReporter())->build($state->report)['summary'];
        $this->assertArrayHasKey('rollups', $summary, 'measured-and-empty still shows the key; only UNARMED omits it');
    }
}
