<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Cli\Config;
use Phpdup\Extraction\Block;
use Phpdup\Reporting\ClusterScope;
use Phpdup\Reporting\JsonReporter;
use Phpdup\Reporting\JsonSchemaSpec;
use Phpdup\Reporting\Report;
use Phpdup\Util\LineRange;

/**
 * Item 5.1 — trailing-additive payload keys: per-member repo/scope and
 * the computed cluster scope. Shape law (N/N-1 tolerance, §8.4): every
 * 1.3 key keeps name/type/position; the three new keys only append.
 */
final class JsonReporterScopeTest extends TestCase
{
    public function testMemberAndClusterScopeKeysAreTrailingAdditive(): void
    {
        $c = new Cluster('M', [
            self::member('/srv/r1/src/A.php', 'Acme\\A', 'r1', 'single'),
            self::member('/srv/r2/src/B.php', 'Acme\\B', 'r2', 'r2'),
        ], 0.95, false);

        $payload = (new JsonReporter())->build(new Report(2, 2, 0, [$c], Config::defaults([])));
        $cluster = $payload['clusters'][0];

        // Historical member key order intact, new pair appended.
        $memberKeys = array_keys($cluster['members'][0]);
        $this->assertSame(
            ['file', 'start', 'end', 'kind', 'namespace', 'class', 'name', 'size', 'repo', 'scope'],
            $memberKeys,
        );
        $this->assertSame('r1', $cluster['members'][0]['repo']);
        $this->assertSame('single', $cluster['members'][0]['scope']);
        $this->assertSame('r2', $cluster['members'][1]['repo']);

        // Cluster 'scope' trails 'holes' — every legacy key in place.
        // (Updated by item 5.4: the scope-era tail stays untouched and the
        // 1.5 evidence keys append AFTER it — see JsonReporterEvidenceTest
        // for the tail-order contract.)
        $this->assertSame(
            ['id', 'kind', 'exact', 'similarity', 'confidence', 'safety', 'impact',
             'pattern_tags', 'outlier_members', 'architectural_findings', 'signature',
             'members', 'pairs', 'holes', 'scope',
             'template', 'source', 'evidence_diff', 'hole_coverage', 're_expressed', 'hole_fragment'],
            array_keys($cluster),
        );
        $this->assertSame(ClusterScope::MULTI, $cluster['scope']);
        $this->assertSame(JsonSchemaSpec::SCHEMA_VERSION, $payload['schema_version']);

        // The schema annex documents the new cluster key.
        $this->assertArrayHasKey('scope', JsonSchemaSpec::clusterShape());
    }

    public function testOneRepoClusterEmitsSingleWithPerRepoScopeWords(): void
    {
        $c = new Cluster('S', [
            self::member('/srv/r1/src/A.php', 'Acme\\A', 'r1', 'r1'),
            self::member('/srv/r1/src/B.php', 'Acme\\B', 'r1', 'r1'),
        ], 0.95, false);
        $payload = (new JsonReporter())->build(new Report(1, 2, 0, [$c], Config::defaults([])));

        $this->assertSame(ClusterScope::SINGLE, $payload['clusters'][0]['scope']);
        $this->assertSame('r1', $payload['clusters'][0]['members'][0]['scope'],
            'multi-root runs put the repo label in the per-member scope');
    }

    public function testUnlabeledMembersEmitHonestEmptyRepo(): void
    {
        $c = new Cluster('U', [
            self::member('/ghost/A.php', 'Acme\\A', '', ''),
            self::member('/ghost/B.php', 'Acme\\B', '', ''),
        ], 0.95, false);
        $payload = (new JsonReporter())->build(new Report(0, 2, 0, [$c], Config::defaults([])));

        $this->assertSame('', $payload['clusters'][0]['members'][0]['repo']);
        $this->assertSame(ClusterScope::SINGLE, $payload['clusters'][0]['scope'],
            'unknown labels never fabricate multi (mirrors ClusterScopeTest)');
    }

    private static function member(string $file, string $namespace, string $repo, string $scope): Block
    {
        $b = new Block(
            file:      $file,
            range:     new LineRange(1, 10),
            kind:      'method',
            namespace: $namespace,
            class:     null,
            name:      'm',
            ast:       new Node\Stmt\Nop(),
        );
        $b->repo  = $repo;
        $b->scope = $scope;
        return $b;
    }
}
