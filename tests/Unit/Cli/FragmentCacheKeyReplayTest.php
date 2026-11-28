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
 * Item 2.1 — the three-key law for the 'fragment' kind, fenced exactly
 * like window_oversized was for 1.2: selecting the kind reshapes the
 * block set, so the roster must bind the tool key (PreprocessWorker's
 * toolFor, pinned in BlockExtractorFragment coverage), the IndexStore
 * key and the ClusterCache key. A warm snapshot saved with fragments
 * must never serve a default run — or vice versa.
 *
 * The replay layer additionally pins the cold/warm parity law of the
 * suppression pass: it reads ONLY the block fields (key/prev/next/pos/
 * size/whole), which PHP serialization carries through IndexStore
 * untouched — so a warm replay (cached Blocks, NO mined rows) suppresses
 * exactly what the cold run (fresh rows) did. SequenceIndex is never
 * consulted by the suppression path; warm files' sequences legitimately
 * go unmined.
 *
 * Layers (template: WindowOversizedCacheKeyReplayTest).
 */
final class FragmentCacheKeyReplayTest extends TestCase
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
        $dir = sys_get_temp_dir() . '/phpdup-fkr-' . bin2hex(random_bytes(6));
        $this->assertNotFalse(mkdir($dir, 0o777, true));
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    /** @return list<string> */
    private function roster(bool $withFragment): array
    {
        $kinds = ['function', 'method', 'closure', 'arrow', 'if', 'for', 'foreach', 'while', 'do', 'try', 'switch', 'match'];
        if ($withFragment) {
            $kinds[] = BlockExtractor::FRAGMENT_KIND;
        }
        return $kinds;
    }

    // ── layer 1: both stage keys bind the roster ────────────────────────

    public function testIndexStoreKeyBindsTheFragmentRoster(): void
    {
        $stage = new PreprocessStage(useCache: true);
        $keyOf = new \ReflectionMethod(PreprocessStage::class, 'indexStoreConfigKey');

        $on  = new Config(paths: ['/x'], exclude: [], allowedKinds: $this->roster(true));
        $off = new Config(paths: ['/x'], exclude: [], allowedKinds: $this->roster(false));

        $this->assertNotSame($keyOf->invoke($stage, $on), $keyOf->invoke($stage, $off),
            'fragment minting reshapes the block set — the IndexStore key must bind the roster');
        $this->assertSame($keyOf->invoke($stage, $on), $keyOf->invoke($stage, $on),
            'non-vacuity: the key is a pure function of the config');
    }

    public function testClusterCacheKeyBindsTheFragmentRoster(): void
    {
        $stage = new ClusterStage(exactOnly: false);
        $keyOf = new \ReflectionMethod(ClusterStage::class, 'cacheConfigKey');

        $on  = new Config(paths: ['/x'], exclude: [], allowedKinds: $this->roster(true));
        $off = new Config(paths: ['/x'], exclude: [], allowedKinds: $this->roster(false));

        $this->assertNotSame($keyOf->invoke($stage, $on), $keyOf->invoke($stage, $off),
            'fragment edges differ across the roster — the cluster cache must bind it');
        $this->assertSame($keyOf->invoke($stage, $on), $keyOf->invoke($stage, $on),
            'non-vacuity: the key is a pure function of the config');
    }

    // ── layer 2: the real stores refuse the cross-serve, fields survive ──

    private static function fragBlock(string $id, string $hash, string $key, string $prev, string $next, int $pos, int $size, bool $whole): Block
    {
        $block = new Block('stub.php', new LineRange($pos + 1, $pos + $size), BlockExtractor::FRAGMENT_KIND, null, null, $id, new Node\Stmt\Nop());
        $block->id = $id;
        $block->structuralHash = $hash;
        $block->fragmentKey = $key;
        $block->fragmentPrev = $prev;
        $block->fragmentNext = $next;
        $block->fragmentPos = $pos;
        $block->fragmentSize = $size;
        $block->fragmentWhole = $whole;
        return $block;
    }

    public function testIndexStoreRefusesCrossRosterServeAndKeepsFragmentFields(): void
    {
        $src = $this->tmpDir() . '/probe.php';
        file_put_contents($src, "<?php function pk(\$a) { \$b = 1; return \$b; }\n");

        $keyOn  = sha1('fragment-on-key');
        $keyOff = sha1('fragment-off-key');
        $dir    = $this->tmpDir();
        $blocks = [
            self::fragBlock('f-a', 'hash-a', 'k1~k2', 'k0', 'k3', 1, 2, false),
            self::fragBlock('f-b', 'hash-b', 'k2~k3', 'k1', '', 2, 2, true),
        ];

        (new IndexStore($dir, $keyOn))->save($src, $blocks);
        $this->assertNull((new IndexStore($dir, $keyOff))->load($src),
            'a snapshot saved under the other extraction roster must never serve');
        $served = (new IndexStore($dir, $keyOn))->load($src);
        $this->assertNotNull($served, 'own-roster serve still works (non-vacuity)');
        $this->assertCount(2, $served);
        $this->assertSame('k1~k2', $served[0]->fragmentKey, 'the suppression fields must survive serialization');
        $this->assertSame('k0', $served[0]->fragmentPrev);
        $this->assertSame('k3', $served[0]->fragmentNext);
        $this->assertSame(1, $served[0]->fragmentPos);
        $this->assertSame(2, $served[0]->fragmentSize);
        $this->assertFalse($served[0]->fragmentWhole);
        $this->assertTrue($served[1]->fragmentWhole);
    }

    public function testClusterCacheRefusesCrossRosterServe(): void
    {
        $blocks  = [self::fragBlock('a', 'h1', 'k', '', '', 0, 3, false), self::stubMethod('b', 'h2')];
        $cluster = new Cluster('Cfrag001', $blocks, 1.0, true);
        $dir     = $this->tmpDir();

        (new ClusterCache($dir, 'key-on'))->save($blocks, [$cluster]);
        $this->assertNull((new ClusterCache($dir, 'key-off'))->load($blocks),
            'a cluster snapshot from the other side of the roster must never replay');
        $served = (new ClusterCache($dir, 'key-on'))->load($blocks);
        $this->assertNotNull($served, 'own-side serve still works (non-vacuity)');
        $this->assertSame('Cfrag001', $served[0]->id);
    }

    private static function stubMethod(string $id, string $hash): Block
    {
        $block = new Block('stub.php', new LineRange(1, 5), 'method', null, null, $id, new Node\Stmt\Nop());
        $block->id = $id;
        $block->structuralHash = $hash;
        return $block;
    }

    // ── layer 3: behavioral replay — the cold/warm parity law ───────────

    private function plantTriples(string $dir): void
    {
        // Three functions sharing the statements 2-4 (a 3-site run at list
        // position 1) and 1-3 in the common prefix: the suppression has
        // real field evidence to work from on BOTH paths.
        file_put_contents($dir . '/f.php', "<?php\nfunction a(\$v) {\n    \$p = init(\$v);\n    \$r = q(\$p);\n    \$s = w(\$r);\n    e(\$s);\n    return \$p;\n}\n");
        file_put_contents($dir . '/g.php', "<?php\nfunction b(\$v) {\n    \$p = init(\$v);\n    \$r = q(\$p);\n    \$s = w(\$r);\n    e(\$s);\n    return \$r;\n}\n");
        file_put_contents($dir . '/h.php', "<?php\nfunction c(\$v) {\n    \$p = init(\$v);\n    \$r = q(\$p);\n    \$s = w(\$r);\n    e(\$s);\n    exit;\n}\n");
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

    /** @return list<string> */
    private static function blockFingerprint(PipelineState $s): array
    {
        $rows = [];
        foreach ($s->blocks as $b) {
            $rows[] = $b->kind . '|' . $b->id . '|' . $b->structuralHash . '|' . $b->file;
        }
        sort($rows);
        return $rows;
    }

    /** @return list<string> */
    private static function fragmentSlots(PipelineState $s): array
    {
        $rows = [];
        foreach ($s->blocks as $b) {
            if ($b->kind === BlockExtractor::FRAGMENT_KIND) {
                $rows[] = basename($b->file) . '#' . $b->fragmentPos . ':' . $b->fragmentSize . '#' . $b->structuralHash;
            }
        }
        sort($rows);
        return $rows;
    }

    public function testWarmFragmentReplaySuppressesExactlyLikeColdAndNeverLeaksAcrossTheFlip(): void
    {
        $corpus = $this->tmpDir();
        $this->plantTriples($corpus);
        $shared = $this->tmpDir();
        $cold   = $this->tmpDir();

        $c1 = $this->drive($corpus, ['allowedKinds' => $this->roster(true)], $shared);
        $this->assertNotEmpty(self::fragmentSlots($c1), 'corpus must mint surviving fragments on-side');
        $this->assertSame(0, $c1->reusedFiles, 'first run into a virgin cacheDir is cold');

        $c2 = $this->drive($corpus, ['allowedKinds' => $this->roster(true)], $shared);
        $this->assertGreaterThan(0, $c2->reusedFiles, 'same-roster warm run reuses the snapshot');
        // The parity law: warm files carry NO mined rows — suppression read
        // the cached block fields and produced the identical survivor set.
        $this->assertSame(self::fragmentSlots($c1), self::fragmentSlots($c2),
            'warm suppression equals cold suppression, field-for-field');
        $this->assertSame(self::blockFingerprint($c1), self::blockFingerprint($c2), 'whole block set agrees');
        $this->assertSame(
            array_map(static fn (Cluster $c): string => $c->id, $c1->clusters),
            array_map(static fn (Cluster $c): string => $c->id, $c2->clusters),
            'clustering replays identical',
        );

        // Flip to the default roster: the fragment-warmed snapshot must be
        // refused in full — minting fragments changes WHICH blocks exist.
        $flipped = $this->drive($corpus, ['allowedKinds' => $this->roster(false)], $shared);
        $this->assertSame(0, $flipped->reusedFiles, 'cross-roster IndexStore reuse is the misfeed class — refused');
        $this->assertFalse($flipped->clustersReplayed, 'the fragment-warmed cluster snapshot must never replay into a default run');
        foreach ($flipped->blocks as $b) {
            $this->assertNotSame(BlockExtractor::FRAGMENT_KIND, $b->kind, 'default roster mints no fragments at all');
        }

        $again = $this->drive($corpus, ['allowedKinds' => $this->roster(false)], $shared);
        $this->assertGreaterThan(0, $again->reusedFiles, 'the default roster keeps its OWN warm cache');

        $backOn = $this->drive($corpus, ['allowedKinds' => $this->roster(true)], $shared);
        $this->assertSame(0, $backOn->reusedFiles, 'the return flip must miss BOTH warmed stores');
        $this->assertFalse($backOn->clustersReplayed);

        $virgin = $this->drive($corpus, ['allowedKinds' => $this->roster(true)], $cold);
        $this->assertSame(self::blockFingerprint($virgin), self::blockFingerprint($backOn),
            'cache-refused run must equal the virgin-cache run (no poisoning residue)');
        $this->assertSame(
            array_map(static fn (Cluster $c): string => $c->id, $virgin->clusters),
            array_map(static fn (Cluster $c): string => $c->id, $backOn->clusters),
            'refused cluster replay equals the virgin clustering',
        );
    }
}
