<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Cli\ConfigLoader;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Reporting\ClusterScope;
use Phpdup\Reporting\JsonReporter;
use Phpdup\Reporting\JsonSchemaSpec;
use Phpdup\Reporting\Report;
use Phpdup\Util\LineRange;

/**
 * Item 5.1 (this lane) — the scope_labels conditional-key contract.
 *
 * Three laws pinned here:
 *  1. UNARMED payloads (every default run, every frozen golden) carry NO
 *     scope_counts / scope_ladder / member package key, and the summary
 *     key set stays exactly JsonSchemaSpec::summaryShape()'s default.
 *  2. ARMED payloads carry all three conditional shapes, and their key
 *     sets match the armed declarations named-for-named.
 *  3. OFF-vs-ON byte identity: strip the armed run's conditional keys and
 *     what remains is BYTE-EQUAL to the unarmed payload of the same
 *     report — the arm may ADD, never MOVE or MUTATE a default byte.
 */
final class JsonReporterScopeLabelsTest extends TestCase
{
    /**
     * Two clusters: one cross-repo pair (members labeled under distinct
     * repos/packages), one intra-file pair (same file, same package).
     * Labels are set exactly the way RepoLabels::stampBlock leaves them.
     *
     * @return array{0: list<Cluster>, 1: Config, 2: Config} [clusters, unarmed config, armed config]
     */
    private function fixture(): array
    {
        $alpha = $this->blk('/rootA/src/Alpha.php');
        $alpha->repo    = 'rootA';
        $alpha->package = 'vendor/root-a';
        $alpha->scope   = 'rootA';

        $beta = $this->blk('/rootB/src/Beta.php');
        $beta->repo    = 'rootB';
        $beta->package = 'vendor/root-b';
        $beta->scope   = 'rootB';

        $twin1 = $this->blk('/mono/src/TwinPair.php');
        $twin1->repo    = 'mono';
        $twin1->package = 'vendor/mono';
        $twin1->scope   = 'single';

        $twin2 = $this->blk('/mono/src/TwinPair.php');
        $twin2->repo    = 'mono';
        $twin2->package = 'vendor/mono';
        $twin2->scope   = 'single';

        $cross  = new Cluster('S51_CROSS', [$alpha, $beta], 0.95, false);
        $intra  = new Cluster('S51_INTRA', [$twin1, $twin2], 0.9, false);

        $unarmed = Config::defaults(['/rootA', '/rootB']);
        // Armed config through the REAL loader route (same bytes the CLI
        // --scope-labels flag feeds): scope_labels is a root-only flat key.
        $armed = (new ConfigLoader())->load(
            paths: ['/rootA', '/rootB'],
            configFile: null,
            overrides: ['scope_labels' => true],
        );
        self::assertTrue($armed->scopeLabels, 'the armed fixture must actually be armed');
        self::assertFalse($unarmed->scopeLabels, 'the unarmed fixture must be the default OFF');

        return [[$cross, $intra], $unarmed, $armed];
    }

    private function report(array $clusters, Config $config, ?array $scopeCounts): Report
    {
        return new Report(
            files: 4, blocks: 4, parseErrors: 0,
            clusters: $clusters, config: $config,
            scopeCounts: $scopeCounts,
        );
    }

    public function testUnarmedPayloadCarriesNoScopeLabelKeys(): void
    {
        [$clusters, $unarmed, ] = $this->fixture();
        $payload = (new JsonReporter())->build($this->report($clusters, $unarmed, null));

        // The producer never ran the census: no summary key, and the
        // historical default key set stands EXACT.
        self::assertArrayNotHasKey('scope_counts', $payload['summary']);
        self::assertSame(
            array_keys(JsonSchemaSpec::summaryShape()),
            array_keys($payload['summary']),
            'summary keys must equal the unarmed declaration, order included',
        );
        foreach ($payload['clusters'] as $row) {
            self::assertArrayNotHasKey('scope_ladder', $row);
            foreach ($row['members'] as $member) {
                self::assertArrayNotHasKey('package', $member);
            }
        }
    }

    public function testArmedPayloadCarriesAllThreeConditionalShapes(): void
    {
        [$clusters, , $armed] = $this->fixture();
        $counts  = ClusterScope::counts($clusters);
        $payload = (new JsonReporter())->build($this->report($clusters, $armed, $counts));

        self::assertSame($counts, $payload['summary']['scope_counts']);
        self::assertSame(
            array_keys(JsonSchemaSpec::summaryShape(scopeLabels: true)),
            array_keys($payload['summary']),
            'armed summary keys must equal the armed declaration, order included',
        );
        // Sum identity: every reported cluster in exactly one rung.
        self::assertSame(
            $payload['summary']['clusters'],
            array_sum($payload['summary']['scope_counts']),
        );
        self::assertSame(['intra-file' => 1, 'intra-package' => 0, 'cross-package' => 0, 'cross-repo' => 1], $counts);

        self::assertSame('cross-repo', $payload['clusters'][0]['scope_ladder']);
        self::assertSame('intra-file', $payload['clusters'][1]['scope_ladder']);
        self::assertSame('vendor/root-a', $payload['clusters'][0]['members'][0]['package']);
        self::assertSame('vendor/root-b', $payload['clusters'][0]['members'][1]['package']);
        self::assertSame('vendor/mono', $payload['clusters'][1]['members'][0]['package']);
    }

    public function testArmedMemberRowCarriesPackageKeyEvenWhenEmpty(): void
    {
        // Conditional-by-config, not by member emptiness: an armed run
        // emits package:'' for a label-less member rather than omitting
        // the key (payload shape must not depend on tree accidents).
        [$clusters, , $armed] = $this->fixture();
        $clusters[1]->members[0]->package = '';
        $payload = (new JsonReporter())->build($this->report($clusters, $armed, ClusterScope::counts($clusters)));
        $row = $payload['clusters'][1]['members'][0];
        self::assertArrayHasKey('package', $row);
        self::assertSame('', $row['package']);
    }

    public function testOffVersusOnByteIdentity(): void
    {
        [$clusters, $unarmed, $armed] = $this->fixture();

        $off = (new JsonReporter())->build($this->report($clusters, $unarmed, null));

        $on = (new JsonReporter())->build($this->report($clusters, $armed, ClusterScope::counts($clusters)));
        unset($on['summary']['scope_counts']);
        foreach ($on['clusters'] as &$row) {
            unset($row['scope_ladder']);
            foreach ($row['members'] as &$member) {
                unset($member['package']);
            }
            unset($member);
        }
        unset($row);

        self::assertEquals(
            json_decode(json_encode($off), true),
            json_decode(json_encode($on), true),
            'stripping the three conditional shapes from an armed payload must leave the unarmed bytes EXACT',
        );
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
}
