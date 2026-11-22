<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Persistence;

use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Persistence\ClusterCache;
use Phpdup\Persistence\IndexStore;
use Phpdup\Util\LineRange;

/**
 * ClusterCache round-trip and gate semantics.
 *
 * shell-S18 (finding shell-009) moved the meta check ahead of the
 * payload: parser_version / config_key / corpus_hash are validated from
 * a bounded plain-text header read, and the framed cluster set is
 * opened only when every gate passes. The "miss without deserializing"
 * tests hide a large, VALID serialized cluster list behind a mismatched
 * header and prove the ordering deterministically: the poison decodes
 * to a non-null list, so any load that reached the payload would return
 * it, flipping the assertNull. The load-timing ratio round 1 paired
 * with that arm flaked under suite-wide GC pressure and carried no
 * coverage the assertNull lacks; the decode-cost story is pinned at the
 * bench/report layer instead (the plan's report-only perf-probe
 * bullet), per review r1 F-2.
 */
final class ClusterCacheTest extends TestCase
{
    /** Current on-wire parser_version stamp (mirrors ClusterCache's private const). */
    private const LIVE_VERSION = 'cluster-cache-v4';

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/phpdup-cluster-cache-' . uniqid();
        mkdir($this->tmp, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->purgeDir($this->tmp);
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

    // ------------------------------------------------------------------
    // shell-S18: header-before-payload (finding shell-009)
    // ------------------------------------------------------------------

    public function testStaleParserVersionMissesWithoutDeserializingPayload(): void
    {
        $blocks = [$this->mkblock('a', 'h1')];
        $this->assertHeaderOnlyMiss($blocks, [
            'parser_version' => 'cluster-cache-v0-ancient',
            'config_key'     => 'cfg-key-1',
            'corpus_hash'    => $this->corpusHash($blocks),
        ]);
    }

    public function testFlippedConfigKeyMissesWithoutDeserializingPayload(): void
    {
        $blocks = [$this->mkblock('a', 'h1')];
        $this->assertHeaderOnlyMiss($blocks, [
            'parser_version' => self::LIVE_VERSION,
            'config_key'     => 'cfg-key-OTHER',
            'corpus_hash'    => $this->corpusHash($blocks),
        ]);
    }

    public function testCorpusHashMismatchMissesWithoutDeserializingPayload(): void
    {
        $blocks = [$this->mkblock('a', 'h1')];
        $this->assertHeaderOnlyMiss($blocks, [
            'parser_version' => self::LIVE_VERSION,
            'config_key'     => 'cfg-key-1',
            'corpus_hash'    => 'c' . str_repeat('0', 39),
        ]);
    }

    public function testMalformedHeadersDegradeToMissNotError(): void
    {
        $blocks   = [$this->mkblock('a', 'h1')];
        $small    = serialize(['clusters' => []]);   // payload never reached on these paths — keep it tiny
        $framed   = strlen($small) . ':' . $small;
        $goodHdr  = "PHPDUP-CCACHE1\nparser_version:" . self::LIVE_VERSION
            . "\nconfig_key:cfg-key-1\ncorpus_hash:" . $this->corpusHash($blocks) . "\n\n";

        $cases = [
            'truncated magic line only'  => 'PHPDUP-CCACHE1',
            // Unterminated, but with EVERY gate key live and the framed
            // payload riding on its own line after the last header line:
            // the unterminated branch is the branch that fires, and a
            // mutation removing it drops the case through to the payload
            // read (review r1 F-3 — the deliberately-absent corpus_hash
            // used to catch it first, masking the branch under test).
            'no header terminator'       => "PHPDUP-CCACHE1\nparser_version:" . self::LIVE_VERSION . "\nconfig_key:cfg-key-1\ncorpus_hash:" . $this->corpusHash($blocks) . "\n$framed",
            // Live gate keys around the colonless line: a skip-instead-of-
            // reject mutation decodes the payload and surfaces here, not
            // under a second gate.
            'malformed line (no colon)'  => "PHPDUP-CCACHE1\nparser_version:" . self::LIVE_VERSION . "\nconfig_key:cfg-key-1\ncorpus_hash:" . $this->corpusHash($blocks) . "\nbroken line\n\n$framed",
            'missing required keys'      => "PHPDUP-CCACHE1\njunk_value\n\n$framed",
            'header ok, empty framing'   => $goodHdr,
            'header ok, foreign framing' => $goodHdr . '999:short',
            'appended bytes after frame' => $goodHdr . $framed . 'GARBAGE',
        ];

        foreach ($cases as $label => $bytes) {
            $dir = $this->tmp . '/malformed';
            if (!is_dir($dir)) {
                mkdir($dir, 0o700, true);
            }
            file_put_contents($dir . '/clusters.idx', $bytes);

            $cache = new ClusterCache($dir, 'cfg-key-1');
            $this->assertNull($cache->load($blocks), "malformed cache [$label] must miss, not error");

            @unlink($dir . '/clusters.idx');
        }
        @rmdir($this->tmp . '/malformed');
    }

    public function testOldRawSerializeBlobMissesCleanlyThenSelfHeals(): void
    {
        $blocks  = [$this->mkblock('a', 'h1')];
        $cluster = new Cluster('C1', $blocks, 1.0, true);

        // Exact pre-S18 writer bytes: one raw serialize() of meta+clusters,
        // every field up to date — under the old loader this was a HIT.
        $legacy = serialize([
            'parser_version' => self::LIVE_VERSION,
            'config_key'     => 'cfg-key-1',
            'corpus_hash'    => $this->corpusHash($blocks),
            'clusters'       => [$cluster],
        ]);
        $this->assertStringStartsWith('a:', $legacy, 'precondition: old format is raw serialize');
        file_put_contents($this->tmp . '/clusters.idx', $legacy);

        $cache = new ClusterCache($this->tmp, 'cfg-key-1');
        $this->assertNull($cache->load($blocks),
            'shell-S18 acceptance: old-format blobs land on a clean one-time miss');

        // save() rewrites in the framed format; the very next load hits.
        $cache->save($blocks, [$cluster]);
        $this->assertStringStartsWith("PHPDUP-CCACHE1\n", (string) file_get_contents($this->tmp . '/clusters.idx'),
            'fresh snapshots must carry the framed-header magic');

        $loaded = (new ClusterCache($this->tmp, 'cfg-key-1'))->load($blocks);
        $this->assertNotNull($loaded, 'cache must self-heal: one miss, then hits again');
        $this->assertSame('C1', $loaded[0]->id);
    }

    public function testFreshCacheDirAndBlobsArePrivate(): void
    {
        $blocks = [$this->mkblock('a', 'h1')];
        $deep   = $this->tmp . '/nested/deeper';

        $cache = new ClusterCache($deep, 'cfg');
        $cache->save($blocks, [new Cluster('C1', $blocks, 1.0, true)]);

        $this->assertDirectoryExists($deep);
        $this->assertSame('0700', substr(sprintf('%o', (int) fileperms($deep)), -4),
            'S23a coverage-gap seat: the cache dir must not be group-writable');
        $this->assertSame('0600', substr(sprintf('%o', (int) fileperms($deep . '/clusters.idx')), -4),
            'cache blobs carry only replayable derived data — owner-only, like AstCache payloads');
    }

    public function testEmptyClusterSetStillHitsAsArray(): void
    {
        // FingerprintModeAcceptanceTest relies on this posture at the
        // payload level: a saved empty set is a HIT returning [], not a
        // miss — the once-S64 "empty blob" line concerns raw garbage
        // files (which the magic check already refuses), not this.
        $blocks = [$this->mkblock('a', 'h1')];
        $cache  = new ClusterCache($this->tmp, 'cfg');
        $cache->save($blocks, []);

        $loaded = $cache->load($blocks);
        $this->assertIsArray($loaded);
        $this->assertCount(0, $loaded);
    }

    // ------------------------------------------------------------------
    // shell-S18 fix r1: header skip contract + serialize fail-open (F-4, F-5)
    // ------------------------------------------------------------------

    public function testUnknownHeaderKeyIsSkippedAndColonlessLineStillMisses(): void
    {
        // The unknown-key SKIP is shell-S30's forward-compat promise
        // ("extra key bytes ride the S18 header without breaking
        // readers"); the colon rule is its boundary. Round 1 measured a
        // skip-to-reject mutation leaving the whole filter green (F-4) —
        // this pins both halves in both directions.
        $blocks  = [$this->mkblock('a', 'h1')];
        $cluster = new Cluster('C1', $blocks, 1.0, true);
        $cache   = new ClusterCache($this->tmp, 'cfg-key-1');
        $cache->save($blocks, [$cluster]);

        $file  = $this->tmp . '/clusters.idx';
        $bytes = (string) file_get_contents($file);
        $sep   = strpos($bytes, "\n\n");
        $this->assertIsInt($sep, 'precondition: the saved header carries its terminator');

        file_put_contents($file, substr($bytes, 0, $sep) . "\nfuture_key:whatever" . substr($bytes, $sep));
        $loaded = $cache->load($blocks);
        $this->assertNotNull($loaded, 'an unknown header key must be skipped, not rejected');
        $this->assertSame('C1', $loaded[0]->id);

        file_put_contents($file, substr($bytes, 0, $sep) . "\nno colon here" . substr($bytes, $sep));
        $this->assertNull($cache->load($blocks),
            'a colonless header line must still miss (skipping unknown KEYS is not skipping malformed LINES)');
    }

    public function testUnserializablePayloadFailsOpenWithoutWritingACacheFile(): void
    {
        // save()'s try/catch around serialize() is a fail-open arm: an
        // unserializable shape must cache NOTHING, throw nothing, and
        // leave the next lookup on a plain miss so the run re-clusters
        // (review r1 F-5 — nothing manufactured it before). A real
        // Cluster carrying a Closure in its untyped $holes array is the
        // shape that reaches the arm: the clone/strip loop copies the
        // slot happily, serialize() is what refuses it.
        $blocks  = [$this->mkblock('a', 'h1')];
        $cluster = new Cluster('C1', $blocks, 1.0, true);
        $cluster->holes = [function (): int { return 1; }];

        $cache = new ClusterCache($this->tmp, 'cfg-key-1');
        $cache->save($blocks, [$cluster]);

        $this->assertFileDoesNotExist($this->tmp . '/clusters.idx',
            'a failed serialize() must not leave a cache file behind');
        $this->assertNull($cache->load($blocks),
            'nothing was cached, so the lookup must miss');
    }

    // ------------------------------------------------------------------
    // shell-S19: recursive payload shape guards + LOCK_EX (finding shell-031)
    // ------------------------------------------------------------------

    public function testTamperedNestedStdClassIsRefused(): void
    {
        // The finding's repro B, verbatim: every header gate passes and
        // the list holds a real Cluster — the stdClass poison hides one
        // level deeper, in the holes array. Pre-S19 the flat screen
        // (payload values + one array level) let it through and the
        // corrupt cluster was served; the pipeline later paid an
        // exit-255 TypeError. The recursive walk now names the smuggled
        // class at the load boundary.
        $blocks  = [$this->mkblock('a', 'h1')];
        $cluster = new Cluster('C1', $blocks, 1.0, true);
        $cluster->holes = [new \stdClass()];

        $this->forgeClusterBlob($this->liveMeta($blocks), serialize(['clusters' => [$cluster]]));

        $cache = new ClusterCache($this->tmp, 'cfg-key-1');
        try {
            $served = $cache->load($blocks);
            $this->fail('nested smuggled class must refuse; got ' . ($served === null ? 'a miss' : 'a served cluster list'));
        } catch (\TypeError $e) {
            $this->fail('refusal must be the exit-1 exception class, never the exit-255 TypeError shape: ' . $e->getMessage());
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression(
                '/phpdup: refusing corrupt cluster cache entry .*hides inside the decoded graph/',
                $e->getMessage(),
            );
            $this->assertStringContainsString('stdClass', $e->getMessage(), 'the refusal names the smuggled class');
            $this->assertStringContainsString('delete the cache directory', $e->getMessage(), 'the refusal must name the remedy that works on this path');
        }
    }

    public function testForeignClusterElementIsRefused(): void
    {
        // List-level shape is checked too — mirror of the block-store
        // repro A: real header, foreign elements.
        $blocks = [$this->mkblock('a', 'h1')];
        $this->forgeClusterBlob($this->liveMeta($blocks), serialize(['clusters' => ['not-a-cluster', 42]]));

        $cache = new ClusterCache($this->tmp, 'cfg-key-1');
        try {
            $cache->load($blocks);
            $this->fail('foreign element must refuse');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/cluster #0 is string, not a Cluster snapshot/', $e->getMessage());
        }
    }

    public function testMissingClustersSectionIsRefused(): void
    {
        $blocks = [$this->mkblock('a', 'h1')];
        $this->forgeClusterBlob($this->liveMeta($blocks), serialize(['written-by-something-else' => true]));

        $cache = new ClusterCache($this->tmp, 'cfg-key-1');
        try {
            $cache->load($blocks);
            $this->fail('payload without its section must refuse');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('carries no "clusters" section', $e->getMessage());
        }
    }

    public function testHonestClusterGraphWalksCleanAndStillHits(): void
    {
        // Non-vacuity: a real Cluster (with a real Block member + AST)
        // must walk null and keep serving warm hits — the guards refuse
        // provable violations, never legitimate snapshots. The empty set
        // stays a HIT too (FingerprintMode posture pinned above).
        $blocks  = [$this->mkblock('a', 'h1')];
        $cluster = new Cluster('C1', $blocks, 1.0, true);
        $this->assertNull(IndexStore::findIncompleteClass(['clusters' => [$cluster]]));

        $cache = new ClusterCache($this->tmp, 'cfg-key-1');
        $cache->save($blocks, [$cluster]);
        $loaded = $cache->load($blocks);
        $this->assertNotNull($loaded, 'honest snapshots keep hitting after the guards landed');
        $this->assertSame('C1', $loaded[0]->id);
    }

    public function testConcurrentSaveSerializesBehindExclusiveLock(): void
    {
        // The save() half of finding shell-031, deterministic: this
        // process holds flock(LOCK_EX) on the target path while a REAL
        // ClusterCache::save() runs in another PHP process. The
        // production write must block on our lock — a save that
        // completed inside the hold window proves the flag is gone
        // (mutation-demonstrated in the lane report). After release the
        // child finishes and the snapshot loads intact.
        $dir    = $this->tmp . '/lock';
        mkdir($dir, 0o700, true);
        $target = $dir . '/clusters.idx';

        $handle = fopen($target, 'c');
        $this->assertIsResource($handle);
        $this->assertTrue(flock($handle, LOCK_EX), 'test precondition: the hold lock is ours');

        $driver = $this->tmp . '/lock-child.php';
        file_put_contents($driver, sprintf(
            "<?php\nrequire %s;\n%s\n",
            var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true),
            self::lockChildBody(),
        ));

        $proc = proc_open(
            [PHP_BINARY, $driver, $dir],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->tmp,
        );
        $this->assertIsResource($proc, 'proc_open refused the lock-child driver');
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $buf      = '';
        $deadline = microtime(true) + 15.0;   // cold php + autoload + corpus build
        while (!str_contains($buf, 'READY')) {
            if (microtime(true) > $deadline) {
                $this->fail('lock child never reached READY: ' . $buf . "\n" . (string) stream_get_contents($pipes[2]));
            }
            $buf .= $this->pump($pipes[1], 1.0);
        }
        $readyAt = strpos($buf, 'READY') + 5;

        // The child is at (or inside) save() now. Bytes it flushed past
        // READY still count against the hold window — over-reading above
        // is exactly how an unguarded write would sneak past.
        $windowEnd = microtime(true) + 0.7;
        while (microtime(true) < $windowEnd) {
            $buf .= $this->pump($pipes[1], 0.1);
        }
        $status = proc_get_status($proc);
        $this->assertStringNotContainsString(
            'SAVED',
            substr($buf, $readyAt),
            'save() completed while another process held LOCK_EX — the write is not lock-protected',
        );
        $this->assertTrue($status['running'], 'the child must still be alive, blocked on the held lock');

        flock($handle, LOCK_UN);
        fclose($handle);

        $deadline = microtime(true) + 10.0;
        while (!str_contains($buf, 'SAVED')) {
            if (microtime(true) > $deadline) {
                $this->fail('lock child never finished after the release: ' . $buf . "\n" . (string) stream_get_contents($pipes[2]));
            }
            $buf .= $this->pump($pipes[1], 1.0);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), 'the lock child must exit cleanly');

