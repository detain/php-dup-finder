<?php
declare(strict_types=1);

namespace Phpdup\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Cli\ConfigLoader;
use Phpdup\Clustering\Clusterer;
use Phpdup\Extraction\Block;
use Phpdup\Index\BlockIndex;
use Phpdup\Parallel\PairScoreWorker;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Persistence\ClusterCache;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Item 1.1 (§5a) — acceptance harness.
 *
 * 1. MONOTONE-SURVIVAL: for every fixture corpus, the edge set of a
 *    shipped-thresholds numbered run must be a SUBSET of the un-numbered
 *    run's edge set (no today-accepted pair may be lost by the flip).
 *    Bag Jaccard/containment and TED similarity are monotone under the
 *    bag/label coarsening; this is the empirical zero-loss proof.
 * 2. CACHE-MISFEED REFUSAL: a warm numbered cache served to an
 *    un-numbered run (IndexStore and ClusterCache alike) must miss
 *    cleanly and never poison results.
 * 3. TWIN PARITY: serial Clusterer scoring and the parallel
 *    PairScoreWorker must emit byte-identical edges under BOTH modes —
 *    clusters must never fork with worker count.
 *
 * Set PHPDUP_EVIDENCE_DIR to also dump the per-corpus survival table as
 * JSON for the lane dossier (inert otherwise).
 */
final class FingerprintModeAcceptanceTest extends TestCase
{
    /** @var list<string> */
    private array $tmpDirs = [];

    /** Shipped J/TED thresholds; df-cap loosened so small corpora generate near-dup candidates in BOTH modes. */
    private const BASE = [
        'similarityThreshold'    => 0.80,
        'treeThreshold'          => 0.85,
        'maxDocumentFrequency'   => 0.9,
        'minClusterImpact'       => 1,
    ];

    /** @return array<string,array{0:string}> */
    public static function corpora(): array
    {
        return [
            'exact'         => ['exact'],
            'notify'        => ['notify'],
            'optional'      => ['optional'],
            'sql'           => ['sql'],
            'micro-fixture' => ['micro-fixture'],
            'unique'        => ['unique'],
            'all-fixtures'  => ['.'],
        ];
    }

    /** @param array<string,mixed> $extra */
    private function drive(string $dir, string $mode, array $extra = []): PipelineState
    {
        $args = array_merge(
            self::BASE,
            [
                'paths' => [$dir],
                'exclude' => [],
                'lazyAst' => false,
                'workers' => 1,
                'incremental' => false,
                'fingerprintIdMode' => $mode,
            ],
            $extra,
        );
        /** @var Config $config */
        $config = new Config(...$args);
        $state = new PipelineState($config);
        $out = new NullOutput();
        $warm = (bool)$args['incremental'];
        (new ScanningStage())->run($state, $out);
        (new PreprocessStage(useCache: $warm))->run($state, $out);
        (new ClusterStage(exactOnly: false, useClusterCache: false))->run($state, $out);
        return $state;
    }

    /** @param list<array{0:string,1:string,2:float,3:string}> $edges */
    private static function pairKeys(array $edges): array
    {
        $keys = [];
        foreach ($edges as [$a, $b]) {
            $keys[strcmp($a, $b) < 0 ? $a . '|' . $b : $b . '|' . $a] = true;
        }
        return $keys;
    }

    /** @dataProvider corpora */
    public function testMonotoneSurvivalPerCorpus(string $name): void
    {
        $dir = realpath(__DIR__ . '/../Fixtures/' . $name);
        $this->assertNotFalse($dir, "fixture corpus missing: $name");

        $numbered   = $this->drive($dir, 'numbered');
        $unnumbered = $this->drive($dir, 'un-numbered');

        $nKeys = self::pairKeys($numbered->edges);
        $uKeys = self::pairKeys($unnumbered->edges);
        $lost  = array_keys(array_diff_key($nKeys, $uKeys));

        $this->assertSame([], $lost,
            "mode flip LOST edges in corpus $name (monotone-survival violation): " . implode(', ', $lost));

        $evidence = getenv('PHPDUP_EVIDENCE_DIR');
        if (is_string($evidence) && $evidence !== '') {
            @mkdir($evidence, 0o777, true);
            file_put_contents($evidence . '/survival-' . $name . '.json', (string)json_encode([
                'corpus'              => $name,
                'edges_numbered'      => count($numbered->edges),
                'edges_unnumbered'    => count($unnumbered->edges),
                'new_edges_unnumbered' => count(array_diff_key($uKeys, $nKeys)),
                'lost'                => $lost,
                'clusters_numbered'   => count($numbered->clusters),
                'clusters_unnumbered' => count($unnumbered->clusters),
            ], JSON_PRETTY_PRINT));
        }
    }

