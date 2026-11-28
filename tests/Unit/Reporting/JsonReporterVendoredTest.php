<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Reporting\JsonReporter;
use Phpdup\Reporting\JsonSchemaSpec;
use Phpdup\Reporting\Report;

/**
 * Item 5.2 (W2b) — the conditional `summary.vendored_duplicates` key.
 * Same posture as idiom_census/cluster_history (the MINE-D conditional-
 * key law): an unarmed payload never grows the key and its summary key
 * set equals summaryShape()'s unarmed declaration; when armed the block
 * TRAILS the other conditional keys and matches vendoredBlockShape().
 * Schema version itself never moves (the emit window is owner-gated).
 */
final class JsonReporterVendoredTest extends TestCase
{
    public function testUnarmedPayloadHasNoVendoredKey(): void
    {
        $payload = (new JsonReporter())->build($this->report(vendored: null));

        self::assertArrayNotHasKey('vendored_duplicates', $payload['summary']);
        self::assertSame(
            array_keys(JsonSchemaSpec::summaryShape(false, false, false)),
            array_keys($payload['summary']),
            'unarmed key set is exactly the unarmed declaration',
        );
    }

    public function testArmedPayloadCarriesTheSectionLast(): void
    {
        $section = self::section();
        $report  = $this->report(vendored: $section);
        $summary = (new JsonReporter())->build($report)['summary'];

        self::assertArrayHasKey('vendored_duplicates', $summary);
        self::assertSame($section, $summary['vendored_duplicates'], 'pass-through of the split state, no mutation');
        self::assertSame(
            'vendored_duplicates',
            array_key_last($summary),
            'the key trails the sibling conditional keys (idiom_census, cluster_history)',
        );
        self::assertSame(
            array_keys(JsonSchemaSpec::summaryShape(false, false, true)),
            array_keys($summary),
        );
    }

    public function testBothHistoryAndVendoredKeepTheirSlots(): void
    {
        $report = new Report(
            files: 2, blocks: 9, parseErrors: 0, clusters: [],
            config: Config::defaults(['/nowhere']),
            clusterHistory: new \Phpdup\Git\ClusterHistory(rows: [], windowMonths: 6, omitted: 0, notes: []),
            vendoredDuplicates: self::section(),
        );
        $summary = (new JsonReporter())->build($report)['summary'];

        self::assertSame(
            array_keys(JsonSchemaSpec::summaryShape(false, true, true)),
            array_keys($summary),
            'slot order: base, cluster_history, vendored_duplicates',
        );
    }

    public function testSectionRowKeysMatchTheDeclaredBlockShape(): void
    {
        $row = self::section()['rows'][0];
        $declared = JsonSchemaSpec::vendoredBlockShape();

        self::assertSame(array_keys($declared), array_keys(self::section()), 'block keys are the declared keys');
        self::assertSame(
            ['id', 'kind', 'members', 'files', 'lines', 'signals', 'locations'],
            array_keys($row),
        );
        self::assertIsString($row['id']);
        self::assertIsString($row['kind']);
        self::assertIsInt($row['members']);
        self::assertIsInt($row['files']);
        self::assertIsInt($row['lines']);
        self::assertIsArray($row['signals']);
        self::assertIsArray($row['locations']);
    }

    public function testZeroSectionStillSerialisesShapefully(): void
    {
        $summary = (new JsonReporter())->build($this->report(vendored: [
            'clusters' => 0, 'lines' => 0, 'omitted' => 0, 'rows' => [],
        ]))['summary'];

        self::assertSame(
            ['clusters' => 0, 'lines' => 0, 'omitted' => 0, 'rows' => []],
            $summary['vendored_duplicates'],
            'an armed vendored-free corpus is a zero-shaped section, not a missing key',
        );
    }

    public function testSchemaVersionIsUntouched(): void
    {
        // The emit-window law: demand-armed keys ride 1.8; this lane
        // may NEVER bump the version. Pin the current truth.
        self::assertSame('1.8', JsonSchemaSpec::SCHEMA_VERSION);
    }

    /** @return array<string, mixed> */
    private static function section(): array
    {
        return [
            'clusters' => 2,
            'lines'    => 41,
            'omitted'  => 0,
            'rows'     => [[
                'id'        => 'c-1',
                'kind'      => 'method',
                'members'   => 2,
                'files'     => 2,
                'lines'     => 24,
                'signals'   => ['composer_lock_path:eusebiob/nusoap'],
                'locations' => ['/app/include/nusoap/a.php:1-12', '/app/include/nusoap/b.php:13-24'],
            ]],
        ];
    }

    /** @param array<string, mixed>|null $vendored */
    private function report(?array $vendored): Report
    {
        return new Report(
            files: 2,
            blocks: 9,
            parseErrors: 0,
            clusters: [],
            config: Config::defaults(['/nowhere']),
            vendoredDuplicates: $vendored,
        );
    }
}
