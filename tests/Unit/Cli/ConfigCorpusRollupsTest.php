<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Cli\ConfigLoader;

/**
 * Item 2.2 (ROLLUP): the `corpus_rollups` demand knob's config contract,
 * mirroring the idiom_mining family (ConfigIdiomMiningTest) one seam
 * later — the rollups arming is report-stage only, so the fragment-roster
 * CONSTRUCTION PRECONDITION has no analogue here (nothing at construction
 * or preprocess depends on the demand; that asymmetry is the point of the
 * knob's cache-clean posture and is asserted as such).
 *
 *  - default OFF on every route (goldens stay byte-frozen unarmed);
 *  - boolean-only at the validate door;
 *  - root-only: refused per-directory (one rollup census per run, never
 *    one per subtree);
 *  - root-carried through Config::withOverrides (per-file derivations
 *    keep the run's demand stance verbatim).
 */
final class ConfigCorpusRollupsTest extends TestCase
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
        $this->tmpDirs = [];
    }

    private function tmpDir(): string
    {
        $dir = sys_get_temp_dir() . '/phpdup-rollupcfg-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    public function testDefaultOffOnEveryRoute(): void
    {
        $this->assertFalse((new ConfigLoader())->load([$this->tmpDir()], null)->corpusRollups);
        $this->assertFalse((new ConfigLoader())->load([$this->tmpDir()], null, [])->corpusRollups);
        $this->assertFalse(Config::defaults([$this->tmpDir()])->corpusRollups);
    }

    public function testConfigFileCarriesArmed(): void
    {
        $dir  = $this->tmpDir();
        $file = $dir . '/phpdup.json';
        file_put_contents($file, json_encode(['corpus_rollups' => true]));
        $this->assertTrue((new ConfigLoader())->load([$dir], $file)->corpusRollups);
    }

    public function testOverrideDictCarriesArmedStance(): void
    {
        // No argv leg at this tip (Command.php roster staging precedent —
        // idiom_mining/ml_pair_allow_private); the programmatic override
        // dict rides the same flat-resolver route an argv leg would.
        $config = (new ConfigLoader())->load(
            [$this->tmpDir()],
            null,
            ['corpus_rollups' => true],
        );
        $this->assertTrue($config->corpusRollups);
    }

    public function testNonBooleanRefusedAtTheDoor(): void
    {
        (new ConfigLoader())->validate(['corpus_rollups' => true]);
        (new ConfigLoader())->validate(['corpus_rollups' => false]);
        try {
            (new ConfigLoader())->validate(['corpus_rollups' => 'yes']);
            $this->fail('a string must not launder the rollup demand on');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('corpus_rollups must be a boolean', $e->getMessage());
        }
        $this->expectException(\RuntimeException::class);
        (new ConfigLoader())->validate(['corpus_rollups' => 1]);
    }

    public function testRootOnlyRefusedPerDirectory(): void
    {
        // One rollup census per run: a subtree flipping the demand would
        // pay (or skip) a whole-corpus AST sweep file-by-file inside one
        // report — the stance family's silent-partial hazard.
        $dir = $this->tmpDir();
        mkdir($dir . '/sub', 0o777, true);
        file_put_contents($dir . '/sub/.phpdup.json', json_encode(['corpus_rollups' => true]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('corpus_rollups');
        (new ConfigLoader())->load([$dir], null);
    }

    public function testDerivedConfigsCopyTheRootStanceNotReplaceIt(): void
    {
        $armed = new Config(paths: ['/nowhere'], exclude: [], corpusRollups: true);
        // Per-directory shapes may retune sizes; the DEMAND is root-only,
        // so every per-file derivation carries it verbatim.
        $derived = $armed->withOverrides(['min_block_size' => 4]);
        $this->assertTrue($derived->corpusRollups);
        $this->assertSame(4, $derived->minBlockSize);
        $this->assertTrue($armed->withOverrides([])->corpusRollups);
        // And the demand never arms a construction precondition — the
        // asymmetry vs idiom_mining (no roster, no invariant, no cache
        // key): an unarmed derivation is inert in every dimension.
        $this->assertFalse((new Config(paths: ['/nowhere'], exclude: []))->withOverrides(['min_block_size' => 4])->corpusRollups);
    }
}