    /**
     * engine-S32 (php-dup-finder-engine-042): the low-memory compact bag
     * must be the exact masked-positive image of the hex bag on every
     * gram the real pipeline mints — key species `digest & PHP_INT_MAX`
     * via the pack/unpack oracle, counts preserved, no fold-era merging
     * beyond the disclosed sign-bit-twin class. On corpora without twin
     * digests the scored edge set must be invariant under the bag key
     * species.
     *
     * @dataProvider corpora
     */
    public function testLowMemoryBagIsMaskedImageOfHexBag(string $name): void
    {
        $dir = realpath(__DIR__ . '/../Fixtures/' . $name);
        $this->assertNotFalse($dir, "fixture corpus missing: $name");

        $wide = $this->drive($dir, 'numbered');
        $low  = $this->drive($dir, 'numbered', ['lowMemory' => true]);

        $wideById = [];
        foreach ($wide->blocks as $b) {
            $wideById[$b->id] = $b;
        }
        $this->assertCount(count($wideById), $wide->blocks, 'fixture block ids must be unique');

        $grams = 0;
        foreach ($low->blocks as $b) {
            $source = $wideById[$b->id] ?? null;
            $this->assertNotNull($source, "block {$b->id} missing from the wide run");
            $mapped = [];
            foreach ($source->ngramBag ?? [] as $digest => $cnt) {
                $key = unpack('J', pack('H16', (string)$digest))[1] & PHP_INT_MAX;
                $mapped[$key] = ($mapped[$key] ?? 0) + $cnt;
                $grams++;
            }
            $actual = $b->ngramBag ?? [];
            ksort($mapped);
            ksort($actual);
            $this->assertSame($mapped, $actual,
                "low-memory bag of {$b->id} is not the masked-positive image of its hex bag");
        }
        $this->assertGreaterThan(0, $grams, "corpus $name mints no n-grams — probe would be vacuous");

        $wEdges = self::pairKeys($wide->edges);
        $lEdges = self::pairKeys($low->edges);
        ksort($wEdges);
        ksort($lEdges);
        $this->assertSame($wEdges, $lEdges,
            "bag key species moved the edge set on corpus $name");
    }

    public function testCrossModeIndexStoreRefusedAndNeverPoisons(): void
    {
        $dir = realpath(__DIR__ . '/../Fixtures/micro-fixture');
        $this->assertNotFalse($dir);
        $shared = $this->tmpDir();  // one cacheDir, both modes → the misfeed probe
        $cold   = $this->tmpDir();  // virgin compare dir

        // Pass 1: numbered cold into $shared.
        $s1 = $this->drive($dir, 'numbered', ['incremental' => true, 'cacheDir' => $shared]);
        $this->assertSame(0, $s1->reusedFiles, 'first run must be cold');

        // Pass 2: same mode, same dir → warm reuse. Proves the key
        // MATCHES per-mode, so a pass-3 miss is about the MODE alone.
        $s2 = $this->drive($dir, 'numbered', ['incremental' => true, 'cacheDir' => $shared]);
        $this->assertGreaterThan(0, $s2->reusedFiles, 'same-mode warm run must reuse the snapshot cache');

        // Pass 3: flipped mode, same dir → every entry refused.
        $s3 = $this->drive($dir, 'un-numbered', ['incremental' => true, 'cacheDir' => $shared]);
        $this->assertSame(0, $s3->reusedFiles,
            'cross-mode IndexStore reuse is the [C]-class misfeed — the config key must refuse it');

        // Refusal completeness: the refused run equals a cold un-numbered run.
        $s4 = $this->drive($dir, 'un-numbered', ['incremental' => true, 'cacheDir' => $cold]);
        $edgeRow = static fn(PipelineState $s): array =>
            array_map(static fn($e) => [$e[0], $e[1], $e[3]], $s->edges);
        $this->assertSame($edgeRow($s3), $edgeRow($s4),
            'cache-refused run must equal the virgin-cache run (no poisoning residue)');
    }

