<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Persistence;

use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Persistence\ClusterCache;
use Phpdup\Util\LineRange;

/**
 * Cache version-gate semantics.
 *
 * cluster-cache-v2 (item 1.4, ruling §5b): pre-split v1 blobs store
 * single-linkage cluster sets with md5(root) ids and must never be
 * served by a split-aware run. cluster-cache-v3 (item 4.4, hazard-4):
 * v2's corpus hash baked the scan-index block id into every token, so
 * the tokens are now file|structuralHash only — v2 blobs must likewise
 * never be served. cluster-cache-v4 (phpver-fix): finding ids moved to
 * scan-root-relative member paths, so v3 blobs holding absolute-path-era
 * ids must never be served. These tests exercise the version GATE itself — a
 * stale payload that matches the current config key and corpus hash
 * byte-for-byte must still be refused, and the same payload stamped
 * with the live version must load. A version constant with no gate
 * would fail the first assertion.
 *
 * shell-S18 (header-before-payload): the gate now reads the live
 * version stamp from the framed header's parser_version line. The
 * forge helper below emits the post-S18 on-wire shape. The pre-S18
 * raw-serialize envelope cannot carry any stamp this loader trusts — a
 * blob in the legacy format misses wholesale (one-time self-healing
 * invalidation), which the last test pins separately.
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
     * Mirror of ClusterCache::hashCorpus (v3: structural member set, no
     * block ids) — the gate must be proven while corpus_hash and
     * config_key MATCH, isolating parser_version as the only discriminator.
     *
     * @param list<Block> $blocks
     */
    private function corpusHash(array $blocks): string
    {
        $tokens = [];
        foreach ($blocks as $b) {
            $tokens[] = $b->file . '|' . $b->structuralHash;
        }
        sort($tokens);
        return sha1(implode("\n", $tokens));
    }

    /**
     * Write the post-S18 on-wire shape: plain-text meta header lines,
     * blank-line terminator, then the length-framed cluster payload
     * (the same framing CacheBlob/AstCache use).
     *
     * @param list<Block>  $blocks
     * @param list<Cluster> $clusters
     */
    private function forgePayload(string $parserVersion, string $configKey, array $blocks, array $clusters): void
    {
        $payload = serialize(['clusters' => $clusters]);
        file_put_contents(
            $this->tmp . '/clusters.idx',
            "PHPDUP-CCACHE1\n"
            . 'parser_version:' . $parserVersion . "\n"
            . 'config_key:'     . $configKey      . "\n"
            . 'corpus_hash:'    . $this->corpusHash($blocks) . "\n"
            . "\n"
            . strlen($payload) . ':' . $payload,
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

    public function testStaleV2PayloadIsRefusedByTheVersionGateAlone(): void
    {
        $blocks = [$this->block('a', 'h1'), $this->block('b', 'h2')];
        $cluster = new Cluster('F0123456789abcdef', $blocks, 0.80, false);

        // Corpus hash computed with the LIVE v3 mirror; ONLY the version
        // stamp is stale. v2 tokens carried block ids — a v2-era writer and
        // a v3-era writer disagree on corpus_hash for any renumbered scan,
        // so v2 blobs are refused wholesale, not renegotiated.
        $this->forgePayload('cluster-cache-v2', 'cfg-current', $blocks, [$cluster]);

        $cache = new ClusterCache($this->tmp, 'cfg-current');
        $this->assertNull($cache->load($blocks),
            'a v2 (id-baked corpus hash) snapshot must never be served, even with matching keys');
    }

    public function testSamePayloadLoadsUnderCurrentVersion(): void
    {
        $blocks = [$this->block('a', 'h1'), $this->block('b', 'h2')];
        $cluster = new Cluster('Ccafe1234', $blocks, 0.90, false);

        $this->forgePayload('cluster-cache-v4', 'cfg-current', $blocks, [$cluster]);

        $cache = new ClusterCache($this->tmp, 'cfg-current');
        $loaded = $cache->load($blocks);
        $this->assertNotNull($loaded, 'the only difference is the version stamp — v4 is the live contract');
        $this->assertCount(1, $loaded);
        $this->assertSame('Ccafe1234', $loaded[0]->id);
    }

    /**
     * Hazard-4 at the cache boundary: the corpus key is the structural
     * member set. Renumbering block ids (a re-scan that finds files in a
     * different order) must NOT invalidate a v4 snapshot.
     */
    public function testBlockIdRenumberingDoesNotInvalidateCache(): void
    {
        $blocks = [$this->block('b001', 'h1'), $this->block('b002', 'h2')];
        $cache = new ClusterCache($this->tmp, 'cfg');
        $cache->save($blocks, [new Cluster('F0123456789abcdef', $blocks, 1.0, true)]);

        $renumbered = [$this->block('b900', 'h1'), $this->block('b901', 'h2')];
        $loaded = $cache->load($renumbered);

        $this->assertNotNull($loaded, 'corpus key must ignore scan-index block ids');
        $this->assertSame('F0123456789abcdef', $loaded[0]->id);
    }

    public function testSaveWritesTheBumpedVersionMarker(): void
    {
        $blocks = [$this->block('a', 'h1')];
        $cache = new ClusterCache($this->tmp, 'cfg');
        $cache->save($blocks, [new Cluster('C1', $blocks, 1.0, true)]);

        // shell-S18: the live version stamp rides the plain-text header,
        // readable without touching the framed payload at all.
        $prefix = (string) file_get_contents($this->tmp . '/clusters.idx', false, null, 0, 4096);
        $this->assertStringStartsWith("PHPDUP-CCACHE1\n", $prefix);
        $this->assertStringContainsString("parser_version:cluster-cache-v4\n", $prefix,
            'item 4.4 / phpver-fix re-pin: fresh snapshots must carry the v4 (id-free corpus) marker');
    }

    public function testPreS18RawFormatBlobMissesWholesale(): void
    {
        $blocks = [$this->block('a', 'h1')];
        $cluster = new Cluster('Cfeedface', $blocks, 1.0, true);

        // The exact pre-S18 envelope: raw serialize() of meta+clusters,
        // stamped v4, keys matching. The format magic refuses it before
        // any of that is consulted — one-time invalidation, then heal.
        file_put_contents(
            $this->tmp . '/clusters.idx',
            serialize([
                'parser_version' => 'cluster-cache-v4',
                'config_key'     => 'cfg-current',
                'corpus_hash'    => $this->corpusHash($blocks),
                'clusters'       => [$cluster],
            ]),
        );

        $cache = new ClusterCache($this->tmp, 'cfg-current');
        $this->assertNull($cache->load($blocks),
            'legacy raw-serialize blobs must clean-miss even when every field matches (no trusted meta to read)');

        $cache->save($blocks, [$cluster]);
        $loaded = (new ClusterCache($this->tmp, 'cfg-current'))->load($blocks);
        $this->assertNotNull($loaded, 'legacy miss must be one-time: the rewrite heals the snapshot');
        $this->assertSame('Cfeedface', $loaded[0]->id);
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
