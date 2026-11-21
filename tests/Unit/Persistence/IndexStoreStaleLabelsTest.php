<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Persistence;

use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstParser;
use Phpdup\Parsing\CacheBlob;
use Phpdup\Persistence\IndexStore;
use Phpdup\Persistence\SerializedClassAllowList;
use Phpdup\Scanning\RepoLabels;

/**
 * Item 5.1 — the stale-cache posture for repo/scope labels.
 *
 * Design law: labels are RUN CONTEXT. PreprocessStage stamps them
 * AFTER the cache-save pass, so `.idx` blobs never carry labels and
 * no PARSER_VERSION bump was required. The corollary this file pins:
 * a blob written by a pre-5.1 phpdup — where the two Block properties
 * did not exist at all — must still load (no gratuitous refusal, no
 * silently-empty consumer), because every restored block is stamped
 * fresh before anything downstream can read a label.
 */
final class IndexStoreStaleLabelsTest extends TestCase
{
    private string $cacheDir;
    private string $sourceFile;

    protected function setUp(): void
    {
        $this->cacheDir   = sys_get_temp_dir() . '/phpdup-51-store-' . uniqid();
        $this->sourceFile = sys_get_temp_dir() . '/phpdup-51-src-' . uniqid() . '.php';
        file_put_contents(
            $this->sourceFile,
            '<?php class A { public function f($x) { if ($x > 10) { return "a"; } return "b"; } }',
        );
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

    public function testPre51BlobStillLoadsAndStampsRestoreLabels(): void
    {
        $blocks = $this->extract($this->sourceFile);
        $this->assertNotEmpty($blocks);

        $store = new IndexStore($this->cacheDir, 'cfg-key-1');
        $store->save($this->sourceFile, $blocks);

        // Surgery: rewrite the on-disk blob with the repo/scope pair
        // REMOVED from every Block object — the exact value-object shape
        // a pre-5.1 writer produced (declared properties absent from a
        // serialization simply do not appear; PHP restores class
        // defaults). public unset() gives us that shape honestly.
        // Since shell-S18 the production writer frames the payload
        // behind a plain-text header, so the surgery splices header
        // from framed tail, unframes to reach the payload, and
        // re-frames on the way back: the header bytes ride through
        // verbatim because only the Block objects are stale-labelled —
        // the CONTAINER is the current shape, exactly what a post-S18
        // pre-5.1-blocks blob would look like on disk.
        $cacheFile = $this->cacheDir . '/' . sha1($this->sourceFile) . '.idx';
        $this->assertFileExists($cacheFile);
        $bytes = (string) file_get_contents($cacheFile);
        $this->assertStringStartsWith('PHPDUP-IDX1' . "\n", $bytes,
            'pin the framed shape the surgery reads: header bytes survive verbatim');
        $sep = strpos($bytes, "\n\n");
        $this->assertNotFalse($sep, 'the production header must terminate for the splice');
        $header = substr($bytes, 0, $sep + 2);
        $blob = CacheBlob::unframe(substr($bytes, $sep + 2));
        $this->assertNotNull($blob, 'the production payload is exactly length-framed');
        $payload = unserialize($blob, [
            'allowed_classes' => SerializedClassAllowList::blockCacheClasses(),
        ]);
        $this->assertIsArray($payload);
        $pre51 = [];
        foreach ($payload['blocks'] as $block) {
            unset($block->repo, $block->scope);
            $this->assertStringNotContainsString('s:4:"repo";', serialize($block),
                'surgery precondition: the property pair is truly gone from the blob');
            $pre51[] = $block;
        }
        $payload['blocks'] = $pre51;
        file_put_contents($cacheFile, $header . CacheBlob::frame(serialize($payload)));
        $this->assertStringStartsWith($header, (string) file_get_contents($cacheFile),
            'the rewritten blob keeps the original header bytes untouched');

        // Refusal would be WRONG here: the payload shape is otherwise
        // current, and labels are recomputed anyway — the load must
        // HIT, restoring `''` defaults through the class definition.
        $loaded = (new IndexStore($this->cacheDir, 'cfg-key-1'))->load($this->sourceFile);
        $this->assertNotNull($loaded, 'a stale-labels-free blob must not be refused (no v6 bump)');
        $this->assertCount(count($blocks), $loaded);
        $this->assertSame($blocks[0]->structuralHash, $loaded[0]->structuralHash);
        foreach ($loaded as $block) {
            $this->assertSame('', $block->repo,
                'restored blocks carry the empty default — nobody may trust that yet');
            $this->assertSame('', $block->scope);
        }

        // ...and the stamping law: the pipeline consumer re-labels from
        // THIS run's roots before anything reads them, so the empty
        // default is never observable downstream. The source file lives
        // in the system temp dir — use its directory as the run root so
        // the expected label is host-derived, never a literal.
        $runRoot = dirname($this->sourceFile);
        RepoLabels::stampAll($loaded, [$runRoot]);
        $this->assertSame(basename($runRoot), $loaded[0]->repo);
        $this->assertSame(RepoLabels::SCOPE_SINGLE, $loaded[0]->scope);
    }

    public function testBlobSavedAfterStampingStillStampsFreshNextRun(): void
    {
        // Defense-in-depth pin: even if a future writer persists labels
        // (or a blob from an in-process stamped run is reused), the
        // next run's stamp overwrites whatever it carried.
        $blocks = $this->extract($this->sourceFile);
        foreach ($blocks as $b) {
            $b->repo  = 'saving-run';
            $b->scope = 'saving-run';
        }
        $store = new IndexStore($this->cacheDir, 'cfg-key-1');
        $store->save($this->sourceFile, $blocks);

        $loaded = (new IndexStore($this->cacheDir, 'cfg-key-1'))->load($this->sourceFile);
        $this->assertNotNull($loaded);
        $runRoot = dirname($this->sourceFile);
        RepoLabels::stampAll($loaded, [$runRoot]);
        $this->assertSame(basename($runRoot), $loaded[0]->repo,
            'labels always speak for the CURRENT run, never the saving run');
    }

    /** @return list<Block> */
    private function extract(string $file): array
    {
        $stmts     = (new AstParser())->parseFile($file);
        $extractor = new BlockExtractor(minSize: 1);
        $blocks    = $extractor->extract($file, $stmts);
        $normalizer = new Normalizer('aggressive');
        foreach ($blocks as $b) {
            $normalizer->normalize($b);
            $b->structuralHash = sha1(spl_object_hash($b->canonical));
        }
        return $blocks;
    }
}
