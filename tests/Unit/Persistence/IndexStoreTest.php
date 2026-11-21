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
 * below prove that ordering deterministically: they hide a large,
 * VALID serialized block list behind a mismatched header, and — the
 * poison decodes to a non-null block list — any load that reached the
 * payload would return it, flipping the assertNull. The load-timing
 * ratio round 1 paired with that arm flaked under suite-wide GC
 * pressure and carried no coverage the assertNull lacks; the
 * decode-cost story is pinned at the bench/report layer instead (the
 * plan's report-only perf-probe bullet), per review r1 F-2.
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
        $small  = serialize(['blocks' => []]);
        $framed = strlen($small) . ':' . $small;

        // %FH% is replaced per case with sha1_file() of THAT case's own
        // temp source, so the framing and terminator cases clear every
        // meta gate and die at exactly the branch their label names
        // (review r1 F-3: with the setUp file's hash in every header,
        // load() rejected at the file_hash compare before the payload —
        // the unframe and terminator branches had zero coverage here).
        // The live key lines also keep the non-colon and terminator
        // branches attributable to each other instead of to the gates.
        $liveHeader = "PHPDUP-IDX1\nfile_hash:%FH%\nparser_version:" . self::LIVE_VERSION . "\nconfig_key:cfg-key-1\n";

        $cases = [
            'truncated magic line only'  => 'PHPDUP-IDX1',
            // Unterminated: the framed payload rides on its own line AFTER
            // the last header line, so no blank line ever exists. Every
            // parseable key is live — with the terminator branch mutated
            // away, the case falls through to the payload read instead of
            // being masked by a later gate.
            'no header terminator'       => $liveHeader . $framed,
            'malformed line (no colon)'  => $liveHeader . "broken line\n\n$framed",
            'missing required keys'      => "PHPDUP-IDX1\njunk_value\n\n$framed",
            'non-hex file_hash'          => "PHPDUP-IDX1\nfile_hash:not-a-sha\nparser_version:" . self::LIVE_VERSION . "\nconfig_key:cfg-key-1\n\n$framed",
            'truncated framing (empty)'  => $liveHeader . "\n",
            'appended bytes after frame' => $liveHeader . "\n" . $framed . 'GARBAGE',
        ];

        foreach ($cases as $label => $template) {
            $dir = sys_get_temp_dir() . '/phpdup-store-malformed-' . $label;
            $src = sys_get_temp_dir() . '/phpdup-src-malformed-' . $label . '.php';
            file_put_contents($src, '<?php class B { public function g() { return 1; } }');
            mkdir($dir, 0o700, true);
            $bytes = str_replace('%FH%', (string) sha1_file($src), $template);
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
    // shell-S18 fix r1: header skip contract + serialize fail-open (F-4, F-5)
    // ------------------------------------------------------------------

    public function testUnknownHeaderKeyIsSkippedAndColonlessLineStillMisses(): void
    {
        // The unknown-key SKIP is shell-S30's forward-compat promise
        // ("extra key bytes ride the S18 header without breaking
        // readers"); the colon rule is its boundary. Round 1 measured a
        // skip→reject mutation leaving the whole filter green (F-4) —
        // this pins both halves in both directions.
        $blocks = $this->extract($this->sourceFile);
        $store  = new IndexStore($this->cacheDir, 'cfg-key-1');
        $store->save($this->sourceFile, $blocks);

        $bytes = (string) file_get_contents($this->cacheFilePath());
        $sep   = strpos($bytes, "\n\n");
        $this->assertIsInt($sep, 'precondition: the saved header carries its terminator');

        file_put_contents($this->cacheFilePath(),
            substr($bytes, 0, $sep) . "\nfuture_key:whatever" . substr($bytes, $sep));
        $loaded = $store->load($this->sourceFile);
        $this->assertNotNull($loaded, 'an unknown header key must be skipped, not rejected');
        $this->assertCount(count($blocks), $loaded);

        file_put_contents($this->cacheFilePath(),
            substr($bytes, 0, $sep) . "\nno colon here" . substr($bytes, $sep));
        $this->assertNull($store->load($this->sourceFile),
            'a colonless header line must still miss (skipping unknown KEYS is not skipping malformed LINES)');
    }

    public function testUnserializablePayloadFailsOpenWithoutWritingACacheFile(): void
    {
        // save()'s try/catch around serialize() is a fail-open arm: an
        // unserializable shape must cache NOTHING, throw nothing, and
        // leave the next lookup on a plain miss so the run re-processes
        // (review r1 F-5 — nothing manufactured it before).
        $store = new IndexStore($this->cacheDir, 'cfg-key-1');
        $store->save($this->sourceFile, [function (): int { return 1; }]);

        $this->assertFileDoesNotExist($this->cacheFilePath(),
            'a failed serialize() must not leave a cache file behind');
        $this->assertNull($store->load($this->sourceFile),
            'nothing was cached, so the lookup must miss');
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * Forge a framed cache file behind a mismatched header and prove the
     * payload is never read: the block-list poison behind the header
     * decodes to a non-null value, so any load that reached it would
     * return it instead of missing (shell-009: the old loader paid the
     * full unserialize before consulting any gate). Deterministic arms
     * only — the timing ratio review r1 F-2 retired.
     *
     * @param array<string,string> $meta
     */
    private function assertHeaderOnlyMiss(array $meta): void
    {
        $poison = self::poisonPayload();

        // Non-vacuity precondition: the poison must be exactly what the
        // loader refuses to touch — a VALID serialized block list.
        $decoded = @unserialize($poison);
        $this->assertIsArray($decoded, 'poison payload must be a valid serialized block list');

        $this->forgeBlob($meta, $poison);

        $store = new IndexStore($this->cacheDir, 'cfg-key-1');
        $this->assertNull($store->load($this->sourceFile),
            'the poison payload decodes to a NON-null block list — a returned value proves the loader read the payload');
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
