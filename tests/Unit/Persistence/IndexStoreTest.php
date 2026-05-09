<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Persistence;

use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstParser;
use Phpdup\Persistence\IndexStore;

final class IndexStoreTest extends TestCase
{
    private string $cacheDir;
    private string $sourceFile;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/phpdup-store-' . uniqid();
        $this->sourceFile = sys_get_temp_dir() . '/phpdup-src-' . uniqid() . '.php';
        file_put_contents($this->sourceFile, '<?php class A { public function f($x) { if ($x > 10) { return "a"; } return "b"; } }');
    }

    protected function tearDown(): void
    {
        @unlink($this->sourceFile);
        if (is_dir($this->cacheDir)) {
            foreach (glob($this->cacheDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->cacheDir);
        }
    }

    public function testSaveAndLoadRoundTrips(): void
    {
        $blocks = $this->extract($this->sourceFile);
        $this->assertNotEmpty($blocks);

        $store = new IndexStore($this->cacheDir, 'cfg-key-1');
        $store->save($this->sourceFile, $blocks);

        $loaded = $store->load($this->sourceFile);
        $this->assertNotNull($loaded);
        $this->assertCount(count($blocks), $loaded);
        $this->assertSame($blocks[0]->structuralHash, $loaded[0]->structuralHash);
    }

    public function testFileChangeInvalidates(): void
    {
        $blocks = $this->extract($this->sourceFile);
        $store = new IndexStore($this->cacheDir, 'cfg-key-1');
        $store->save($this->sourceFile, $blocks);

        sleep(1);
        file_put_contents($this->sourceFile, '<?php class A { public function f($x) { return $x; } }');
        $this->assertNull($store->load($this->sourceFile),
            'modified source must invalidate the snapshot');
    }

    public function testConfigKeyChangeInvalidates(): void
    {
        $blocks = $this->extract($this->sourceFile);
        $store1 = new IndexStore($this->cacheDir, 'cfg-key-1');
        $store1->save($this->sourceFile, $blocks);

        $store2 = new IndexStore($this->cacheDir, 'cfg-key-DIFFERENT');
        $this->assertNull($store2->load($this->sourceFile),
            'config-key mismatch must miss the cache');
    }

    /** @return list<Block> */
    private function extract(string $file): array
    {
        $stmts = (new AstParser())->parseFile($file);
        $extractor = new BlockExtractor(minSize: 1);
        $blocks = $extractor->extract($file, $stmts);
        $normalizer = new Normalizer('aggressive');
        foreach ($blocks as $b) {
            $normalizer->normalize($b);
            $b->structuralHash = sha1(spl_object_hash($b->canonical));
        }
        return $blocks;
    }
}
