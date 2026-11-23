<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Refactor\Hole;
use Phpdup\Reporting\JsonReporter;
use Phpdup\Reporting\JsonSchemaSpec;
use Phpdup\Reporting\Report;
use Phpdup\Util\LineRange;

final class JsonReporterTest extends TestCase
{
    public function testEmptyReportProducesEmptyClustersArray(): void
    {
        $payload = (new JsonReporter())->build(new Report(0, 0, 0, [], Config::defaults([])));
        $this->assertSame(0, $payload['summary']['clusters']);
        $this->assertSame([], $payload['clusters']);
        $this->assertArrayHasKey('phpdup_version', $payload);
        $this->assertSame('aggressive', $payload['config']['normalization_mode']);
    }

    /**
     * 0.6 additive summary keys: JsonSchemaSpec::summaryShape() and the
     * emitted object must agree key-for-key in order (trailing-additive —
     * the six historical 1.1 keys keep their positions), null-vs-zero is
     * respected, and the balance law holds on a tracked producer.
     */
    public function testSummaryCarriesFileAccountingKeys(): void
    {
        $report  = new Report(
            files: 10, blocks: 42, parseErrors: 1, clusters: [], config: Config::defaults([]),
            reusedFiles: 3, processedFiles: 5, skippedFiles: 1,
        );
        $payload = (new JsonReporter())->build($report);

        $this->assertSame('1.7', $payload['schema_version']);
        $summary = $payload['summary'];
        $this->assertSame(array_keys(JsonSchemaSpec::summaryShape()), array_keys($summary));
        // Item 4.3: trailing key, present even for producers that never
        // ran the analyzer (default [] on Report).
        $this->assertSame(array_keys(JsonSchemaSpec::topLevelShape()), array_keys($payload));
        $this->assertSame([], $payload['declared_twins']);
        $this->assertSame(10, $summary['files']);          // historical key = files scanned
        $this->assertSame(10, $summary['files_scanned']);  // restated unambiguously
        $this->assertSame(3, $summary['files_reused']);
        $this->assertSame(5, $summary['files_processed']);
        $this->assertSame(1, $summary['files_without_blocks']);
        $this->assertSame(
            $summary['files_scanned'],
            $summary['files_reused'] + $summary['files_processed']
                + $summary['files_without_blocks'] + $summary['parse_errors'],
            'balance law: scanned = reused + processed + no-blocks + parse-errors',
        );
    }

    /** Legacy `new Report(...)` producers (library API, server workers) emit nulls, not fake zeros. */
    public function testSummaryNullCountersForLegacyProducer(): void
    {
        $payload = (new JsonReporter())->build(new Report(4, 7, 0, [], Config::defaults([])));
        $this->assertNull($payload['summary']['files_reused']);
        $this->assertNull($payload['summary']['files_processed']);
        $this->assertNull($payload['summary']['files_without_blocks']);
        $this->assertSame(4, $payload['summary']['files_scanned']);
    }

