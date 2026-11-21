<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Persistence;

use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Persistence\ClusterCache;
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
    // helpers
    // ------------------------------------------------------------------

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
