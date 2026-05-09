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
use Phpdup\Reporting\DiffReporter;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use Symfony\Component\Console\Output\NullOutput;

final class DiffReporterTest extends TestCase
{
    public function testWriteDirCreatesOneFilePerCluster(): void
    {
        $report = $this->buildReport();
        $dir    = sys_get_temp_dir() . '/phpdup-diff-' . uniqid();
        try {
            (new DiffReporter())->writeDir($report, $dir);

            $this->assertDirectoryExists($dir);
            $expected = 0;
            foreach ($report->clusters as $c) {
                if (count($c->members) >= 2) $expected++;
            }
            $this->assertGreaterThan(0, $expected, 'sql fixture should have multi-member clusters');

            $files = glob($dir . '/*.diff');
            $this->assertCount($expected, $files);

            foreach ($files as $f) {
                $body = (string)file_get_contents($f);
                $this->assertStringContainsString('# phpdup cluster', $body);
                $this->assertStringContainsString('# Suggested abstraction:', $body);
            }
        } finally {
            $this->rrmdir($dir);
        }
    }

    public function testWritePatchProducesSingleFile(): void
    {
        $report = $this->buildReport();
        $tmp    = sys_get_temp_dir() . '/phpdup-patch-' . uniqid() . '.patch';
        try {
            (new DiffReporter())->writePatch($report, $tmp);
            $this->assertFileExists($tmp);
            $body = (string)file_get_contents($tmp);
            $this->assertStringContainsString('# phpdup cluster', $body);
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

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
