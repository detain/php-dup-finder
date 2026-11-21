<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Persistence;

use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Persistence\ClusterCache;
use Phpdup\Util\LineRange;

/**
 * Item 1.4 cache re-pin (ruling §5b, plan §8.4 "ClusterCache v1→v2"):
 * pre-split blobs store single-linkage cluster sets with md5(root) ids
 * and must never be served by a split-aware run. These tests exercise
 * the version GATE itself — a stale-v1 payload that matches the current
 * config key and corpus hash byte-for-byte must still be refused, and
 * the same payload stamped v2 must load. A version constant with no
 * gate would fail the first assertion.
 */
final class ClusterCacheVersionTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/phpdup-cluster-cache-v-' . uniqid();
        mkdir($this->tmp, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->tmp) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            @unlink($this->tmp . '/' . $entry);
        }
        @rmdir($this->tmp);
    }

    private function block(string $id, string $hash): Block
    {
        $b = new Block(
            file: 'cached.php',
            range: new LineRange(1, 1),
            kind: 'function',
            namespace: null,
            class: null,
            name: null,
            ast: new \PhpParser\Node\Stmt\Return_(new \PhpParser\Node\Scalar\String_($id)),
        );
        $b->id = $id;
        $b->structuralHash = $hash;
        return $b;
    }

    /**
     * Mirror of ClusterCache::hashCorpus — the gate must be proven while
     * corpus_hash and config_key MATCH, isolating parser_version as the
     * only discriminator.
     *
     * @param list<Block> $blocks
     */
    private function corpusHash(array $blocks): string
    {
        $tokens = [];
        foreach ($blocks as $b) {
            $tokens[] = $b->id . '|' . $b->file . '|' . $b->structuralHash;
        }
        sort($tokens);
        return sha1(implode("\n", $tokens));
    }

    /**
     * @param list<Block>  $blocks
     * @param list<Cluster> $clusters
     */
    private function forgePayload(string $parserVersion, string $configKey, array $blocks, array $clusters): void
    {
        file_put_contents(
            $this->tmp . '/clusters.idx',
            serialize([
                'parser_version' => $parserVersion,
                'config_key'     => $configKey,
                'corpus_hash'    => $this->corpusHash($blocks),
                'clusters'       => $clusters,
            ]),
        );
    }

    public function testStaleV1PayloadIsRefusedByTheVersionGateAlone(): void
    {
        $blocks = [$this->block('a', 'h1'), $this->block('b', 'h2')];
        $cluster = new Cluster('Cdeadbeef', $blocks, 0.80, false);

        // config_key + corpus_hash are byte-identical to what the live
        // cache will ask for; ONLY parser_version is pre-split v1.
        $this->forgePayload('cluster-cache-v1', 'cfg-current', $blocks, [$cluster]);

        $cache = new ClusterCache($this->tmp, 'cfg-current');
        $this->assertNull($cache->load($blocks),
            'a v1 (pre-split, md5-root-id) snapshot must never be served, even with matching keys');
    }

    public function testSamePayloadLoadsUnderCurrentVersion(): void
    {
        $blocks = [$this->block('a', 'h1'), $this->block('b', 'h2')];
        $cluster = new Cluster('Ccafe1234', $blocks, 0.90, false);

        $this->forgePayload('cluster-cache-v2', 'cfg-current', $blocks, [$cluster]);

        $cache = new ClusterCache($this->tmp, 'cfg-current');
        $loaded = $cache->load($blocks);
        $this->assertNotNull($loaded, 'the only difference is the version stamp — v2 is the live contract');
        $this->assertCount(1, $loaded);
        $this->assertSame('Ccafe1234', $loaded[0]->id);
    }

    public function testSaveWritesTheBumpedVersionMarker(): void
    {
        $blocks = [$this->block('a', 'h1')];
        $cache = new ClusterCache($this->tmp, 'cfg');
        $cache->save($blocks, [new Cluster('C1', $blocks, 1.0, true)]);

        $payload = unserialize(
            (string) file_get_contents($this->tmp . '/clusters.idx'),
            ['allowed_classes' => \Phpdup\Persistence\SerializedClassAllowList::blockCacheClasses()],
        );
        $this->assertSame('cluster-cache-v2', $payload['parser_version'],
            'item 1.4 re-pin: fresh snapshots must carry the v2 marker');
    }

    public function testRoundTripPreservesMedoidIdAcrossTheCacheBoundary(): void
    {
        $blocks = [$this->block('a', 'h1'), $this->block('b', 'h2')];
        $cluster = new Cluster('Cab12345', $blocks, 0.93, false);
        $cluster->medoidId = 'a';

        $cache = new ClusterCache($this->tmp, 'cfg');
        $cache->save($blocks, [$cluster]);

        $loaded = $cache->load($blocks);
        $this->assertNotNull($loaded);
        $this->assertSame('a', $loaded[0]->medoidId,
            'the §5b medoid hub survives serialize()/unserialize() for downstream tightness consumers');
    }
}