        // The lock serialized the write — it did not lose it: the exact
        // corpus the child saved must replay for a fresh reader.
        $loaded = (new ClusterCache($dir, 'cfg-key-1'))->load($this->lockChildCorpusBlocks());
        $this->assertNotNull($loaded, 'a locked save must leave a readable snapshot');
        $this->assertSame('C1', $loaded[0]->id);
    }

    public function testPoisonedClusterCacheRefusesAtTheCliWithExitOne(): void
    {
        // The finding's end-to-end complaint: a tampered clusters.idx
        // used to be SERVED (exit 0, corrupt output) and the crash came
        // later as a 255 TypeError. Now: honest warm run, surgery puts a
        // stdClass into the first cluster's holes keeping the header
        // bytes, rerun → clean exit-1 refusal; --no-cache unaffected.
        $base   = sys_get_temp_dir() . '/phpdup-s19-cc-cli-' . uniqid();
        $corpus = $base . '/src';
        $cache  = $base . '/cache';
        mkdir($corpus, 0o700, true);
        foreach (['a', 'b', 'c'] as $letter) {
            file_put_contents(
                $corpus . '/' . $letter . '.php',
                "<?php function f_{$letter}(\$xs) { \$t = 0; foreach (\$xs as \$x) { \$t += \$x; } if (\$t > 10) { \$t = max(\$t, 1); } else { \$t = min(\$t, 0); } return \$t; }",
            );
        }
        $cfg = $base . '/cfg.json';
        file_put_contents($cfg, (string) json_encode(['cache_dir' => $cache]));

        try {
            $warm = $this->runCli($cfg, $corpus);
            $this->assertSame(0, $warm['exit'], 'precondition: the honest warm run succeeds: ' . $warm['combined']);

            $snapshot = $cache . '/clusters.idx';
            $this->assertFileExists($snapshot, 'precondition: the warm run wrote the cluster snapshot');

            $bytes = (string) file_get_contents($snapshot);
            $sep   = strpos($bytes, "\n\n");
            $this->assertIsInt($sep, 'precondition: framed snapshot carries its terminator');
            $header = substr($bytes, 0, $sep + 2);
            $framed = substr($bytes, $sep + 2);
            $colon  = strpos($framed, ':');
            $this->assertIsInt($colon, 'precondition: framed payload carries its length prefix');
            $payload = unserialize(substr($framed, $colon + 1, (int) substr($framed, 0, $colon)));
            $this->assertIsArray($payload);
            $this->assertNotEmpty($payload['clusters'] ?? [], 'precondition: the warm corpus produced clusters to poison');

            $payload['clusters'][0]->holes = [new \stdClass()];
            $poison = serialize($payload);
            file_put_contents($snapshot, $header . strlen($poison) . ':' . $poison);

            $rerun = $this->runCli($cfg, $corpus);
            $this->assertSame(1, $rerun['exit'], 'refusal must be exit 1, got ' . $rerun['exit'] . "\n" . $rerun['combined']);
            $this->assertMatchesRegularExpression('/phpdup: refusing corrupt cluster cache entry/', $rerun['err']);
            $this->assertStringNotContainsString('TypeError', $rerun['combined']);
            $this->assertStringNotContainsString('Fatal error', $rerun['combined']);

            // Characterization pins (disclosed by shell-S19): the cluster
            // snapshot does NOT honor --no-cache today — the flag's
            // useCache term never reaches ClusterStage's cache gate, a
            // pre-existing wiring gap outside this step's file locks
            // (ClusterStage.php). The refusal therefore still fires; the
            // remedy the message names (delete the cache directory) is
            // what restores service. Once --no-cache is wired through,
            // the first arm here flips to 0 and should be re-pinned.
            $flagStillRefuses = $this->runCli($cfg, $corpus, extra: ['--no-cache']);
            $this->assertSame(1, $flagStillRefuses['exit'], '--no-cache does not bypass the cluster snapshot (gap pinned above)');

            foreach (glob($cache . '/*') ?: [] as $f) {
                @unlink($f);
            }
            $rebuilt = $this->runCli($cfg, $corpus);
            $this->assertSame(0, $rebuilt['exit'], 'deleting the cache directory rebuilds cleanly: ' . $rebuilt['combined']);
        } finally {
            $this->purgeDir($base);
        }
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /** @return array<string,string> gate-clearing meta for the given corpus */
    private function liveMeta(array $blocks): array
    {
        return [
            'parser_version' => self::LIVE_VERSION,
            'config_key'     => 'cfg-key-1',
            'corpus_hash'    => $this->corpusHash($blocks),
        ];
    }

    /** Write a framed clusters.idx keeping the given meta verbatim. */
    private function forgeClusterBlob(array $meta, string $payloadBytes): void
    {
        $lines = ['PHPDUP-CCACHE1'];
        foreach ($meta as $key => $value) {
            $lines[] = $key . ':' . $value;
        }
        file_put_contents(
            $this->tmp . '/clusters.idx',
            implode("\n", $lines) . "\n\n" . strlen($payloadBytes) . ':' . $payloadBytes,
        );
    }

    /**
     * One pump slice: wait up to $slice seconds for readable bytes,
     * non-blocking read otherwise.
     * @param resource $stream
     */
    private function pump($stream, float $slice): string
    {
        $read = [$stream];
        $w = $e = null;
        $sec  = (int) $slice;
        $usec = (int) round(($slice - $sec) * 1_000_000);
        if (stream_select($read, $w, $e, $sec, $usec) < 1) return '';
        $chunk = fread($stream, 65536);
        return $chunk === false ? '' : $chunk;
    }

    /** Top-level driver code for the LOCK_EX contention child. */
    private static function lockChildBody(): string
    {
        return <<<'PHP'
$dir = $argv[1];
$stmts = (new Phpdup\Parsing\AstParser())->parseCode('<?php $x;');
$block = new Phpdup\Extraction\Block(
    file: 'x.php',
    range: new Phpdup\Util\LineRange(1, 1),
    kind: 'method',
    namespace: null, class: null, name: null,
    ast: $stmts[0],
);
$block->id = 'a';
$block->structuralHash = 'h1';
$blocks = [$block];
$clusters = [new Phpdup\Clustering\Cluster('C1', $blocks, 1.0, true)];
echo "READY\n";
(new Phpdup\Persistence\ClusterCache($dir, 'cfg-key-1'))->save($blocks, $clusters);
echo "SAVED\n";
PHP;
    }

    /** The child's exact corpus, built identically here for the post-race load. */
    private function lockChildCorpusBlocks(): array
    {
        return [$this->mkblock('a', 'h1')];
    }

    /**
     * @param list<string> $extra
     * @return array{exit:int, out:string, err:string, combined:string}
     */
    private function runCli(string $cfgPath, string $corpus, array $extra = []): array
    {
        $cmd = array_merge(
            [PHP_BINARY, dirname(__DIR__, 3) . '/bin/phpdup', 'analyze', $corpus, '--workers=1', '--min-impact', '0', '-c', $cfgPath],
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
     * payload is never read: the cluster-set poison decodes to a
     * non-null list, so any load that reached it would return it instead
     * of missing (shell-009: the pre-S18 loader unserialized the whole
     * cluster set before consulting any key). Deterministic arms only —
     * the timing ratio review r1 F-2 retired.
     *
     * @param list<Block> $blocks
     * @param array<string,string> $meta
     */
    private function assertHeaderOnlyMiss(array $blocks, array $meta): void
    {
        $poison = self::poisonPayload();

        // Non-vacuity precondition: the poison must be exactly what the
        // loader refuses to touch — a VALID serialized cluster list.
        $decoded = @unserialize($poison);
        $this->assertIsArray($decoded, 'poison payload must be a valid serialized cluster list');

        $lines = ['PHPDUP-CCACHE1'];
        foreach ($meta as $key => $value) {
            $lines[] = $key . ':' . $value;
        }
        file_put_contents(
            $this->tmp . '/clusters.idx',
            implode("\n", $lines) . "\n\n" . strlen($poison) . ':' . $poison,
        );

        $cache = new ClusterCache($this->tmp, 'cfg-key-1');
        $this->assertNull($cache->load($blocks),
            'the poison payload decodes to a NON-null cluster list — a returned value proves the loader read the payload');
    }

    /** Mirror of ClusterCache::hashCorpus (v4 token recipe: file|structuralHash). */
    /** @param list<Block> $blocks */
    private function corpusHash(array $blocks): string
    {
        $tokens = [];
        foreach ($blocks as $b) {
            $tokens[] = $b->file . '|' . $b->structuralHash;
        }
        sort($tokens);
        return sha1(implode("\n", $tokens));
    }

    /** A large, VALID serialized ['clusters'=>...] list: ~400k string entries. */
    private static function poisonPayload(): string
    {
        static $poison = null;
        if ($poison === null) {
            $poison = serialize(['clusters' => array_fill(0, 400_000, 'phpdup-shell-S18-poison')]);
        }
        return $poison;
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
