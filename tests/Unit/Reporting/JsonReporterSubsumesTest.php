<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Cli\Config;
use Phpdup\Extraction\Block;
use Phpdup\Reporting\JsonReporter;
use Phpdup\Reporting\JsonSchemaSpec;
use Phpdup\Reporting\Report;
use Phpdup\Util\LineRange;

/**
 * Item 6.3 — the conditional `subsumes` cluster key. Exact posture of
 * the 2.3 `class_shape` precedent: the key is present ONLY on clusters
 * the armed fold attributed a family to, absent (never null, never [])
 * everywhere else, and absent from any default payload because unarmed
 * runs never populate the field. No SCHEMA_VERSION move — a default
 * run's bytes cannot change (program conditional-key ruling).
 */
final class JsonReporterSubsumesTest extends TestCase
{
    public function testUnannotatedClustersCarryNoSubsumesKey(): void
    {
        $payload = (new JsonReporter())->build($this->report(
            $this->cluster('A', []),
            $this->cluster('B', []),
        ));

        foreach ($payload['clusters'] as $cluster) {
            $this->assertArrayNotHasKey('subsumes', $cluster);
        }
    }

    public function testAttributedClusterTrailsTheSubsumesIdsVerbatim(): void
    {
        $a = $this->cluster('A', ['B', 'C']);
        $payload = (new JsonReporter())->build($this->report(
            $a,
            $this->cluster('B', []),
            $this->cluster('C', []),
        ));

        // The survivor's list is the annotation itself, sorted, trailing
        // the historical key set (placement mirrors class_shape's).
        $this->assertSame(['B', 'C'], $payload['clusters'][0]['subsumes']);
        $keys = array_keys($payload['clusters'][0]);
        $this->assertSame('subsumes', $keys[count($keys) - 1], 'trailing key, additive position');
        $this->assertArrayNotHasKey('subsumes', $payload['clusters'][1]);
    }

    public function testSpecDeclaresTheConditionalKeyAsOptional(): void
    {
        $shape = JsonSchemaSpec::clusterShape();
        $this->assertArrayHasKey('subsumes', $shape);
        $this->assertStringStartsWith('?list<string>', $shape['subsumes'],
            'conditional spelling — the belts never see it on default payloads');
    }

    private function report(Cluster ...$clusters): Report
    {
        return new Report(
            files: 3, blocks: 6, parseErrors: 0, clusters: array_values($clusters),
            config: Config::defaults(['/nowhere']),
        );
    }

    /** @param list<string> $subsumes */
    private function cluster(string $id, array $subsumes): Cluster
    {
        $c = new Cluster($id, [
            new Block(
                file: '/p/f.php', range: new LineRange(1, 10), kind: 'method',
                namespace: null, class: null, name: 'm', ast: new Node\Stmt\Nop(),
            ),
            new Block(
                file: '/p/g.php', range: new LineRange(1, 10), kind: 'method',
                namespace: null, class: null, name: 'm', ast: new Node\Stmt\Nop(),
            ),
        ], 1.0, false);
        $c->subsumes = $subsumes;
        return $c;
    }
}
