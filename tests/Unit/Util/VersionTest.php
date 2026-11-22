<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Util;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Reporting\CheckstyleReporter;
use Phpdup\Reporting\GitLabSastReporter;
use Phpdup\Reporting\JsonReporter;
use Phpdup\Reporting\Report;
use Phpdup\Reporting\SarifReporter;
use Phpdup\Reporting\TimeseriesReporter;
use Phpdup\Util\Version;

/**
 * The central tool-version constant and its five consumers
 * (php-dup-finder-output-038).
 *
 * The bug it kills: hand-copied version literals drifted — JSON and
 * timeseries payloads stamped 0.1.0 while SARIF, GitLab SAST and
 * Checkstyle stamped 0.2.0, so one run reported two different tool
 * versions depending on output format. Every payload must now carry
 * Version::STRING verbatim.
 */
final class VersionTest extends TestCase
{
    public function testConstantIsASaneSemverString(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/',
            Version::STRING,
            'the tool version is a plain X.Y.Z string in every payload',
        );
    }

    /**
     * One synthetic Report, five payloads: every emitted tool-version
     * field equals Version::STRING (acceptance pin of output-038).
     */
    public function testAllFivePayloadsCarryTheCentralVersion(): void
    {
        $report = new Report(0, 0, 0, [], Config::defaults([]));

        $json = (new JsonReporter())->build($report);
        $this->assertSame(Version::STRING, $json['phpdup_version'], 'JSON phpdup_version');

        $timeseries = (new TimeseriesReporter())->buildRecord($report);
        $this->assertSame(Version::STRING, $timeseries['phpdup_version'], 'timeseries phpdup_version');

        $sarif = (new SarifReporter())->build($report);
        $this->assertSame(
            Version::STRING,
            $sarif['runs'][0]['tool']['driver']['version'],
            'SARIF driver version',
        );

        $gitlab = (new GitLabSastReporter())->build($report);
        $this->assertSame(Version::STRING, $gitlab['scan']['scanner']['version'], 'GitLab scanner version');
        $this->assertSame(Version::STRING, $gitlab['scan']['analyzer']['version'], 'GitLab analyzer version');

        $checkstyle = (new CheckstyleReporter())->build($report);
        $this->assertStringContainsString(
            '<checkstyle version="phpdup-' . Version::STRING . '">',
            $checkstyle,
            'Checkstyle root element version',
        );
    }

    /**
     * The zero-drift contract the finding was raised for: the same run
     * never shows two versions. Cross-payload equality (not just each
     * equal to the constant) is the regression pin against a site
     * reintroducing its own literal.
     */
    public function testNoFormatDivergesFromAnotherOnTheSameReport(): void
    {
        $report = new Report(0, 0, 0, [], Config::defaults([]));

        $emitted = [
            'json'       => (new JsonReporter())->build($report)['phpdup_version'],
            'timeseries' => (new TimeseriesReporter())->buildRecord($report)['phpdup_version'],
            'sarif'      => (new SarifReporter())->build($report)['runs'][0]['tool']['driver']['version'],
            'gitlab'     => (new GitLabSastReporter())->build($report)['scan']['scanner']['version'],
            'checkstyle' => (new CheckstyleReporter())->build($report),
        ];

        $checkstyle = $emitted['checkstyle'];
        unset($emitted['checkstyle']);
        $this->assertSame(1, preg_match('/<checkstyle version="phpdup-([^"]+)">/', $checkstyle, $m));
        $emitted['checkstyle'] = $m[1];

        $this->assertSame(array_fill(0, count($emitted), Version::STRING), array_values($emitted));
    }

    /**
     * The SARIF spec version and the GitLab SAST schema version are
     * report-format versions, NOT the tool version — they keep their
     * own constants and must not move when the tool ships.
     */
    public function testFormatSchemaVersionsStayIndependentOfTheToolVersion(): void
    {
        $report = new Report(0, 0, 0, [], Config::defaults([]));

        $sarif = (new SarifReporter())->build($report);
        $this->assertSame(SarifReporter::VERSION, $sarif['version'], 'SARIF envelope keeps the spec version');
        $this->assertNotSame(Version::STRING, $sarif['version']);

        $gitlab = (new GitLabSastReporter())->build($report);
        $this->assertSame(GitLabSastReporter::SCHEMA_VERSION, $gitlab['version'], 'GitLab envelope keeps the SAST schema version');
        $this->assertNotSame(Version::STRING, $gitlab['version']);
    }
}
