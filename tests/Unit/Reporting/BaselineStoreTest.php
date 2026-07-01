<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use PhpParser\Node;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Reporting\BaselineStore;
use Phpdup\Util\LineRange;

final class BaselineStoreTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phpdup-baseline-test-' . uniqid();
        mkdir($this->tmpDir, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iter as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($dir);
    }

    public function testWriteAndReadBaselineRoundtrip(): void
    {
        $clusters = [
            $this->cluster('c1', [
                ['file' => 'src/Foo.php', 'start' => 10, 'end' => 20],
                ['file' => 'src/Bar.php', 'start' => 15, 'end' => 25],
            ], 100),
            $this->cluster('c2', [
                ['file' => 'src/Baz.php', 'start' => 5, 'end' => 15],
            ], 50),
        ];

        $path = $this->tmpDir . '/baseline.json';
        $store = new BaselineStore();
        $store->writeBaseline($clusters, $path);

        $this->assertFileExists($path);

        $entries = $store->readBaseline($path);

        $this->assertCount(2, $entries);

        // Sort by id for consistent comparison
        usort($entries, fn($a, $b) => $a['id'] <=> $b['id']);

        $this->assertSame('c1', $entries[0]['id']);
        $this->assertSame(100, $entries[0]['impact']);
        $this->assertCount(2, $entries[0]['member_hashes']);

        $this->assertSame('c2', $entries[1]['id']);
        $this->assertSame(50, $entries[1]['impact']);
        $this->assertCount(1, $entries[1]['member_hashes']);
    }

    public function testCompareBaselinesFindsNoNewClustersWhenIdentical(): void
    {
        $clusterA = $this->cluster('c1', [
            ['file' => 'src/Foo.php', 'start' => 10, 'end' => 20],
            ['file' => 'src/Bar.php', 'start' => 15, 'end' => 25],
        ], 100);

        $clusterB = $this->cluster('c2', [
            ['file' => 'src/Baz.php', 'start' => 5, 'end' => 15],
        ], 50);

        $currentEntries = $this->clustersToEntries([$clusterA, $clusterB]);
        $baselineEntries = $this->clustersToEntries([$clusterA, $clusterB]);

        $store = new BaselineStore();
        $newClusters = $store->compareBaselines($currentEntries, $baselineEntries);

        $this->assertSame([], $newClusters);
    }

    public function testCompareBaselinesFindsNewClustersWhenNotInBaseline(): void
    {
        $clusterA = $this->cluster('c1', [
            ['file' => 'src/Foo.php', 'start' => 10, 'end' => 20],
        ], 100);

        $clusterB = $this->cluster('c2', [
            ['file' => 'src/Bar.php', 'start' => 5, 'end' => 15],
        ], 50);

        $currentEntries = $this->clustersToEntries([$clusterA, $clusterB]);
        $baselineEntries = $this->clustersToEntries([$clusterA]); // c2 is missing

        $store = new BaselineStore();
        $newClusters = $store->compareBaselines($currentEntries, $baselineEntries);

        $this->assertCount(1, $newClusters);
        $this->assertSame('c2', $newClusters[0]['id']);
    }

    public function testCompareBaselinesDetectsPartialOverlap(): void
    {
        // Cluster with same ID but different member hashes should be detected as new
        $clusterA = $this->cluster('c1', [
            ['file' => 'src/Foo.php', 'start' => 10, 'end' => 20],
            ['file' => 'src/Bar.php', 'start' => 15, 'end' => 25],
        ], 100);

        $clusterB = $this->cluster('c1', [ // same id but NEW member
            ['file' => 'src/Foo.php', 'start' => 10, 'end' => 20],
            ['file' => 'src/Baz.php', 'start' => 5, 'end' => 15], // different member
        ], 100);

        $currentEntries = $this->clustersToEntries([$clusterB]);
        $baselineEntries = $this->clustersToEntries([$clusterA]);

        $store = new BaselineStore();
        $newClusters = $store->compareBaselines($currentEntries, $baselineEntries);

        $this->assertCount(1, $newClusters);
        $this->assertSame('c1', $newClusters[0]['id']);
    }

    public function testCompareBaselinesDetectsClusterWithAdditionalMembersAsNew(): void
    {
        // Cluster with more members than baseline IS new (has member Bar not in baseline)
        // The brief defines "new" as: a cluster whose member hashes aren't all
        // present in some baseline cluster. Current = [Foo, Bar], Baseline = [Foo].
        // Bar is NOT in baseline, so current IS new.
        $clusterA = $this->cluster('c1', [
            ['file' => 'src/Foo.php', 'start' => 10, 'end' => 20],
        ], 100);

        $clusterB = $this->cluster('c1', [
            ['file' => 'src/Foo.php', 'start' => 10, 'end' => 20],
            ['file' => 'src/Bar.php', 'start' => 15, 'end' => 25],
        ], 150);

        $currentEntries = $this->clustersToEntries([$clusterB]);
        $baselineEntries = $this->clustersToEntries([$clusterA]);

        $store = new BaselineStore();
        $newClusters = $store->compareBaselines($currentEntries, $baselineEntries);

        // Bar is not in baseline, so current cluster is NEW
        $this->assertCount(1, $newClusters);
        $this->assertSame('c1', $newClusters[0]['id']);
    }

    public function testCompareBaselinesSameMembersIsNotNew(): void
    {
        // Cluster with EXACTLY the same members as baseline is NOT new
        $clusterA = $this->cluster('c1', [
            ['file' => 'src/Foo.php', 'start' => 10, 'end' => 20],
            ['file' => 'src/Bar.php', 'start' => 15, 'end' => 25],
        ], 100);

        $clusterB = $this->cluster('c1', [
            ['file' => 'src/Foo.php', 'start' => 10, 'end' => 20],
            ['file' => 'src/Bar.php', 'start' => 15, 'end' => 25],
        ], 100);

        $currentEntries = $this->clustersToEntries([$clusterB]);
        $baselineEntries = $this->clustersToEntries([$clusterA]);

        $store = new BaselineStore();
        $newClusters = $store->compareBaselines($currentEntries, $baselineEntries);

        // Same members, so NOT new
        $this->assertSame([], $newClusters);
    }

    public function testCompareBaselinesSubsetMembersIsNotNew(): void
    {
        // Cluster with fewer/same members than baseline (subset) is NOT new
        // This is the "allow pruning" case: if current has Foo, baseline has [Foo, Bar],
        // then current's members ARE all present in baseline, so NOT new.
        $clusterA = $this->cluster('c1', [
            ['file' => 'src/Foo.php', 'start' => 10, 'end' => 20],
            ['file' => 'src/Bar.php', 'start' => 15, 'end' => 25],
        ], 100);

        $clusterB = $this->cluster('c1', [
            ['file' => 'src/Foo.php', 'start' => 10, 'end' => 20],
        ], 50);

        $currentEntries = $this->clustersToEntries([$clusterB]);
        $baselineEntries = $this->clustersToEntries([$clusterA]);

        $store = new BaselineStore();
        $newClusters = $store->compareBaselines($currentEntries, $baselineEntries);

        // All current members (Foo) ARE present in baseline (Foo, Bar), so NOT new
        $this->assertSame([], $newClusters);
    }

    public function testCompareBaselinesWithEmptyBaselineReturnsAllAsNew(): void
    {
        $clusterA = $this->cluster('c1', [
            ['file' => 'src/Foo.php', 'start' => 10, 'end' => 20],
        ], 100);

        $currentEntries = $this->clustersToEntries([$clusterA]);
        $baselineEntries = [];

        $store = new BaselineStore();
        $newClusters = $store->compareBaselines($currentEntries, $baselineEntries);

        $this->assertCount(1, $newClusters);
    }

    public function testReadBaselineThrowsOnMissingFile(): void
    {
        $store = new BaselineStore();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Baseline file not found');
        $store->readBaseline($this->tmpDir . '/nonexistent.json');
    }

    public function testReadBaselineThrowsOnInvalidJson(): void
    {
        $path = $this->tmpDir . '/invalid.json';
        file_put_contents($path, 'not valid json');

        $store = new BaselineStore();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not valid JSON');
        $store->readBaseline($path);
    }

    public function testReadBaselineThrowsOnMissingVersion(): void
    {
        $path = $this->tmpDir . '/no-version.json';
        file_put_contents($path, json_encode(['clusters' => []]));

        $store = new BaselineStore();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid structure');
        $store->readBaseline($path);
    }

    public function testReadBaselineThrowsOnVersionMismatch(): void
    {
        $path = $this->tmpDir . '/wrong-version.json';
        file_put_contents($path, json_encode(['version' => 99, 'clusters' => []]));

        $store = new BaselineStore();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('version mismatch');
        $store->readBaseline($path);
    }

    public function testComputeBlockHashIsStable(): void
    {
        $block = new Block(
            file: 'src/Foo.php',
            range: new LineRange(10, 20),
            kind: 'method',
            namespace: null,
            class: null,
            name: 'bar',
            ast: new Node\Stmt\Nop(),
        );

        $store = new BaselineStore();
        $hash1 = $store->computeBlockHash($block);
        $hash2 = $store->computeBlockHash($block);

        $this->assertSame($hash1, $hash2);
        $this->assertStringStartsWith('sha256:', $hash1);
    }

    public function testComputeBlockHashDiffersForDifferentBlocks(): void
    {
        $block1 = new Block(
            file: 'src/Foo.php',
            range: new LineRange(10, 20),
            kind: 'method',
            namespace: null,
            class: null,
            name: 'bar',
            ast: new Node\Stmt\Nop(),
        );

        $block2 = new Block(
            file: 'src/Foo.php',
            range: new LineRange(30, 40), // different range
            kind: 'method',
            namespace: null,
            class: null,
            name: 'bar',
            ast: new Node\Stmt\Nop(),
        );

        $store = new BaselineStore();
        $hash1 = $store->computeBlockHash($block1);
        $hash2 = $store->computeBlockHash($block2);

        $this->assertNotSame($hash1, $hash2);
    }

    /**
     * Build a Cluster from an array of member specs.
     *
     * @param list<array{file: string, start: int, end: int}> $memberSpecs
     */
    private function cluster(string $id, array $memberSpecs, int $impact): Cluster
    {
        $blocks = [];
        foreach ($memberSpecs as $spec) {
            $blocks[] = new Block(
                file: $spec['file'],
                range: new LineRange($spec['start'], $spec['end']),
                kind: 'method',
                namespace: null,
                class: null,
                name: 'method',
                ast: new Node\Stmt\Nop(),
            );
        }
        $cluster = new Cluster($id, $blocks, 1.0, false);
        $cluster->impact = $impact;
        return $cluster;
    }

    /**
     * Convert a list of Cluster objects to baseline entry format.
     *
     * @param list<Cluster> $clusters
     * @return list<array{id: string, impact: int, member_hashes: list<string>}>
     */
    private function clustersToEntries(array $clusters): array
    {
        $store = new BaselineStore();
        $entries = [];
        foreach ($clusters as $cluster) {
            $memberHashes = [];
            foreach ($cluster->members as $block) {
                $memberHashes[] = $store->computeBlockHash($block);
            }
            sort($memberHashes);
            $entries[] = [
                'id' => $cluster->id,
                'impact' => $cluster->impact,
                'member_hashes' => $memberHashes,
            ];
        }
        return $entries;
    }
}
