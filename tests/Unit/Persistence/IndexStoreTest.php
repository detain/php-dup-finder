<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Persistence;

use PHPUnit\Framework\TestCase;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstParser;
use Phpdup\Persistence\IndexStore;

/**
 * IndexStore snapshot round-trip and gate semantics.
 *
 * shell-S18 (finding shell-009) moved the meta check ahead of the
 * payload: parser_version / config_key / file_hash are validated from a
 * bounded plain-text header read, and the framed block list is opened
 * only when every gate passes. The "miss without deserializing" tests
 * below prove that ordering by hiding a large, VALID serialized block
 * list behind a mismatched header: a header-only miss returns in a
 * small fraction of the measured decode cost (self-calibrated per
 * box), and — because the poison decodes to a non-null block list — a
 * payload read would also flip the assertNull.
 */
final class IndexStoreTest extends TestCase
{
    /** Current on-wire parser_version stamp (mirrors IndexStore's private const). */
    private const LIVE_VERSION = 'parser-v6-namesentinel';

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
        $this->purgeDir($this->cacheDir);
    }

    private function purgeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->purgeDir($path) : @unlink($path);
        }
        @rmdir($dir);
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

    // ------------------------------------------------------------------
    // shell-S18: header-before-payload (finding shell-009)
    // ------------------------------------------------------------------

    public function testStaleParserVersionMissesWithoutDeserializingPayload(): void
    {
        $this->assertHeaderOnlyMiss([
            'file_hash'      => (string) sha1_file($this->sourceFile),
            'parser_version' => 'parser-v0-ancient',
            'config_key'     => 'cfg-key-1',
        ]);
    }

    public function testFlippedConfigKeyMissesWithoutDeserializingPayload(): void
    {
        $this->assertHeaderOnlyMiss([
            'file_hash'      => (string) sha1_file($this->sourceFile),
            'parser_version' => self::LIVE_VERSION,
            'config_key'     => 'cfg-key-OTHER',
        ]);
    }

    public function testMismatchedFileHashMissesWithoutDeserializingPayload(): void
    {
        $this->assertHeaderOnlyMiss([
            'file_hash'      => 'b' . str_repeat('0', 39),
            'parser_version' => self::LIVE_VERSION,
            'config_key'     => 'cfg-key-1',
        ]);
    }

    public function testMalformedHeadersDegradeToMissNotError(): void
    {
        $current = [
            'file_hash'      => (string) sha1_file($this->sourceFile),
            'parser_version' => self::LIVE_VERSION,
            'config_key'     => 'cfg-key-1',
        ];
        $blocks0  = [];
        $small    = serialize(['blocks' => $blocks0]);   // payload never reached on these paths — keep it tiny
        $framed   = strlen($small) . ':' . $small;
        $header   = $this->headerBytes($current);
        $liveSha = $current['file_hash'];

        $cases = [
            'truncated magic line only'  => 'PHPDUP-IDX1',
            'no header terminator'       => $header[0] . "file_hash:$liveSha\nparser_version:" . self::LIVE_VERSION . "\nconfig_key:cfg-key-1",
            'malformed line (no colon)'  => "PHPDUP-IDX1\nfile_hash:$liveSha\nbroken line\n\n$framed",
            'missing required keys'      => "PHPDUP-IDX1\njunk_value\n\n$framed",
            'non-hex file_hash'          => "PHPDUP-IDX1\nfile_hash:not-a-sha\nparser_version:" . self::LIVE_VERSION . "\nconfig_key:cfg-key-1\n\n$framed",
            'truncated framing (empty)'  => $header[0],
            'appended bytes after frame' => $header[0] . $framed . 'GARBAGE',
        ];

        foreach ($cases as $label => $bytes) {
            $dir = sys_get_temp_dir() . '/phpdup-store-malformed-' . $label;
            $src = sys_get_temp_dir() . '/phpdup-src-malformed-' . $label . '.php';
            file_put_contents($src, '<?php class B { public function g() { return 1; } }');
            mkdir($dir, 0o700, true);
            file_put_contents($dir . '/' . sha1($src) . '.idx', $bytes);

            $store = new IndexStore($dir, 'cfg-key-1');
            $this->assertNull($store->load($src), "malformed cache [$label] must miss, not error");

            @unlink($src);
            $this->purgeDir($dir);
        }
    }

    public function testOldRawSerializeBlobMissesCleanlyThenSelfHeals(): void
    {
        $blocks = $this->extract($this->sourceFile);
        $this->assertNotEmpty($blocks);

        $store = new IndexStore($this->cacheDir, 'cfg-key-1');

        // Exact pre-S18 writer bytes: one raw serialize() of meta+blocks,
        // every field up to date — under the old loader this was a HIT.
        $legacy = serialize([
            'file_hash'      => (string) sha1_file($this->sourceFile),
            'parser_version' => self::LIVE_VERSION,
            'config_key'     => 'cfg-key-1',
            'blocks'         => $blocks,
        ]);
        $this->assertStringStartsWith('a:', $legacy, 'precondition: old format is raw serialize');
        file_put_contents($this->cacheFilePath(), $legacy);

        $this->assertNull($store->load($this->sourceFile),
            'shell-S18 acceptance: old-format blobs land on a clean one-time miss');

        // save() rewrites in the framed format; the very next load hits.
        $store->save($this->sourceFile, $blocks);
        $this->assertStringStartsWith("PHPDUP-IDX1\n", (string) file_get_contents($this->cacheFilePath()),
            'fresh snapshots must carry the framed-header magic');

        $loaded = (new IndexStore($this->cacheDir, 'cfg-key-1'))->load($this->sourceFile);
        $this->assertNotNull($loaded, 'cache must self-heal: one miss, then hits again');
        $this->assertCount(count($blocks), $loaded);
        $this->assertSame($blocks[0]->structuralHash, $loaded[0]->structuralHash);
    }

    // ------------------------------------------------------------------
    // shell-S18: single source hash per file per run (finding shell-009)
    // ------------------------------------------------------------------

    public function testSaveWritesCallerSuppliedHashVerbatim(): void
    {
        $blocks = $this->extract($this->sourceFile);
        $custom = 'b' . str_repeat('0', 39);

        $store = new IndexStore($this->cacheDir, 'cfg-key-1');
        $store->save($this->sourceFile, $blocks, $custom);

        $prefix = (string) file_get_contents($this->cacheFilePath(), false, null, 0, 4096);
        $this->assertStringContainsString("file_hash:$custom\n", $prefix,
            'a caller-supplied hash must be persisted verbatim — save() did not re-hash the source');

        $this->assertNull((new IndexStore($this->cacheDir, 'cfg-key-1'))->load($this->sourceFile),
            'a snapshot keyed by a foreign hash must miss against the live file (self-healing next save)');
    }

    public function testSaveAfterLookupMissReusesTheLoadTimeHashNoSecondRead(): void
    {
        $blocks       = $this->extract($this->sourceFile);
        $hashAtLookup = (string) sha1_file($this->sourceFile);

        $this->assertNotNull($hashAtLookup);
        $this->forgeBlob([
            'file_hash'      => str_repeat('a', 40),          // stale → load computes and memoizes the live hash
            'parser_version' => self::LIVE_VERSION,
            'config_key'     => 'cfg-key-1',
        ], serialize(['blocks' => []]));

        $store = new IndexStore($this->cacheDir, 'cfg-key-1');
        $this->assertNull($store->load($this->sourceFile), 'precondition: hash mismatch misses');

        // Mutate the source AFTER the lookup. If save() hashed the file
        // itself, the header would carry the NEW content's hash; reusing
        // load()'s memoized hash proves the single-hash posture — and is
        // the safer direction (a mismatching header always misses).
        file_put_contents($this->sourceFile, '<?php // changed after the lookup');
        $store->save($this->sourceFile, $blocks);

        $prefix = (string) file_get_contents($this->cacheFilePath(), false, null, 0, 4096);
        $this->assertStringContainsString("file_hash:$hashAtLookup\n", $prefix,
            'save() must persist the hash load() computed, not a fresh sha1_file of the mutated source');
        $this->assertNotSame($hashAtLookup, sha1_file($this->sourceFile),
            'non-vacuity: the file really did change after the lookup');
    }

    public function testColdSaveFallsBackToASingleInternalHash(): void
    {
        $blocks = $this->extract($this->sourceFile);
        $store  = new IndexStore($this->cacheDir, 'cfg-key-1');
        $store->save($this->sourceFile, $blocks);   // no prior load → internal sha1_file

        $prefix = (string) file_get_contents($this->cacheFilePath(), false, null, 0, 4096);
        $this->assertStringContainsString('file_hash:' . sha1_file($this->sourceFile) . "\n", $prefix,
            'a hash-less cold save must still stamp the true content hash');
        $this->assertNotNull((new IndexStore($this->cacheDir, 'cfg-key-1'))->load($this->sourceFile));
    }

    // ------------------------------------------------------------------
    // shell-S18: private-bytes posture (S23a house pattern)
    // ------------------------------------------------------------------

    public function testFreshCacheDirAndBlobsArePrivate(): void
    {
        $blocks = $this->extract($this->sourceFile);
        $deep   = $this->cacheDir . '/nested/deeper';

        $store = new IndexStore($deep, 'cfg-key-1');
        $this->assertDirectoryExists($deep);
        $this->assertSame('0700', substr(sprintf('%o', (int) fileperms($deep)), -4),
            'S23a coverage-gap seat: the cache dir must not be group-writable');

        $store->save($this->sourceFile, $blocks);
        $this->assertSame('0600', substr(sprintf('%o', (int) fileperms($deep . '/' . sha1($this->sourceFile) . '.idx')), -4),
            'cache blobs carry only replayable derived data — owner-only, like AstCache payloads');
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * Forge a framed cache file behind a mismatched header and prove the
     * load returns fast — the block-list poison behind it must never be
     * deserialized (shell-009: the old loader paid the full unserialize
     * before consulting any gate).
     *
     * @param array<string,string> $meta
     */
    private function assertHeaderOnlyMiss(array $meta): void
    {
        $poison = self::poisonPayload();

        // Self-calibrate the box: measure the decode the loader must AVOID.
        $t0 = microtime(true);
        $decoded = @unserialize($poison);
        $decodeMs = (microtime(true) - $t0) * 1000.0;
        $this->assertIsArray($decoded, 'poison payload must be a valid serialized block list');
        $this->assertGreaterThan(15.0, $decodeMs,
            'poison payload must cost measurable milliseconds to decode (test-env guard)');

        $this->forgeBlob($meta, $poison);

        $store = new IndexStore($this->cacheDir, 'cfg-key-1');
        $t1 = microtime(true);
        $result = $store->load($this->sourceFile);
        $missMs = (microtime(true) - $t1) * 1000.0;

        $this->assertNull($result,
            'the poison payload decodes to a NON-null block list — a returned value proves the loader read the payload');
        $this->assertLessThan($decodeMs / 3.0, $missMs,
            sprintf('header-only miss (%.1fms) must not pay the payload decode cost (%.1fms)', $missMs, $decodeMs));
    }

    private function cacheFilePath(): string
    {
        return $this->cacheDir . '/' . sha1($this->sourceFile) . '.idx';
    }

    /** @param array<string,string> $meta */
    private function forgeBlob(array $meta, string $payloadBytes): void
    {
        [$header, ] = $this->headerBytes($meta);
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0o700, true);
        }
        file_put_contents($this->cacheFilePath(), $header . strlen($payloadBytes) . ':' . $payloadBytes);
    }

    /** @param array<string,string> $meta @return array{0:string,1:string} [header, framedPayload] */
    private function headerBytes(array $meta): array
    {
        $lines = ['PHPDUP-IDX1'];
        foreach ($meta as $key => $value) {
            $lines[] = $key . ':' . $value;
        }
        return [implode("\n", $lines) . "\n\n", ''];
    }

    /** A large, VALID serialized ['blocks'=>...] list: ~400k string entries. */
    private static function poisonPayload(): string
    {
        static $poison = null;
        if ($poison === null) {
            $poison = serialize(['blocks' => array_fill(0, 400_000, 'phpdup-shell-S18-poison')]);
        }
        return $poison;
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
