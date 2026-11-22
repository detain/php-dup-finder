<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Persistence;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstParser;
use Phpdup\Persistence\IndexStore;
use Phpdup\Pipeline\Stages\PreprocessStage;

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
        // once-S64 rider: scratch cleanup of the relocated shell-S39
        // dir-trust pins, merged into this class's existing teardown.
        if ($this->s39Scratch !== null) {
            self::s39DeleteTree($this->s39Scratch);
            $this->s39Scratch = null;
        }
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
    // shell-S19: recursive payload shape guards (finding shell-031)
    // ------------------------------------------------------------------

    public function testTamperedBlockListPayloadIsRefusedNotServed(): void
    {
        // The finding's repro A: an otherwise-live-header blob whose
        // block list is ['not-a-block', 42]. Pre-S19 the flat screen
        // served it and the pipeline died mid-run with an exit-255
        // TypeError; the refusal must be a RuntimeException (the CLI's
        // exit-1 runtime-error class), naming the offending element.
        $this->forgeBlob($this->liveMeta(), serialize(['blocks' => ['not-a-block', 42]]));

        $store = new IndexStore($this->cacheDir, 'cfg-key-1');
        try {
            $served = $store->load($this->sourceFile);
            $this->fail('a proven contract violation must refuse, not return; got ' . ($served === null ? 'null' : 'a list'));
        } catch (\TypeError $e) {
            $this->fail('refusal must be the exit-1 exception class, never the exit-255 TypeError shape: ' . $e->getMessage());
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression(
                '/phpdup: refusing corrupt block-index cache entry .*block #0 is string, not a Block snapshot/',
                $e->getMessage(),
            );
            $this->assertStringContainsString('--no-cache', $e->getMessage(), 'the refusal must name the remedy');
            $this->assertSame(0, (int) $e->getCode(), 'code 0 keeps the CLI on exit 1, not a custom nonzero fatal');
        }
    }

    public function testMissingBlocksSectionIsRefused(): void
    {
        // A decoded array with no "blocks" key cannot come from this
        // writer — same provable-violation posture as repro A.
        $this->forgeBlob($this->liveMeta(), serialize(['written-by-something-else' => true]));

        $store = new IndexStore($this->cacheDir, 'cfg-key-1');
        try {
            $store->load($this->sourceFile);
            $this->fail('payload without its section must refuse');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('carries no "blocks" section', $e->getMessage());
        }
    }

    public function testNestedSmuggledClassInsideLegalBlockIsRefused(): void
    {
        // The finding's repro B, block-store edition: the list is real
        // Block objects (element checks pass) and the stdClass poison
        // sits inside a public array field — one level deeper than the
        // flat pre-S19 screen could reach. Under the allow-list it
        // decodes to __PHP_Incomplete_Class; the recursive walk names the
        // original class and the load refuses.
        $blocks = $this->extract($this->sourceFile);
        $this->assertNotEmpty($blocks);
        $blocks[0]->ngramBag = ['deep' => [new \stdClass()]];

        $this->forgeBlob($this->liveMeta(), serialize(['blocks' => $blocks]));

        $store = new IndexStore($this->cacheDir, 'cfg-key-1');
        try {
            $served = $store->load($this->sourceFile);
            $this->fail('nested smuggled class must refuse; got ' . ($served === null ? 'a miss' : 'a served list'));
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression(
                '/phpdup: refusing corrupt block-index cache entry .*hides inside the decoded graph/',
                $e->getMessage(),
            );
            $this->assertStringContainsString('stdClass', $e->getMessage(), 'the refusal names the smuggled class');
        }
    }

    public function testHonestGraphIncludingRealAstWalksClean(): void
    {
        // Non-vacuity for every refusal pin above: a real extracted,
        // normalized Block graph (canonical + ast + the whole PhpParser
        // node forest) must walk clean AND keep serving cache hits.
        // Should this ever refuse, the guard became a false-positive
        // machine against warm caches.
        $blocks = $this->extract($this->sourceFile);
        $this->assertNotEmpty($blocks);
        $this->assertNull(
            IndexStore::findIncompleteClass(['blocks' => $blocks]),
            'the honest graph must walk clean',
        );

        $store = new IndexStore($this->cacheDir, 'cfg-key-1');
        $store->save($this->sourceFile, $blocks);
        $loaded = $store->load($this->sourceFile);
        $this->assertNotNull($loaded, 'honest snapshots keep hitting after the guards landed');
        $this->assertCount(count($blocks), $loaded);
    }

    public function testShapeWalkIsCycleSafeAndBudgetDegradesToSentinel(): void
    {
        // serialize()/unserialize() round-trips R: self-references, so a
        // poison graph can be cyclic — the walk must neither hang nor
        // pass it clean. The injected budget runs the unresolved arm in
        // milliseconds; production readers hand it to miss semantics
        // (never serve unchecked, never refuse a merely huge graph).
        $cyclic = ['guard' => 'top'];
        $cyclic['self'] = &$cyclic;
        $decoded = unserialize(serialize($cyclic));
        $this->assertIsArray($decoded, 'precondition: the cycle survives the round trip');

        $this->assertSame(
            IndexStore::SCAN_UNRESOLVED,
            IndexStore::findIncompleteClass($decoded, 100),
            'a cyclic graph must run out the budget, not hang and not pass clean',
        );

        // Non-vacuity the other way: an honest-but-wide 50k-entry list
        // (~50k edges) walks clean inside the production default budget.
        $this->assertNull(
            IndexStore::findIncompleteClass(['blocks' => array_fill(0, 50_000, 'phpdup-shell-S19-wide')]),
            'a wide honest graph must not trip the budget',
        );
    }

    public function testPoisonedCacheRefusesAtTheCliWithExitOneNotFatal(): void
    {
        // The acceptance probe as an automated pin: analyze once (honest
        // warm cache), swap every .idx payload for repro A's shape with
        // the live header bytes kept verbatim, rerun — the second run
        // ends in the friendly refusal at exit 1 (never 255, never a
        // TypeError or Fatal), and --no-cache keeps the corpus
        // analysable again. All paths are tmp-dir; the real tree is not
        // touched.
        $base   = sys_get_temp_dir() . '/phpdup-s19-cli-' . uniqid();
        $corpus = $base . '/src';
        $cache  = $base . '/cache';
        mkdir($corpus, 0o700, true);
        foreach (['a', 'b', 'c'] as $letter) {
            file_put_contents(
                $corpus . '/' . $letter . '.php',
                "<?php function f_{$letter}(\$x) { \$y = \$x + 1; if (\$y > 2) { return 'a'; } return 'b'; }",
            );
        }
        $cfg = $base . '/cfg.json';
        file_put_contents($cfg, (string) json_encode(['cache_dir' => $cache]));

        try {
            $warm = $this->runCli($cfg, $corpus);
            $this->assertSame(0, $warm['exit'], 'precondition: the honest warm run succeeds: ' . $warm['combined']);
            // The block-store surgery targets per-file snapshots only —
            // clusters.idx shares the *.idx suffix but rides the other
            // class's guards (pinned in ClusterCacheTest).
            $idxFiles = array_values(array_filter(
                glob($cache . '/*.idx') ?: [],
                static fn (string $f): bool => basename($f) !== 'clusters.idx',
            ));
            $this->assertNotEmpty($idxFiles, 'precondition: the warm run wrote per-file snapshots');

            foreach ($idxFiles as $idx) {
                $this->surgeryBlocks($idx);
            }

            $poisoned = $this->runCli($cfg, $corpus);
            $this->assertSame(1, $poisoned['exit'], 'refusal must be exit 1 (runtime error), got ' . $poisoned['exit'] . "\n" . $poisoned['combined']);
            $this->assertMatchesRegularExpression('/phpdup: refusing corrupt block-index cache entry/', $poisoned['err']);
            $this->assertStringNotContainsString('TypeError', $poisoned['combined'], 'the finding-031 crash shape must be gone');
            $this->assertStringNotContainsString('Fatal error', $poisoned['combined'], 'no PHP fatal may escape the refusal');

            $bypassed = $this->runCli($cfg, $corpus, extra: ['--no-cache']);
            $this->assertSame(0, $bypassed['exit'], '--no-cache must be unaffected by a poisoned cache: ' . $bypassed['combined']);
        } finally {
            $this->purgeDir($base);
        }
    }

    // ------------------------------------------------------------------
    // shell-S16: the IndexStore config key binds low_memory (finding shell-027)
    // ------------------------------------------------------------------

    /**
     * Mirror of the shell-S30 scoring-knob pin: the low_memory flag
     * rewrites every stored bag (hex-keyed 64-bit vs CompactNgramBag
     * int-keyed), so flipping it must move the key PreprocessStage feeds
     * the store, and a snapshot from one side must MISS on the other —
     * while still HITTING under its own key (the refusal is about the
     * flag alone). This is the cache-invalidation consequence of S16:
     * existing pre-S16 caches (whose key was computed WITHOUT lowMemory)
     * self-invalidate once through this very gate — a clean miss, never
     * a stale serve, disclosed in the lane report.
     */
    public function testLowMemoryFlipSeparatesConfigKeysAndRefusesCrossServe(): void
    {
        $base = new Config(paths: ['/x'], exclude: []);
        $flipped = new Config(paths: ['/x'], exclude: [], lowMemory: true);

        $stage = new PreprocessStage(useCache: true);
        $keyOf = new \ReflectionMethod(PreprocessStage::class, 'indexStoreConfigKey');

        $keyDefault = $keyOf->invoke($stage, $base);
        $keyFlipped = $keyOf->invoke($stage, $flipped);

        $this->assertNotSame($keyDefault, $keyFlipped,
            'low_memory rewrites stored bag keys — the IndexStore config key must bind it');
        $this->assertSame($keyDefault, $keyOf->invoke($stage, $base),
            'non-vacuity: the key must be a pure function of the config');

        $blocks = $this->extract($this->sourceFile);
        $this->assertNotEmpty($blocks);
        (new IndexStore($this->cacheDir, $keyDefault))->save($this->sourceFile, $blocks);

        $this->assertNull((new IndexStore($this->cacheDir, $keyFlipped))->load($this->sourceFile),
            'a snapshot from the other side of the flag flip must never be served');
        $served = (new IndexStore($this->cacheDir, $keyDefault))->load($this->sourceFile);
        $this->assertNotNull($served, 'same-flag serve still works (non-vacuity)');
        $this->assertCount(count($blocks), $served);
    }

    // ------------------------------------------------------------------
    // once-S64 rider (shell-S39 LOW-3): the dir-trust pins relocated
    // from SerializedClassAllowListTest. Method bodies and helpers are
    // verbatim; the only adaptation is that the scratch cleanup merged
    // into this class's pre-existing tearDown.
    // ------------------------------------------------------------------

    /**
     * Leg (b): a FRESH IndexStore dir is private absolutely. The mode
     * under test is the one the creation request cannot promise on its
     * own — mkdir's mode argument is masked by the process umask, so a
     * hostile 0777 environment would yield a 0000 (or under the old
     * 0775-request, a group-writable) dir without the shell-S39
     * post-mkdir chmod. Acceptance spelling: stat %a == 700.
     */
    public function testFreshIndexStoreDirIsAbsolutePrivateEvenUnderHostileUmask(): void
    {
        $base = $this->s39ScratchBase();
        $dir  = $base . '/fresh';

        $previous = umask(0o777); // would mask a 0700 mkdir() request down to 0000
        try {
            $store = new IndexStore($dir, 'cfg-key-s39');
            clearstatcache(true, $dir);
            self::assertDirectoryExists($dir);
            self::assertSame('0700', substr(sprintf('%o', (int) fileperms($dir)), -4),
                'shell-S39: the fresh cache dir mode is absolute, not umask-relative');
            self::assertTrue($store->isEnabled());
        } finally {
            umask($previous);
        }
    }

    /**
     * Leg (b), the adoption arms. The finding's concrete harm is a
     * FORGEABLE dir (writable by non-owners) feeding report rows, so
     * write is the bit that must die — reads are posture the operator
     * chose. Symlinks are refused outright (no chmod, no cache through
     * a link we did not create; ServeCommand's results-dir doctrine in
     * disabled-store form).
     */
    public function testAdoptedCacheDirsLoseForeignWriteAndSymlinksAreRefused(): void
    {
        $base = $this->s39ScratchBase();

        // 0775 — precisely what the pre-S39 creation request produced:
        // adopted, but tightened.
        $legacy = $base . '/legacy-0775';
        self::s39MkdirMode($legacy, 0o775);
        $legacyStore = new IndexStore($legacy, 'k');
        clearstatcache(true, $legacy);
        self::assertSame('0755', substr(sprintf('%o', (int) fileperms($legacy)), -4),
            'a group-writable cache dir must come out of the trust path write-clean (shell-052(b))');
        self::assertTrue($legacyStore->isEnabled(), 'tightening is an upgrade, not a cache death');

        // 0755 — not forgeable: the operator's mode stays untouched.
        $readShared = $base . '/read-shared-0755';
        self::s39MkdirMode($readShared, 0o755);
        self::assertTrue((new IndexStore($readShared, 'k'))->isEnabled());
        clearstatcache(true, $readShared);
        self::assertSame('0755', substr(sprintf('%o', (int) fileperms($readShared)), -4));

        // symlink — refuse; the target is never touched through the link.
        $target = $base . '/symlink-target';
        self::s39MkdirMode($target, 0o775);
        $link = $base . '/symlinked';
        self::assertTrue(symlink($target, $link));
        self::assertFalse((new IndexStore($link, 'k'))->isEnabled(),
            'a symlinked cache dir is not a trust anchor');
        clearstatcache(true, $target);
        self::assertSame('0775', substr(sprintf('%o', (int) fileperms($target)), -4),
            'the constructor must not chmod through a symlink');
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /** @return array<string,string> header meta that clears every load() gate for sourceFile */
    private function liveMeta(): array
    {
        return [
            'file_hash'      => (string) sha1_file($this->sourceFile),
            'parser_version' => self::LIVE_VERSION,
            'config_key'     => 'cfg-key-1',
        ];
    }

    /**
     * Keep the framed header verbatim, replace the payload section with
     * ['blocks' => ['not-a-block', 42]] (repro A) under correct framing.
     */
    private function surgeryBlocks(string $idxFile): void
    {
        $bytes  = (string) file_get_contents($idxFile);
        $sep    = strpos($bytes, "\n\n");
        $this->assertIsInt($sep, 'precondition: framed snapshot carries its terminator');
        $header = substr($bytes, 0, $sep + 2);
        $framed = substr($bytes, $sep + 2);
        $colon  = strpos($framed, ':');
        $this->assertIsInt($colon, 'precondition: framed payload carries its length prefix');
        $blob     = substr($framed, $colon + 1, (int) substr($framed, 0, $colon));
        $payload  = unserialize($blob);
        $this->assertIsArray($payload, 'precondition: the warm payload decodes');

        $payload['blocks'] = ['not-a-block', 42];
        $poison = serialize($payload);
        file_put_contents($idxFile, $header . strlen($poison) . ':' . $poison);
    }

    /**
     * @param list<string> $extra
     * @return array{exit:int, out:string, err:string, combined:string}
     */
    private function runCli(string $cfgPath, string $corpus, array $extra = []): array
    {
        $cmd = array_merge(
            [PHP_BINARY, dirname(__DIR__, 3) . '/bin/phpdup', 'analyze', $corpus, '--workers=1', '-c', $cfgPath],
            $extra,
        );
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname($corpus));
        $this->assertIsResource($proc, 'proc_open refused ' . implode(' ', $cmd));

        fclose($pipes[0]);   // </dev/null law: no interactive prompt can block the child
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out      = '';
        $err      = '';
        $deadline = microtime(true) + 30.0;
        $outOpen  = true;
        $errOpen  = true;
        while ($outOpen || $errOpen) {
            $read = array_values(array_filter([$outOpen ? $pipes[1] : null, $errOpen ? $pipes[2] : null]));
            $w = $e = null;
            if ($read !== [] && stream_select($read, $w, $e, 1) > 0) {
                foreach ($read as $stream) {
                    $chunk = fread($stream, 65536);
                    if ($stream === $pipes[1]) {
                        $out .= $chunk === false ? '' : $chunk;
                    } else {
                        $err .= $chunk === false ? '' : $chunk;
                    }
                }
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                $this->fail('phpdup child timed out: ' . implode(' ', $cmd));
            }
            if (@feof($pipes[1])) $outOpen = false;
            if (@feof($pipes[2])) $errOpen = false;
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return ['exit' => $exit, 'out' => $out, 'err' => $err, 'combined' => $out . $err];
    }

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

    private ?string $s39Scratch = null;

    private function s39ScratchBase(): string
    {
        if ($this->s39Scratch === null) {
            $dir = sys_get_temp_dir() . '/phpdup-s39-' . getmypid() . '-' . spl_object_id($this);
            @mkdir($dir, 0o700, true);
            $this->s39Scratch = $dir;
        }
        return $this->s39Scratch;
    }

    private static function s39MkdirMode(string $dir, int $mode): void
    {
        $previous = umask(0); // the requested mode is the created mode, verbatim
        try {
            self::assertTrue(@mkdir($dir, $mode, true), "scratch dir {$dir} must exist for the adoption arms");
        } finally {
            umask($previous);
        }
    }

    private static function s39DeleteTree(string $path): void
    {
        if (is_link($path)) {
            @unlink($path);
            return;
        }
        if (is_dir($path)) {
            @chmod($path, 0o700); // recover traversal/unlink rights on modes we set back
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::s39DeleteTree($path . '/' . $entry);
                }
            }
            @rmdir($path);
            return;
        }
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