    /**
     * output-S27 (schema 1.7) full projection matrix on synthetic holes:
     * verified absence (canonical, honest hole), never-gathered row
     * (canonical, degraded hole), and the two legacy impostor strings a
     * stale pre-1.7 payload may still carry. present_in_members counts
     * GENUINE VALUE ROWS ONLY — positionally, never from the deduped set.
     */
    public function testCanonicalAndLegacyAbsenceRowsProjectOntoThe17Wire(): void
    {
        $blocks  = [$this->blk('m1.php'), $this->blk('m2.php'), $this->blk('m3.php'), $this->blk('m4.php')];
        $cluster = new Cluster('S27_WIRE', $blocks, 0.9, false);
        $cluster->holes = [
            new Hole('__O0', 'optional_block', ["'s();'", Hole::MISSING, Hole::MISSING, Hole::MISSING], false),
            new Hole('__O1', 'optional_block', ["'t();'", "'t();'", Hole::MISSING, Hole::MISSING], true),
            new Hole('__O2', 'optional_block', ['<absent>', "'u();'", '<missing>', "'u();'"], false),
        ];
        $report = new Report(
            files: 4, blocks: 4, parseErrors: 0, clusters: [$cluster], config: Config::defaults([]),
        );
        $holes = (new JsonReporter())->build($report)['clusters'][0]['holes'];

        // Honest optional hole: deduped observed [value, null]; the three
        // verified absences are NOT present; degraded stays false.
        $this->assertSame(["'s();'", null], $holes[0]['observed']);
        $this->assertSame([0], $holes[0]['present_in_members']);
        $this->assertFalse($holes[0]['degraded']);
        $this->assertSame(2, $holes[0]['value_count']);

        // Degraded optional hole: gaps drop out of the presence list too.
        $this->assertSame([0, 1], $holes[1]['present_in_members']);
        $this->assertTrue($holes[1]['degraded']);

        // Legacy payload: '<absent>' nulls WITHOUT lifting degraded (it was
        // the verified marker), '<missing>' nulls AND lifts it honestly;
        // neither string may survive in the emitted list.
        $this->assertSame([null, "'u();'", null], $holes[2]['observed']);
        $this->assertSame([1, 3], $holes[2]['present_in_members']);
        $this->assertTrue($holes[2]['degraded']);
        $this->assertStringNotContainsString('<absent>', json_encode($holes));
        $this->assertStringNotContainsString('<missing>', json_encode($holes));
    }

    private function blk(string $file): Block
    {
        $stmts = (new \Phpdup\Parsing\AstParser())->parseCode('<?php $x;');
        return new Block(
            file: $file,
            range: new LineRange(1, 1),
            kind: 'method',
            namespace: null, class: null, name: null,
            ast: $stmts[0],
        );
    }

    /**
     * output-S56b synthetic carrier for the retired sql/optional/notify
     * fixture pipelines: two clusters over hand-built blocks — A2 with a
     * signature, one literal and one optional_block hole; B2 a plain
     * near-duplicate. Deterministic ids/kinds feed the exact-vector pins.
     *
     * @return list<Cluster>
     */
    private function syntheticClusters(): array
    {
        $a = new Cluster('F00000000000000A2', [
            $this->blk('/phpdup-syn/p1.php'), $this->blk('/phpdup-syn/p2.php'),
        ], 0.92, false);
        $a->signature = 'function q(string $x): mixed';
        $a->patternTags = ['optional-segments'];
        $a->holes = [
            new Hole('__L0', 'literal', ["'a';", "'b';"], false),
            new Hole('__O0', 'optional_block', ["'s();'", Hole::MISSING], false),
        ];

        $b = new Cluster('F00000000000000B2', [
            $this->blk('/phpdup-syn/q1.php'), $this->blk('/phpdup-syn/q2.php'),
            $this->blk('/phpdup-syn/q3.php'),
        ], 1.0, true);

        return [$a, $b];
    }

    /**
     * output-S56b (was testFixtureReportPopulatesClusters): the fixture
     * era re-derived nothing here, but the summary count was an open
     * range; the synthetic report carries exactly 2 clusters, now pinned.
     */
    public function testSyntheticReportPopulatesClusters(): void
    {
        $payload = (new JsonReporter())->build(
            new Report(5, 5, 0, $this->syntheticClusters(), Config::defaults([])),
        );

        $this->assertSame(2, $payload['summary']['clusters']);
        $this->assertNotEmpty($payload['clusters']);

        foreach ($payload['clusters'] as $c) {
            $this->assertArrayHasKey('id', $c);
            $this->assertArrayHasKey('exact', $c);
            $this->assertArrayHasKey('similarity', $c);
            $this->assertArrayHasKey('signature', $c);
            $this->assertArrayHasKey('members', $c);
            $this->assertArrayHasKey('holes', $c);
            $this->assertArrayHasKey('kind', $c, 'cluster.kind populated from member[0].kind');
        }

        // Strengthened (output-S56b): identity vectors ride the synthetic
        // ids — the fixture never allowed pinning these.
        $this->assertSame('F00000000000000A2', $payload['clusters'][0]['id']);
        $this->assertSame('function q(string $x): mixed', $payload['clusters'][0]['signature']);
        $this->assertSame('method', $payload['clusters'][0]['kind']);
        $this->assertSame(3, $payload['clusters'][1]['size'] ?? count($payload['clusters'][1]['members']));
    }

