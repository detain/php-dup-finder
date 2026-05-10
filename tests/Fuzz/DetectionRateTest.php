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
use Phpdup\Reporting\JsonReporter;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use Phpdup\Testing\FuzzCorpusGenerator;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @group fuzz
 */
final class DetectionRateTest extends TestCase
{
    public function testGeneratorEmitsExpectedFileCount(): void
    {
        $tmp = sys_get_temp_dir() . '/phpdup-fuzz-' . uniqid();
        $manifest = (new FuzzCorpusGenerator(123))->generate($tmp, [
            'simple' => [
                ['threshold' => '10', 'value' => '1'],
                ['threshold' => '20', 'value' => '2'],
                ['threshold' => '30', 'value' => '3'],
            ],
            'other' => [
                ['threshold' => '5', 'value' => '50'],
            ],
        ]);
        try {
            $this->assertCount(4, $manifest);
            foreach ($manifest as $entry) {
                $this->assertFileExists($entry['file']);
            }
        } finally {
            foreach ($manifest as $entry) @unlink($entry['file']);
            @rmdir($tmp);
        }
    }

    public function testPipelineFindsAtLeastOneClusterInGeneratedCorpus(): void
    {
        $tmp = sys_get_temp_dir() . '/phpdup-fuzz-' . uniqid();
        $manifest = (new FuzzCorpusGenerator(7))->generate($tmp, [
            'a' => [
                ['threshold' => '10', 'value' => '1'],
                ['threshold' => '20', 'value' => '2'],
                ['threshold' => '30', 'value' => '3'],
            ],
        ]);
        try {
            $report = $this->analyze($tmp);
            $this->assertGreaterThan(0, count($report->clusters),
                'phpdup should detect duplicates in the synthetic corpus');
        } finally {
            foreach ($manifest as $entry) @unlink($entry['file']);
            @rmdir($tmp);
        }
    }

    private function analyze(string $dir): Report
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
