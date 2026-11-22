<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Normalization\NormalizationPlugin;
use Phpdup\Persistence\ClusterCache;
use Phpdup\Persistence\IndexStore;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Util\LineRange;
use Symfony\Component\Console\Output\NullOutput;

/**
 * php-dup-finder-once-026 (HIGH) — the warm-cache knob-replay fence.
 *
 * The finding: both cache keys (ClusterStage::cacheConfigKey feeding
 * ClusterCache, PreprocessStage::indexStoreConfigKey feeding IndexStore)
 * omitted behavior-affecting knobs, so flipping a knob that changes what
 * the engines produce still replayed the warm snapshot — the report bytes
 * refused to move across an engine switch. The fix doctrine is one line:
 * EVERY knob that alters observable output must be bound into BOTH keys
 * it can influence, and this file is the permanent fence named by the
 * plan's acceptance text ("flip each knob → report bytes change or
 * cache-miss is counted", template: the fingerprint_id_mode refusal
 * harness in tests/Integration/FingerprintModeAcceptanceTest.php).
 *
 * Three layers, per lane law ("replay pins are behavioral, never text
 * mirrors"):
 *  1. KEY LAYER — a flip must move the sha1 the stage feeds the store,
 *     and the key must stay a pure function of config (two equal
 *     configs hash equal — warm replays of unchanged runs still hit).
 *  2. STORE LAYER — a snapshot saved under the pre-flip key must MISS
 *     under the post-flip key in the real store (IndexStore/ClusterCache
 *     header gates), while still HITTING under its own key (the refusal
 *     is about the knob alone: shell-S30's payload pattern).
 *  3. REPLAY LAYER — end-to-end: warm a shared cacheDir, re-run with one
 *     knob flipped, and count the miss through PipelineState
 *     (reusedFiles / clustersReplayed). The refused run must then equal
 *     the virgin-cache run byte-for-byte (no poisoning residue — the
 *     FingerprintModeAcceptanceTest parity posture).
 *
 * Provenance of each leg: scorer/ir_threshold/ml_pair_* ride the cluster
 * key since shell-019 (shell-S30) and the index key since the scorer
 * binding (shell-S16 era); ted_weights since shell-004; lowMemory on the
 * INDEX key since shell-S16 (shell-027). What once-S23 adds here:
 * lowMemory on the CLUSTER key, and normalizationPlugins +
 * perDirectoryOverrides on the INDEX key (ENGINE-CONV-04) — and the
 * whole-roster replay fence the finding's fix line mandated. Legs whose
 * binding predates this step are re-pinned at layers 1-2 for roster
 * completeness (the acceptance names them); layer 3 drives the three
 * new bindings plus the cluster-key-only-isolable ted_weights/ir_threshold
 * pair. No store PARSER_VERSION was touched (key-namespace route, the
 * fingerprintIdMode precedent — era-fold tripwire stays green).
 */
final class CacheKeyKnobReplayTest extends TestCase
{
    /** @var list<string> */
    private array $tmpDirs = [];

    private string $sourceFile = '';