    public function testWriteToCreatesParseableJsonFile(): void
    {
        $tmp = sys_get_temp_dir() . '/phpdup-json-' . uniqid() . '.json';
        try {
            (new JsonReporter())->writeTo(
                new Report(5, 5, 0, $this->syntheticClusters(), Config::defaults([])),
                $tmp,
            );
            $this->assertFileExists($tmp);
            $decoded = json_decode((string)file_get_contents($tmp), true);
            $this->assertIsArray($decoded);
            $this->assertSame('0.1.0', $decoded['phpdup_version']);
            // output-S56b strengthening: round-trip keeps the cluster count.
            $this->assertSame(2, $decoded['summary']['clusters']);
        } finally {
            @unlink($tmp);
        }
    }

    public function testOptionalBlockHolesIncludePresentInMembers(): void
    {
        // output-S56b: a hand-built optional-segments cluster on 4 members
        // replaces the optional-fixture scan — two optional_block holes
        // with real absences, so every wire law below has bite by
        // construction (the fixture only "should" produce this shape).
        $cluster = new Cluster('OPT_WIRE', [
            $this->blk('o1.php'), $this->blk('o2.php'), $this->blk('o3.php'), $this->blk('o4.php'),
        ], 0.9, false);
        $cluster->patternTags = ['optional-segments'];
        $cluster->holes = [
            new Hole('__O0', 'optional_block', ["'s();'", "'s();'", Hole::MISSING, Hole::MISSING], false),
            new Hole('__O1', 'optional_block', [Hole::MISSING, "'t();'", Hole::MISSING, "'t();'"], false),
        ];
        $report  = new Report(4, 4, 0, [$cluster], Config::defaults([]));
        $payload = (new JsonReporter())->build($report);

        // Locate the optional-segments cluster.
        $optional = null;
        foreach ($payload['clusters'] as $c) {
            if (in_array('optional-segments', $c['pattern_tags'], true)) {
                $optional = $c;
                break;
            }
        }
        $this->assertNotNull($optional, 'synthetic report carries an optional-segments cluster');

        $optionalHoles = array_filter($optional['holes'], static fn($h) => $h['kind'] === 'optional_block');
        $this->assertNotEmpty($optionalHoles, 'cluster has at least one optional_block hole');

        foreach ($optionalHoles as $h) {
            $this->assertArrayHasKey('present_in_members', $h, 'optional_block holes carry present_in_members[]');
            $this->assertIsArray($h['present_in_members']);
            $this->assertNotEmpty($h['present_in_members'], 'at least one member must have the segment');
            // output-S27 wire law: the printable impostors are render-time
            // only — the wire never carries them, and an absence row that
            // is not listed as present serializes as JSON null.
            $this->assertNotContains('<absent>', $h['observed'], 'sentinel string never reaches the wire');
            $this->assertNotContains('<missing>', $h['observed']);
            $this->assertNotContains('', $h['observed'], 'canonical absence must not serialize as empty string');
            // Indices should be valid integer offsets into cluster.members.
            foreach ($h['present_in_members'] as $idx) {
                $this->assertIsInt($idx);
                $this->assertGreaterThanOrEqual(0, $idx);
                $this->assertLessThan(count($optional['members']), $idx);
            }
        }

        // Strengthened (output-S56b): the presence lists are exact.
        $this->assertSame([0, 1], $optionalHoles[0]['present_in_members']);
        $this->assertSame([1, 3], $optionalHoles[1]['present_in_members']);

        $sawNull = false;
        foreach ($optionalHoles as $h) {
            if (in_array(null, $h['observed'], true)) {
                $sawNull = true;
            }
        }
        $this->assertTrue($sawNull, 'the short members verify an absence — wire shows null');
    }

