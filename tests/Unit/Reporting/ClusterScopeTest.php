<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Reporting\ClusterScope;
use Phpdup\Util\LineRange;

/**
 * Item 5.1 — cluster scope computation + the per-repo namespace
 * grouping that breaks the scoring double-dip. Synthetic clusters
 * (the RankerTest fake-cluster pattern), no pipeline needed.
 */
final class ClusterScopeTest extends TestCase
{
    public function testSingleRepoClusterIsSingle(): void
    {
        $c = $this->cluster('S', [
            self::member('/r1/A.php', 'Acme\\A', 'repo1'),
            self::member('/r1/B.php', 'Acme\\B', 'repo1'),
        ]);
        $this->assertSame(ClusterScope::SINGLE, ClusterScope::of($c));
        $this->assertFalse(ClusterScope::isCrossRepo($c));
        $this->assertSame(['repo1'], ClusterScope::repos($c));
    }

    public function testTwoDistinctReposIsMulti(): void
    {
        $c = $this->cluster('M', [
            self::member('/r1/A.php', 'Acme\\A', 'repo1'),
            self::member('/r2/B.php', 'Acme\\B', 'repo2'),
        ]);
        $this->assertSame(ClusterScope::MULTI, ClusterScope::of($c));
        $this->assertTrue(ClusterScope::isCrossRepo($c));
        $this->assertSame(['repo1', 'repo2'], ClusterScope::repos($c),
            'repos are sorted — payload order is deterministic');
    }

    public function testUnlabeledMembersNeverFabricateMulti(): void
    {
        $c = $this->cluster('U', [
            self::member('/r1/A.php', 'Acme\\A', 'repo1'),
            self::member('/ghost/B.php', 'Other\\B', ''),
        ]);
        $this->assertSame(ClusterScope::SINGLE, ClusterScope::of($c),
            'an unknown label is not evidence of a second repo');
    }

    public function testEmptyClusterDegradesToSingle(): void
    {
        $c = new Cluster('E', [], 1.0, true);
        $this->assertSame(ClusterScope::SINGLE, ClusterScope::of($c));
        $this->assertSame(0, ClusterScope::maxRepoNamespaceSpread($c));
    }

    public function testNamespaceSpreadGroupsPerRepo(): void
    {
        // The phlix double-dip shape: 3 subsystems × 2 repos. Raw ns
        // count is 6 (>3 ⇒ legacy −0.05/−0.10 penalties fire on the
        // MIRRORED structure itself); the per-repo spread is 3 — the
        // honest "one repo's worth of unrelated namespaces" reading.
        $c = $this->cluster('D', [
            self::member('/r1/A.php', 'Shop\\Admin', 'repo1'),
            self::member('/r1/B.php', 'Shop\\Api',   'repo1'),
            self::member('/r1/C.php', 'Shop\\Cron',  'repo1'),
            self::member('/r2/A.php', 'Shop2\\Admin', 'repo2'),
            self::member('/r2/B.php', 'Shop2\\Api',   'repo2'),
            self::member('/r2/C.php', 'Shop2\\Cron',  'repo2'),
        ]);
        $this->assertCount(6, self::rawNamespaceCount($c), 'fixture: raw spread really is 6');
        $this->assertSame(3, ClusterScope::maxRepoNamespaceSpread($c));
        $this->assertTrue(ClusterScope::isCrossRepo($c));
    }

    public function testSpreadDegradesExactlyToLegacyCountForOneRepo(): void
    {
        $c = $this->cluster('1', [
            self::member('/r1/A.php', 'Acme\\A', 'repo1'),
            self::member('/r1/B.php', 'Acme\\B', 'repo1'),
            self::member('/r1/C.php', 'Acme\\C', 'repo1'),
            self::member('/r1/D.php', 'Acme\\D', 'repo1'),
        ]);
        $this->assertSame(
            count(self::rawNamespaceCount($c)),
            ClusterScope::maxRepoNamespaceSpread($c),
            'single-repo clusters must read the same number the legacy path used',
        );
    }

    public function testUnlabeledMembersShareTheirOwnSpreadBucket(): void
    {
        $c = $this->cluster('W', [
            self::member('/r1/A.php', 'Acme\\A', ''),
            self::member('/r1/B.php', 'Acme\\B', ''),
            self::member('/r2/C.php', 'Other\\C', 'repo2'),
        ]);
        $this->assertSame(2, ClusterScope::maxRepoNamespaceSpread($c),
            "the '' bucket counts (max of 2 vs 1) — grouping is about the densest repo");
    }

    public function testFilterAllIsIdentity(): void
    {
        $clusters = [$this->cluster('X', [self::member('/a/A.php', 'A', 'a')])];
        $this->assertSame($clusters, ClusterScope::filter($clusters, ClusterScope::FILTER_ALL));
    }

    public function testFilterSingleMultiAndLabel(): void
    {
        $single = $this->cluster('S', [
            self::member('/a/A.php', 'A', 'alpha'),
            self::member('/a/B.php', 'B', 'alpha'),
        ]);
        $multi  = $this->cluster('M', [
            self::member('/a/C.php', 'C', 'alpha'),
            self::member('/b/D.php', 'D', 'beta'),
        ]);
        $clusters = [$single, $multi];

        $this->assertSame(['S'], array_map(fn(Cluster $c) => $c->id,
            ClusterScope::filter($clusters, ClusterScope::FILTER_SINGLE)));
        $this->assertSame(['M'], array_map(fn(Cluster $c) => $c->id,
            ClusterScope::filter($clusters, ClusterScope::FILTER_MULTI)));
        $this->assertSame(['S', 'M'], array_map(fn(Cluster $c) => $c->id,
            ClusterScope::filter($clusters, 'alpha')),
            'a repo label keeps every cluster touching that repo');
        $this->assertSame(['M'], array_map(fn(Cluster $c) => $c->id,
            ClusterScope::filter($clusters, 'beta')));
        $this->assertSame([], ClusterScope::filter($clusters, 'no-such-repo'));
    }

    private function cluster(string $id, array $members): Cluster
    {
        return new Cluster($id, $members, 1.0, true);
    }

    /** @return array<string, true> */
    private static function rawNamespaceCount(Cluster $c): array
    {
        $namespaces = [];
        foreach ($c->members as $m) {
            $namespaces[$m->namespace ?? ''] = true;
        }
        return $namespaces;
    }

    private static function member(string $file, string $namespace, string $repo): Block
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
        $b->scope = $repo;
        return $b;
    }
}
