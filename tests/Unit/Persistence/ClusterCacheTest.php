<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Persistence;

use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Persistence\ClusterCache;
use Phpdup\Util\LineRange;

final class ClusterCacheTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/phpdup-cluster-cache-' . uniqid();
        mkdir($this->tmp, 0o775, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tmp)) return;
        foreach (scandir($this->tmp) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            @unlink($this->tmp . '/' . $entry);
        }
        @rmdir($this->tmp);
    }

    public function testRoundTripReturnsEquivalentClusters(): void
    {
        $blocks = [$this->mkblock('a', 'h1'), $this->mkblock('b', 'h2')];
        $clusters = [new Cluster('C1', $blocks, 1.0, true)];

        $cache = new ClusterCache($this->tmp, 'cfg-key-1');
        $cache->save($blocks, $clusters);

        $loaded = $cache->load($blocks);
        $this->assertNotNull($loaded);
        $this->assertCount(1, $loaded);
        $this->assertSame('C1', $loaded[0]->id);
    }

    public function testCorpusChangeInvalidatesCache(): void
    {
        $blocks = [$this->mkblock('a', 'h1')];
        $cache = new ClusterCache($this->tmp, 'cfg-key-1');
        $cache->save($blocks, [new Cluster('C1', $blocks, 1.0, true)]);

        // Change one block's structuralHash.
        $blocks[0]->structuralHash = 'h1-modified';
        $this->assertNull($cache->load($blocks));
    }

    public function testConfigKeyChangeInvalidatesCache(): void
    {
        $blocks = [$this->mkblock('a', 'h1')];
        (new ClusterCache($this->tmp, 'cfg-A'))->save($blocks, [new Cluster('C', $blocks, 1.0, true)]);
        $this->assertNull((new ClusterCache($this->tmp, 'cfg-B'))->load($blocks));
    }

    public function testGeneralizedAstStrippedFromSerializedPayload(): void
    {
        $blocks = [$this->mkblock('a', 'h1')];
        $cluster = new Cluster('C', $blocks, 1.0, true);
        // The raw Cluster has generalizedAst possibly set; the cache
        // should never round-trip a heavy AST through serialize().
        $cache = new ClusterCache($this->tmp, 'cfg');
        $cache->save($blocks, [$cluster]);
        $loaded = $cache->load($blocks);
        $this->assertNotNull($loaded);
        $this->assertNull($loaded[0]->generalizedAst);
    }

    public function testEmptyCacheDirReturnsNull(): void
    {
        $blocks = [$this->mkblock('a', 'h1')];
        $cache = new ClusterCache($this->tmp, 'cfg');
        $this->assertNull($cache->load($blocks));
    }

    private function mkblock(string $id, string $hash): Block
    {
        $stmts = (new \Phpdup\Parsing\AstParser())->parseCode('<?php $x;');
        $block = new Block(
            file: 'x.php',
            range: new LineRange(1, 1),
            kind: 'method',
            namespace: null, class: null, name: null,
            ast: $stmts[0],
        );
        $block->id = $id;
        $block->structuralHash = $hash;
        return $block;
    }
}