    public function testNonOptionalHolesOmitPresentInMembers(): void
    {
        // output-S56b: synthetic carrier (was a notify-fixture scan): one
        // literal hole must serialize WITHOUT present_in_members, and the
        // companion optional hole must still carry it — the contrast is
        // pinned inside the same cluster, not left to fixture luck.
        $payload = (new JsonReporter())->build(
            new Report(5, 5, 0, $this->syntheticClusters(), Config::defaults([])),
        );

        $sawAnyHole = false;
        foreach ($payload['clusters'] as $c) {
            foreach ($c['holes'] as $h) {
                if ($h['kind'] !== 'optional_block') {
                    $this->assertArrayNotHasKey('present_in_members', $h);
                    $sawAnyHole = true;
                }
            }
        }
        $this->assertTrue($sawAnyHole, 'synthetic clusters carry a non-optional hole');

        // Direct contrast pin (strengthened): cluster A2 hole index 1 is
        // the optional_block and DOES carry the key.
        $this->assertArrayHasKey(
            'present_in_members',
            $payload['clusters'][0]['holes'][1],
        );
    }

    /**
     * once-S37 fix-1: the scan-signal carriers (skip notes, parse failures,
     * replay verdict) ride the Report's trailing params. Legacy producers
     * that omit them — library API, server workers hand-building a Report —
     * stay honestly UNTRACKED (empty lists, clustersReplayed null), never a
     * fabricated zero.
     */
    public function testS37Fix1CarriersRideTrailingParamsLegacyProducersStayUntracked(): void
    {
        $populated = new Report(
            0, 0, 0, [], Config::defaults([]),
            scanNotes: ['n1'],
            parseFailures: [['file' => 'f.php', 'message' => 'm']],
            clustersReplayed: true,
        );
        $this->assertSame(['n1'], $populated->scanNotes);
        $this->assertSame([['file' => 'f.php', 'message' => 'm']], $populated->parseFailures);
        $this->assertTrue($populated->clustersReplayed);

        $legacy = new Report(0, 0, 0, [], Config::defaults([]));
        $this->assertSame([], $legacy->scanNotes);
        $this->assertSame([], $legacy->parseFailures);
        $this->assertNull($legacy->clustersReplayed);
    }

    /**
     * once-S37 fix-1 schema-event tripwire: emitting summary.scan_notes[],
     * summary.parse_failures[] or summary.clusters_replayed is a JSON schema
     * 1.7→1.8 event that lands ONLY together with SCHEMA_VERSION and a golden
     * regen in one no-broken-tip commit (plan §37 shared-resources: "the ONLY
     * sanctioned bumps … once-S37 (1.7→1.8, owner-approved row)"). The owner
     * window is closed, so while carriers are populated the payload must stay
     * exactly the frozen 1.7 keyset. This pin turns RED the day a summary key
     * is added without moving the version — the breach r1 warned about.
     */
    public function testS37Fix1JsonShapeFrozenWhileCarriersPopulated(): void
    {
        $payload = (new JsonReporter())->build(new Report(
            0, 0, 0, [], Config::defaults([]),
            scanNotes: ['skipped unreadable path /tmp/x (directory is not readable)'],
            parseFailures: [['file' => '/tmp/x/broken.php', 'message' => 'Syntax error, unexpected ";" on line 2']],
            clustersReplayed: true,
        ));
        $this->assertSame('1.7', $payload['schema_version']);
        $this->assertSame(array_keys(JsonSchemaSpec::summaryShape()), array_keys($payload['summary']));
        $this->assertArrayNotHasKey('scan_notes', $payload['summary']);
        $this->assertArrayNotHasKey('parse_failures', $payload['summary']);
        $this->assertArrayNotHasKey('clusters_replayed', $payload['summary']);
    }
}
