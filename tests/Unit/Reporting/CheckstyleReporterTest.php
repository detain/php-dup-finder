<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Reporting\CheckstyleReporter;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use Phpdup\Util\LineRange;
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

    /**
     * php-dup-finder-output-037: `<error severity="...">` routes through
     * Severity::checkstyleSeverity while the emitted attribute stays identical
     * to the pre-centralization inline `$cluster->exact ? 'warning' : 'info'`
     * — the expected literals below are that old expression, kept verbatim as
     * a byte-freeze oracle independent of the center.
     */
    public function testErrorSeverityRoutesThroughSeverityWithByteIdenticalLabels(): void
    {
        $member = static fn(string $name, int $line): Block => new Block(
            file: 'checkstyle-level.php',
            range: new LineRange($line, $line + 4),
            kind: 'function',
            namespace: null,
            class: null,
            name: $name,
            ast: new \PhpParser\Node\Stmt\Nop(),
        );

        $exact = new Cluster('F0000000000000005', [$member('a', 1)], 1.0, true);
        $near  = new Cluster('F0000000000000006', [$member('b', 10)], 0.8, false);

        $xml = (new CheckstyleReporter())->build(
            new Report(1, 2, 0, [$exact, $near], Config::defaults([])),
        );

        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($xml));

        $severities = [];
        foreach ($doc->getElementsByTagName('error') as $err) {
            $severities[] = $err->getAttribute('severity');
        }
        $this->assertSame(['warning', 'info'], $severities,
            'exact clone keeps warning, near-dup keeps info — the historical inline mapping');
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
