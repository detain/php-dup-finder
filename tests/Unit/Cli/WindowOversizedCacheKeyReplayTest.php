<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Persistence\ClusterCache;
use Phpdup\Persistence\IndexStore;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Util\LineRange;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Item 1.2 (E2) — the three-key law for window_oversized, fenced like
 * once-026 fenced its roster: the extraction posture changes WHICH
 * BLOCKS EXIST (windows replace whole-giant cuts), so it must bind the
 * tool cache key (pinned in WindowClustering coverage), the IndexStore
 * key and the ClusterCache key. A warm snapshot saved under one
 * posture must never serve the other — including the one-time
 * invalidation flip every pre-E2 cache takes when the default-on
 * posture lands.
 *
 * Layers (template: CacheKeyKnobReplayTest): key layer via reflection,
 * store layer via the real stores' header gates, replay layer through
 * the actual stages with a shared cacheDir.
 */
final class WindowOversizedCacheKeyReplayTest extends TestCase
{
    /** @var list<string> */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            ) as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($dir);
        }
    }

    private function tmpDir(): string
    {
        $dir = sys_get_temp_dir() . '/phpdup-wkr-' . bin2hex(random_bytes(6));
        $this->assertNotFalse(mkdir($dir, 0o777, true));
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    // ── layer 1: both stage keys bind the flip ──────────────────────────

    public function testIndexStoreKeyBindsWindowPosture(): void
    {
        $stage = new PreprocessStage(useCache: true);
        $keyOf = new \ReflectionMethod(PreprocessStage::class, 'indexStoreConfigKey');

        $on  = new Config(paths: ['/x'], exclude: [], windowOversized: true);
        $off = new Config(paths: ['/x'], exclude: [], windowOversized: false);

        $this->assertNotSame($keyOf->invoke($stage, $on), $keyOf->invoke($stage, $off),
            'windowing reshapes the block set — the IndexStore key must bind the posture');
        $this->assertSame($keyOf->invoke($stage, $on), $keyOf->invoke($stage, $on),
            'non-vacuity: the key is a pure function of the config');
    }

    public function testClusterCacheKeyBindsWindowPosture(): void
    {
        $stage = new ClusterStage(exactOnly: false);
        $keyOf = new \ReflectionMethod(ClusterStage::class, 'cacheConfigKey');

        $on  = new Config(paths: ['/x'], exclude: [], windowOversized: true);
        $off = new Config(paths: ['/x'], exclude: [], windowOversized: false);

        $this->assertNotSame($keyOf->invoke($stage, $on), $keyOf->invoke($stage, $off),
            'edge membership differs across the posture — the cluster cache must bind it');
        $this->assertSame($keyOf->invoke($stage, $on), $keyOf->invoke($stage, $on),
            'non-vacuity: the key is a pure function of the config');
    }

    // ── layer 2: the real stores refuse the cross-serve ─────────────────

    private static function stubBlock(string $id, string $hash): Block
    {
        $block = new Block(
            'stub.php', new LineRange(1, 5), 'function', null, null, $id,
            new Node\Scalar\Int_(1),
        );
        $block->id = $id;
        $block->structuralHash = $hash;
        return $block;
    }

    public function testIndexStoreRefusesCrossPostureServe(): void
    {
        $src = $this->tmpDir() . '/probe.php';
        file_put_contents($src, "<?php class WindowKeyProbe { public function f(\$x) { return \$x + 1; } }\n");

        $keyOn  = sha1('window-on-key');
        $keyOff = sha1('window-off-key');
        $dir    = $this->tmpDir();
        $blocks = [self::stubBlock('probe-a', 'hash-a'), self::stubBlock('probe-b', 'hash-b')];

        (new IndexStore($dir, $keyOn))->save($src, $blocks);
        $this->assertNull((new IndexStore($dir, $keyOff))->load($src),
            'a snapshot saved under the other extraction posture must never serve');
        $served = (new IndexStore($dir, $keyOn))->load($src);
        $this->assertNotNull($served, 'own-posture serve still works (non-vacuity)');
        $this->assertCount(count($blocks), $served);
    }

    public function testClusterCacheRefusesCrossPostureServe(): void
    {
        $blocks  = [self::stubBlock('a', 'h1'), self::stubBlock('b', 'h2')];
        $cluster = new Cluster('Cdead001', $blocks, 1.0, true);
        $dir     = $this->tmpDir();

        (new ClusterCache($dir, 'key-on'))->save($blocks, [$cluster]);
        $this->assertNull((new ClusterCache($dir, 'key-off'))->load($blocks),
            'a cluster snapshot from the other side of the posture must never replay');
        $served = (new ClusterCache($dir, 'key-on'))->load($blocks);
        $this->assertNotNull($served, 'own-side serve still works (non-vacuity)');
        $this->assertSame('Cdead001', $served[0]->id);
    }

    // ── layer 3: behavioral replay with a genuinely giant corpus ────────

    private function plantGiants(string $dir): void
    {
        $body = '';
        for ($i = 0; $i < 400; ++$i) {
            $body .= "    \$x = {$i};\n";
        }
        // The small twin function guarantees BOTH postures produce
        // cacheable blocks (an off-posture file with zero blocks is
        // never snapshotted — reusedFiles would stay 0 for the wrong
        // reason). The giants carry the window delta.
        $small = "\nfunction small_twin() {\n    \$a = 1;\n    \$b = 2;\n    return \$a + \$b;\n}\n";
        file_put_contents($dir . '/a.php', "<?php\nfunction big_alpha() {\n{$body}}\n{$small}");
        file_put_contents($dir . '/b.php', "<?php\nfunction big_beta() {\n{$body}}\n{$small}");
    }

    /** @param array<string,mixed> $extra */
    private function drive(string $dir, array $extra, string $cacheDir): PipelineState
    {
        $config = new Config(...array_merge([
            'paths'                => [$dir],
            'exclude'              => [],
            'similarityThreshold'  => 0.80,
            'treeThreshold'        => 0.85,
            'maxDocumentFrequency' => 0.9,
            'minClusterImpact'     => 1,
            'lazyAst'              => false,
            'workers'              => 1,
            'incremental'          => true,
        ], $extra, ['cacheDir' => $cacheDir]));

        $state = new PipelineState($config);
        $out   = new NullOutput();
        (new ScanningStage())->run($state, $out);
        (new PreprocessStage(useCache: true))->run($state, $out);
        (new ClusterStage(exactOnly: false, useClusterCache: true))->run($state, $out);
        return $state;
    }

    /** @return list<string> sorted ids+hashes of EVERY block in the state */
    private static function blockFingerprint(PipelineState $s): array
    {
        $rows = [];
        foreach ($s->blocks as $b) {
            $rows[] = $b->kind . '|' . $b->id . '|' . $b->structuralHash . '|' . $b->file;
        }
        sort($rows);
        return $rows;
    }

    /** @return list<string> sorted window member ids */
    private static function windowKinds(PipelineState $s): array
    {
        $rows = [];
        foreach ($s->blocks as $b) {
            if ($b->kind === BlockExtractor::WINDOW_KIND) {
                $rows[] = $b->id . '#' . $b->structuralHash;
            }
        }
        sort($rows);
        return $rows;
    }

    public function testWarmCachesMissAcrossTheWindowFlipAndNeverPoison(): void
    {
        $corpus = $this->tmpDir();
        $this->plantGiants($corpus);
        $shared = $this->tmpDir();
        $cold   = $this->tmpDir();

        // Warm control on the ON side.
        $p1 = $this->drive($corpus, ['windowOversized' => true], $shared);
        $this->assertNotEmpty(self::windowKinds($p1), 'corpus must mint windows on-side');
        $this->assertSame(0, $p1->reusedFiles, 'first run into virgin cacheDir is cold');
        $p2 = $this->drive($corpus, ['windowOversized' => true], $shared);
        $this->assertGreaterThan(0, $p2->reusedFiles, 'same-posture warm run reuses the snapshot');

        // Flip to OFF: BOTH stores must refuse the cross-serve despite
        // the corpus hashes matching — the one-time invalidation every
        // pre-E2 cache takes, pinned behaviorally.
        $flipped = $this->drive($corpus, ['windowOversized' => false], $shared);
        $this->assertSame(0, $flipped->reusedFiles,
            'cross-posture IndexStore reuse is the once-026 misfeed class — refused');
        $this->assertFalse($flipped->clustersReplayed,
            'the cluster snapshot warmed on the other side must never replay');
        $this->assertSame(2, $flipped->oversizedBlocks, 'off-side recounts both giant parents as dropped');

        // STRONG no-poisoning equality on the ON side: a flipped
        // posture warms its OWN snapshot; an ON-side cross-flip then
        // refuses BOTH stores and the refused run must equal the
        // virgin-cache ON run byte-for-byte (fresh-only semantics make
        // the block fingerprint cross-posture comparable).
        $again = $this->drive($corpus, ['windowOversized' => false], $shared);
        $this->assertGreaterThan(0, $again->reusedFiles,
            'the OFF posture keeps its OWN warm cache (refusal is about the flip alone)');

        $backOn = $this->drive($corpus, ['windowOversized' => true], $shared);
        $this->assertSame(0, $backOn->reusedFiles, 'the return flip must miss BOTH warmed stores');
        $this->assertFalse($backOn->clustersReplayed);

        $virginOn = $this->drive($corpus, ['windowOversized' => true], $cold);
        $this->assertSame(self::blockFingerprint($virginOn), self::blockFingerprint($backOn),
            'cache-refused run must equal the virgin-cache run (no poisoning residue)');
        $this->assertSame(
            array_map(static fn(Cluster $c): string => $c->id, $virginOn->clusters),
            array_map(static fn(Cluster $c): string => $c->id, $backOn->clusters),
            'refused cluster replay equals the virgin clustering',
        );
    }
}
