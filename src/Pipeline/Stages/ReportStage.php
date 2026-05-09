<?php
declare(strict_types=1);

namespace Phpdup\Pipeline\Stages;

use Phpdup\Parallel\WorkerPool;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stage;
use Phpdup\Pipeline\StageInterface;
use Phpdup\Reporting\CheckstyleReporter;
use Phpdup\Reporting\CliReporter;
use Phpdup\Reporting\DiffReporter;
use Phpdup\Reporting\GitLabSastReporter;
use Phpdup\Reporting\HtmlReporter;
use Phpdup\Reporting\JsonReporter;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use Phpdup\Reporting\SarifReporter;
use Symfony\Component\Console\Output\OutputInterface;

final class ReportStage implements StageInterface
{
    public function __construct(
        private readonly int $limit,
        private readonly bool $showStats,
        private readonly ?string $sarifFile = null,
        private readonly ?string $gitlabSastFile = null,
        private readonly ?string $diffDir = null,
        private readonly ?string $patchFile = null,
        private readonly ?string $checkstyleFile = null,
    ) {}

    public function name(): Stage
    {
        return Stage::Reporting;
    }

    public function run(PipelineState $state, OutputInterface $output): void
    {
        if (!$state->blocks) {
            return;
        }

        $config = $state->config;

        if ($this->showStats) {
            $output->writeln('  timings (s):');
            foreach ($state->timings as $k => $v) {
                $output->writeln(sprintf('    %-12s %6.2f', $k, $v));
            }
            $output->writeln(sprintf(
                '  workers: %d (pcntl %s)',
                $config->workers > 0 ? $config->workers : WorkerPool::detectCpuCount(),
                WorkerPool::isAvailable() ? 'available' : 'unavailable',
            ));
        }

        $clusters = (new Ranker(
            $config->minClusterImpact,
            \Phpdup\Reporting\ClusterSort::parse($config->sort),
        ))->rank($state->clusters);

        $report = new Report(
            files: count($state->files),
            blocks: count($state->blocks),
            parseErrors: $state->parseErrors,
            clusters: $clusters,
            config: $config,
        );
        $state->report   = $report;
        $state->clusters = $clusters;

        (new CliReporter())->render($report, $output, $this->limit);

        if ($config->jsonReportFile !== null) {
            (new JsonReporter())->writeTo($report, $config->jsonReportFile);
            $output->writeln("<info>phpdup</info> json report → {$config->jsonReportFile}");
        }
        if ($config->htmlReportDir !== null) {
            (new HtmlReporter())->writeTo($report, $config->htmlReportDir);
            $output->writeln("<info>phpdup</info> html report → {$config->htmlReportDir}/index.html");
        }
        if ($this->sarifFile !== null) {
            (new SarifReporter())->writeTo($report, $this->sarifFile);
            $output->writeln("<info>phpdup</info> sarif report → {$this->sarifFile}");
        }
        if ($this->gitlabSastFile !== null) {
            (new GitLabSastReporter())->writeTo($report, $this->gitlabSastFile);
            $output->writeln("<info>phpdup</info> gitlab-sast report → {$this->gitlabSastFile}");
        }
        if ($this->diffDir !== null) {
            (new DiffReporter())->writeDir($report, $this->diffDir);
            $output->writeln("<info>phpdup</info> diff reports → {$this->diffDir}/");
        }
        if ($this->patchFile !== null) {
            (new DiffReporter())->writePatch($report, $this->patchFile);
            $output->writeln("<info>phpdup</info> cumulative patch → {$this->patchFile}");
        }
        if ($this->checkstyleFile !== null) {
            (new CheckstyleReporter())->writeTo($report, $this->checkstyleFile);
            $output->writeln("<info>phpdup</info> checkstyle report → {$this->checkstyleFile}");
        }
    }
}
