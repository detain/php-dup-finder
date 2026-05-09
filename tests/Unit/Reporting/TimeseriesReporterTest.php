<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use Phpdup\Reporting\TimeseriesReporter;
use Symfony\Component\Console\Output\NullOutput;

final class TimeseriesReporterTest extends TestCase
{
    public function testRecordContainsExpectedFields(): void
    {
        $reporter = new TimeseriesReporter(commitSha: 'abc123', timestamp: 1700000000);
        $record = $reporter->buildRecord(
            new Report(0, 0, 0, [], Config::defaults([])),
        );

        $this->assertSame('abc123', $record['commit_sha']);
        $this->assertSame(0, $record['files']);
        $this->assertSame(0, $record['clusters']);
        $this->assertSame([], $record['pattern_tags']);
        $this->assertArrayHasKey('timestamp', $record);
        $this->assertArrayHasKey('phpdup_version', $record);
    }

    public function testFixtureRecordHasNonZeroClusterCount(): void
    {
        $report = $this->buildReport();
        $reporter = new TimeseriesReporter(commitSha: 'test-sha', timestamp: 1700000000);
        $record = $reporter->buildRecord($report);

        $this->assertSame(count($report->clusters), $record['clusters']);
        $this->assertSame($report->files, $record['files']);
        $this->assertSame($report->blocks, $record['blocks']);
        $this->assertSame('test-sha', $record['commit_sha']);
    }

    public function testWriteToAppendsLine(): void
    {
        $reporter = new TimeseriesReporter(commitSha: 'sha1', timestamp: 1);
        $tmp = tempnam(sys_get_temp_dir(), 'phpdup-ts-');
        try {
            $report = new Report(0, 0, 0, [], Config::defaults([]));
            $reporter->writeTo($report, $tmp);
            $reporter2 = new TimeseriesReporter(commitSha: 'sha2', timestamp: 2);
            $reporter2->writeTo($report, $tmp);

            $contents = file_get_contents($tmp);
            $this->assertNotFalse($contents);
            $lines = array_values(array_filter(explode("\n", $contents)));
            $this->assertCount(2, $lines);

            $row1 = json_decode($lines[0], true);
            $row2 = json_decode($lines[1], true);
            $this->assertSame('sha1', $row1['commit_sha']);
            $this->assertSame('sha2', $row2['commit_sha']);
        } finally {
            @unlink($tmp);
        }
    }

    private function buildReport(): Report
    {
        $config = new Config(
            paths: [__DIR__ . '/../../Fixtures/sql'],
            exclude: Config::defaults([])->exclude,
            lazyAst: false,
        );
        $state = new PipelineState($config);
        $out   = new NullOutput();
        (new ScanningStage())->run($state, $out);
        (new PreprocessStage(useCache: false))->run($state, $out);
        (new ClusterStage(exactOnly: true))->run($state, $out);
        (new RefactorStage(useCache: false))->run($state, $out);

        return new Report(
            files: count($state->files),
            blocks: count($state->blocks),
            parseErrors: $state->parseErrors,
            clusters: (new Ranker($config->minClusterImpact))->rank($state->clusters),
            config: $config,
        );
    }
}
