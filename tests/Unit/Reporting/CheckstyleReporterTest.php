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
use Phpdup\Reporting\CheckstyleReporter;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use Symfony\Component\Console\Output\NullOutput;

final class CheckstyleReporterTest extends TestCase
{
    public function testEmptyReportProducesValidEmptyXml(): void
    {
        $xml = (new CheckstyleReporter())->build(
            new Report(0, 0, 0, [], Config::defaults([])),
        );
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $this->assertStringContainsString('<checkstyle version="phpdup-', $xml);
        $this->assertStringContainsString('</checkstyle>', $xml);

        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($xml));
    }

    public function testFixtureReportProducesParseableXmlWithErrorPerMember(): void
    {
        $xml = (new CheckstyleReporter())->build($this->buildReport());

        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($xml));

        $errors = $doc->getElementsByTagName('error');
        $this->assertGreaterThan(0, $errors->length);
        foreach ($errors as $err) {
            $this->assertSame('phpdup.duplicate-logic', $err->getAttribute('source'));
            $this->assertNotEmpty($err->getAttribute('line'));
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