    public function testClusterCacheKeySeparatesModesAndPayloadMissesCross(): void
    {
        $stage = new ClusterStage(exactOnly: false);
        $ref = new \ReflectionMethod(ClusterStage::class, 'cacheConfigKey');
        $key = static fn(string $mode): string => $ref->invoke($stage, new Config(
            paths: ['/x'],
            exclude: [],
            fingerprintIdMode: $mode,
        ));

        $this->assertNotSame($key('numbered'), $key('un-numbered'),
            'edges and tiers move between modes — the cluster cache must not serve across them');
        $this->assertSame($key('un-numbered'), $key('un-numbered'), 'same-mode key must be stable');

        // Payload level: two blocks with distinct ids/hashes, saved under
        // the numbered key, must miss under the un-numbered key.
        $dir = $this->tmpDir();
        $blocks = [self::stubBlock('A', 'hash-a'), self::stubBlock('B', 'hash-b')];
        $storeN = new ClusterCache($dir, $key('numbered'));
        $storeU = new ClusterCache($dir, $key('un-numbered'));
        $this->assertTrue($storeN->isEnabled(), 'cache dir must be live for the probe');
        $storeN->save($blocks, []);
        $this->assertNull($storeU->load($blocks), 'cross-mode ClusterCache serve must refuse');
        $this->assertIsArray($storeN->load($blocks), 'same-mode ClusterCache serve still works (non-vacuity)');
    }

    public function testSerialParallelTwinByteIdenticalUnderBothModes(): void
    {
        $dir = realpath(__DIR__ . '/../Fixtures/micro-fixture');
        $this->assertNotFalse($dir);

        foreach (['numbered', 'un-numbered'] as $mode) {
            $state = $this->drive($dir, $mode);
            $index = new BlockIndex();
            foreach ($state->blocks as $b) {
                $index->add($b);
            }

            $buildRef = new \ReflectionMethod(ClusterStage::class, 'buildClusterer');
            $clusterer = $buildRef->invoke(new ClusterStage(exactOnly: false), $state->config);

            $pairs = iterator_to_array($clusterer->generateCandidatePairs(
                $index,
                new NullOutput(),
                Clusterer::UNLIMITED_CANDIDATE_PAIRS,
            ));
            $this->assertGreaterThan(0, count($pairs), "candidate generation is dead under mode $mode");

            $serialRef = new \ReflectionMethod(Clusterer::class, 'scorePairsSerially');
            $serial = $serialRef->invoke($clusterer, $index);

            $worker = new PairScoreWorker(
                index: $index,
                similarityThreshold: 0.80,
                treeThreshold: 0.85,
                optionalBlocksEnabled: true,
                containmentThreshold: 0.85,
                optionalBlocksMinOverlap: 0.6,
                unnumbered: $state->config->fingerprintIdsUnnumbered(),
            );
            $parallel = $worker->score($pairs);

            $norm = static function (array $rows): array {
                $out = [];
                foreach ($rows as [$a, $b, $sim, $tier]) {
                    $out[strcmp($a, $b) < 0 ? $a . '|' . $b : $b . '|' . $a] = [$sim, $tier];
                }
                ksort($out);
                return $out;
            };
            $this->assertSame($norm($serial), $norm($parallel),
                "twin divergence under mode $mode — clusters would fork with worker count");
            $this->assertGreaterThan(0, count($norm($parallel)),
                "non-vacuity: micro-fixture must yield at least one scored edge under mode $mode");
        }
    }

    public function testLoaderRefusesUnknownMode(): void
    {
        $this->expectException(\RuntimeException::class);
        (new ConfigLoader())->validate(['fingerprint_id_mode' => 'bogus']);
    }

    private static function stubBlock(string $id, string $structuralHash): Block
    {
        $block = new Block(
            'stub.php',
            new \Phpdup\Util\LineRange(1, 5),
            'function',
            null,
            null,
            $id,
            new \PhpParser\Node\Scalar\Int_(1),
        );
        $block->id = $id;
        $block->structuralHash = $structuralHash;
        return $block;
    }

    private function tmpDir(): string
    {
        $dir = sys_get_temp_dir() . '/phpdup-fma-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            ) as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($dir);
        }
        $this->tmpDirs = [];
    }
}