    protected function setUp(): void
    {
        $this->sourceFile = $this->tmpDir() . '/src_probe.php';
        file_put_contents($this->sourceFile, "<?php class SrcProbe { public function f(\$x) { return \$x + 1; } }\n");
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            $this->purgeDir($dir);
        }
        $this->tmpDirs = [];
    }

    // ------------------------------------------------------------------
    // Layer 1+2, index side: every once-026 index-knob flip separates
    // the config key AND the real IndexStore refuses the cross-serve.
    // ------------------------------------------------------------------

    /** @return array<string, array{0: Config, 1: Config}> */
    public static function indexKnobFlipCases(): array
    {
        $base = new Config(paths: ['/x'], exclude: []);
        return [
            // Bound since the shell-S16 era — roster-completeness re-pin.
            'scorer'            => [$base, new Config(paths: ['/x'], exclude: [], scorer: 'ir')],
            'low_memory'        => [$base, new Config(paths: ['/x'], exclude: [], lowMemory: true)],
            // once-S23 legs (finding once-026 / ENGINE-CONV-04):
            'normalization_plugins' => [$base, new Config(
                paths: ['/x'], exclude: [], normalizationPlugins: [CacheKeyProbePlugin::class],
            )],
            'per_dir_overrides' => [$base, new Config(
                paths: ['/x'], exclude: [],
                perDirectoryOverrides: ['/srv/app/src' => ['min_block_size' => 40]],
            )],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('indexKnobFlipCases')]
    public function testIndexKnobFlipSeparatesKeyAndStoreRefusesCrossServe(Config $default, Config $flipped): void
    {
        $stage = new PreprocessStage(useCache: true);
        $keyOf = new \ReflectionMethod(PreprocessStage::class, 'indexStoreConfigKey');

        $keyDefault = $keyOf->invoke($stage, $default);
        $keyFlipped = $keyOf->invoke($stage, $flipped);

        $this->assertNotSame($keyDefault, $keyFlipped,
            'this knob changes what a cached Block snapshot would carry — the IndexStore key must bind it');
        $this->assertSame($keyDefault, $keyOf->invoke($stage, $default),
            'non-vacuity: the key must be a pure function of the config');

        $blocks = [self::stubBlock('probe-a', 'hash-a'), self::stubBlock('probe-b', 'hash-b')];
        $dir = $this->tmpDir();
        (new IndexStore($dir, $keyDefault))->save($this->sourceFile, $blocks);

        $this->assertNull((new IndexStore($dir, $keyFlipped))->load($this->sourceFile),
            'a snapshot from the other side of the knob flip must never be served');
        $served = (new IndexStore($dir, $keyDefault))->load($this->sourceFile);
        $this->assertNotNull($served, 'same-knob serve still works (non-vacuity)');
        $this->assertCount(count($blocks), $served);
    }

    // ------------------------------------------------------------------
    // Layer 1+2, cluster side: the full scoring-knob roster (acceptance
    // list) separates cacheConfigKey and the ClusterCache payload
    // refuses across the flip.
    // ------------------------------------------------------------------

    /** @return array<string, array{0: Config, 1: Config}> */
    public static function clusterKnobFlipCases(): array
    {
        $base = new Config(paths: ['/x'], exclude: []);
        return [
            // Bound since shell-004 / shell-019 (shell-S30 re-pins the
            // first five at its own payload level) — roster-completeness.
            'ted_weights'        => [$base, new Config(paths: ['/x'], exclude: [], tedWeights: 'semantic')],
            'scorer'             => [$base, new Config(paths: ['/x'], exclude: [], scorer: 'ir')],
            'ir_threshold'       => [$base, new Config(paths: ['/x'], exclude: [], irThreshold: 0.70)],
            // Canonical public literal (TEST-NET-3): hermetic, no DNS.
            'ml_pair_url'        => [$base, new Config(paths: ['/x'], exclude: [], mlPairUrl: 'https://203.0.113.7/api')],
            'ml_pair_threshold'  => [$base, new Config(paths: ['/x'], exclude: [], mlPairThreshold: 0.90)],
            // once-S23 leg (finding once-026): the second half of the
            // cache chain for the bag-species knob.
            'low_memory'         => [$base, new Config(paths: ['/x'], exclude: [], lowMemory: true)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('clusterKnobFlipCases')]
    public function testClusterKnobFlipSeparatesKeyAndPayloadRefusesCrossServe(Config $default, Config $flipped): void
    {
        $stage = new ClusterStage(exactOnly: false);
        $keyOf = new \ReflectionMethod(ClusterStage::class, 'cacheConfigKey');

        $keyDefault = $keyOf->invoke($stage, $default);
        $keyFlipped = $keyOf->invoke($stage, $flipped);

        $this->assertNotSame($keyDefault, $keyFlipped,
            'this knob moves edge scores / cluster membership — the cluster-cache key must bind it');
        $this->assertSame($keyDefault, $keyOf->invoke($stage, $default),
            'non-vacuity: the key must be a pure function of the config');

        $blocks = [self::stubBlock('a', 'h1')];
        $cluster = new Cluster('Cbeef123', $blocks, 1.0, true);
        $dir = $this->tmpDir();
        (new ClusterCache($dir, $keyDefault))->save($blocks, [$cluster]);

        $this->assertNull((new ClusterCache($dir, $keyFlipped))->load($blocks),
            'a snapshot from the other side of the knob flip must never be served');
        $served = (new ClusterCache($dir, $keyDefault))->load($blocks);
        $this->assertNotNull($served, 'same-knob serve still works (non-vacuity)');
        $this->assertSame('Cbeef123', $served[0]->id);
    }

    // ------------------------------------------------------------------
    // Layer 3, index replay: warm a shared cacheDir, flip ONE knob, and
    // count the miss through the real stage — then prove the refused
    // run equals the virgin run (no poisoning).
    // ------------------------------------------------------------------

    /** @return array<string, array{0: array<string,mixed>, 1: bool}> */
    public static function indexReplayFlips(): array
    {
        return [
            // [flip-into-config, expect-block-fingerprint-shift]
            'scorer_ir'            => [['scorer' => 'ir'], false],
            'low_memory'           => [['lowMemory' => true], false],
            'normalization_plugin' => [['normalizationPlugins' => [CacheKeyProbePlugin::class]], false],
            'per_dir_allowed_kinds' => [['_override_dir' => true], true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('indexReplayFlips')]
    public function testWarmIndexReplayMissesPerKnobFlipAndNeverPoisons(array $flip, bool $expectShifted): void
    {
        $dir = realpath(__DIR__ . '/../../Fixtures/micro-fixture');
        $this->assertNotFalse($dir, 'fixture corpus missing');

        // Control pair first: warm state is REAL, so any later miss is
        // about the knob alone (the template's pass-1/pass-2 posture).
        $shared = $this->tmpDir();
        $cold = $this->tmpDir();
        $p1 = $this->drive($dir, cacheDir: $shared);
        $this->assertSame(0, $p1->reusedFiles, 'first run into a virgin cacheDir must be cold');
        $this->assertGreaterThan(0, count($p1->blocks), 'fixture must mint blocks or the fence is vacuous');
        $p2 = $this->drive($dir, cacheDir: $shared);
        $this->assertGreaterThan(0, $p2->reusedFiles, 'same-config warm run must reuse the snapshot cache');

        // Per-directory overrides arrive as the map over $dir itself;
        // everything else is a plain named-arg flip.
        $perDir = (bool)($flip['_override_dir'] ?? false);
        unset($flip['_override_dir']);
        if ($perDir) {
            $flip = ['perDirectoryOverrides' => [$dir => ['allowed_kinds' => ['method']]]];
        }

        $flipped = $this->drive($dir, extra: $flip, cacheDir: $shared);
        $this->assertSame(0, $flipped->reusedFiles,
            'cross-knob IndexStore reuse is the once-026 misfeed — the config key must refuse it');
        if ($expectShifted) {
            $this->assertNotSame(self::blockFingerprint($p2), self::blockFingerprint($flipped),
                'non-vacuity: the per-directory allowed_kinds restriction must genuinely reshape the block set — byte-identical files with shifted content is exactly the misfeed class file_hash cannot see');
        }

        $virgin = $this->drive($dir, extra: $flip, cacheDir: $cold);
        $this->assertSame(self::blockFingerprint($virgin), self::blockFingerprint($flipped),
            'cache-refused run must equal the virgin-cache run (no poisoning residue)');
    }

    // ------------------------------------------------------------------
    // Layer 3, cluster replay: knobs the INDEX key does not bind
    // (ted_weights, ir_threshold) keep the block set byte-identical on
    // the flipped run — reusedFiles > 0 — so the cluster-cache miss the
    // new/old bindings must produce is provably KEY-caused, the exact
    // isolation the finding's fix line asked for ("no md5 identity
    // across engine switch"). low_memory/scorer flip both stores at once
    // and are pinned as full-cold composites.
    // ------------------------------------------------------------------

    public function testClusterReplayRefusesKeyOnlyKnobFlipsWhileBlocksStayWarm(): void
    {
        $dir = realpath(__DIR__ . '/../../Fixtures/micro-fixture');
        $this->assertNotFalse($dir, 'fixture corpus missing');

        $shared = $this->tmpDir();
        $p1 = $this->driveCluster($dir, cacheDir: $shared);
        $this->assertFalse($p1->clustersReplayed, 'first run into a virgin cacheDir must compute');
        $this->assertGreaterThan(0, count($p1->clusters), 'fixture must form clusters or the fence is vacuous');

        $p2 = $this->driveCluster($dir, cacheDir: $shared);
        $this->assertTrue($p2->clustersReplayed, 'same-config warm run must replay the cluster snapshot');
        $this->assertGreaterThan(0, $p2->reusedFiles, 'blocks reuse too (corpus-hash gate intact)');

        foreach ([
            'ted_weights'  => ['tedWeights' => 'semantic'],
            'ir_threshold' => ['irThreshold' => 0.70],
        ] as $label => $flip) {
            $cold = $this->tmpDir();
            $flipped = $this->driveCluster($dir, extra: $flip, cacheDir: $shared);
            $this->assertGreaterThan(0, $flipped->reusedFiles,
                "$label: the index key does not bind this knob — blocks MUST stay warm, isolating the refusal below to the cluster key");
            $this->assertFalse($flipped->clustersReplayed,
                "$label: the cluster key must refuse the snapshot across the flip (once-026 engine-switch md5-identity)");

            $virgin = $this->driveCluster($dir, extra: $flip, cacheDir: $cold);
            $this->assertSame(self::resultFingerprint($virgin), self::resultFingerprint($flipped),
                "$label: cache-refused run must equal the virgin-cache run (no poisoning residue)");

            // Own-side non-vacuity: the flipped run's OWN snapshot serves
            // the next identical run (the refusal is about the flip alone).
            $again = $this->driveCluster($dir, extra: $flip, cacheDir: $shared);
            $this->assertTrue($again->clustersReplayed,
                "$label: the flipped key must warm-replay its own snapshot (non-vacuity)");
        }
    }

    /** @return array<string, array<string,mixed>> */
    public static function clusterCompositeFlips(): array
    {
        return [
            'low_memory' => [['lowMemory' => true], false],
            'scorer_ir'  => [['scorer' => 'ir'], false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('clusterCompositeFlips')]
    public function testClusterReplayRefusesCompositeKnobFlipsAndNeverPoisons(array $flip): void
    {
        $dir = realpath(__DIR__ . '/../../Fixtures/micro-fixture');
        $this->assertNotFalse($dir, 'fixture corpus missing');

        $shared = $this->tmpDir();
        $cold = $this->tmpDir();
        $p1 = $this->driveCluster($dir, cacheDir: $shared);
        $this->assertGreaterThan(0, count($p1->clusters), 'fixture must form clusters or the fence is vacuous');
        $p2 = $this->driveCluster($dir, cacheDir: $shared);
        $this->assertTrue($p2->clustersReplayed, 'warm control must replay (knob flip is the only delta below)');

        $flipped = $this->driveCluster($dir, extra: $flip, cacheDir: $shared);
        $this->assertFalse($flipped->clustersReplayed,
            'a snapshot warmed on the other side of the knob must never replay (once-026)');

        $virgin = $this->driveCluster($dir, extra: $flip, cacheDir: $cold);
        $this->assertSame(self::resultFingerprint($virgin), self::resultFingerprint($flipped),
            'cache-refused run must equal the virgin-cache run (no poisoning residue)');
    }

    // ------------------------------------------------------------------
    // harness
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $extra */
    private function drive(string $dir, array $extra = [], ?string $cacheDir = null): PipelineState
    {
        return $this->driveStages($dir, $extra, $cacheDir, clusterCache: false);
    }

    /** @param array<string,mixed> $extra */
    private function driveCluster(string $dir, array $extra = [], ?string $cacheDir = null): PipelineState
    {
        return $this->driveStages($dir, $extra, $cacheDir, clusterCache: true);
    }

    /** @param array<string,mixed> $extra */
    private function driveStages(string $dir, array $extra, ?string $cacheDir, bool $clusterCache): PipelineState
    {
        $args = array_merge([
            'paths'                => [$dir],
            'exclude'              => [],
            'similarityThreshold'  => 0.80,
            'treeThreshold'        => 0.85,
            'maxDocumentFrequency' => 0.9,
            'minClusterImpact'     => 1,
            'lazyAst'              => false,
            'workers'              => 1,
            'incremental'          => $cacheDir !== null,
            'cacheDir'             => $cacheDir ?? $this->tmpDir(),
        ], $extra);
        $config = new Config(...$args);

        $state = new PipelineState($config);
        $out = new NullOutput();
        (new ScanningStage())->run($state, $out);
        (new PreprocessStage(useCache: $cacheDir !== null))->run($state, $out);
        (new ClusterStage(exactOnly: false, useClusterCache: $clusterCache && $cacheDir !== null))->run($state, $out);
        return $state;
    }

    /** Content identity of the stage-2 product: block ids + structural hashes, sorted. */
    private static function blockFingerprint(PipelineState $s): string
    {
        $rows = [];
        foreach ($s->blocks as $b) {
            $rows[] = $b->id . '#' . $b->structuralHash . '@' . $b->name;
        }
        sort($rows);
        return implode("\n", $rows);
    }

    /** Content identity of the stage-3 product: clusters + scored edge pairs, sorted. */
    private static function resultFingerprint(PipelineState $s): string
    {
        $clusters = [];
        foreach ($s->clusters as $c) {
            $members = array_map(static fn(Block $b): string => $b->id, $c->members);
            sort($members);
            $clusters[] = $c->id . '|' . implode(',', $members);
        }
        sort($clusters);
        $edges = [];
        foreach ($s->edges as [$a, $b]) {
            $edges[] = strcmp($a, $b) < 0 ? $a . '|' . $b : $b . '|' . $a;
        }
        sort($edges);
        return implode("\n", $clusters) . "\n==\n" . implode("\n", $edges);
    }

    private static function stubBlock(string $id, string $structuralHash): Block
    {
        $block = new Block(
            'stub.php',
            new LineRange(1, 5),
            'function',
            null,
            null,
            $id,
            new Node\Scalar\Int_(1),
        );
        $block->id = $id;
        $block->structuralHash = $structuralHash;
        return $block;
    }

    private function tmpDir(): string
    {
        $dir = sys_get_temp_dir() . '/phpdup-ckr-' . bin2hex(random_bytes(6));
        $this->assertNotFalse(mkdir($dir, 0o777, true));
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    private function purgeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->purgeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

/**
 * Test-local normalization plugin with a genuinely observable rewrite
 * (integer literal 1 → 2 outside strict mode), so a normalizationPlugins
 * flip is a REAL behavior change at the replay layer — the canonical AST
 * and every structural hash downstream move. Self-gated per the
 * interface doctrine; touches nothing outside this test's configs.
 */
final class CacheKeyProbePlugin implements NormalizationPlugin
{
    #[\Override]
    public function visit(Node $node, string $mode): void
    {
        if ($mode === 'strict') {
            return;
        }
        if ($node instanceof Node\Scalar\Int_ && $node->value === 1) {
            $node->value = 2;
        }
    }
}
