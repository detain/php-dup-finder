<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Cli\ConfigLoader;

/**
 * Item 6.4 (THEME): the `themes` demand knob's config contract, mirroring
 * the corpus_rollups family (ConfigCorpusRollupsTest) one seam later —
 * theming is a REPORT-STAGE view, so the idiom_mining construction
 * precondition has no analogue here (nothing at construction or
 * preprocess depends on the demand; that asymmetry is the point of the
 * knob's cache-clean posture and is asserted as such).
 *
 *  - default OFF on every route (goldens stay byte-frozen unarmed);
 *  - boolean-only at the validate door;
 *  - root-only: refused per-directory (one theming pass over the whole
 *    reported summary, never one per subtree);
 *  - root-carried through Config::withOverrides (per-file derivations
 *    keep the run's demand stance verbatim).
 */
final class ConfigThemesTest extends TestCase
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
        $dir = sys_get_temp_dir() . '/phpdup-themecfg-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    public function testDefaultOffOnEveryRoute(): void
    {
        $this->assertFalse((new ConfigLoader())->load([$this->tmpDir()], null)->themes);
        $this->assertFalse((new ConfigLoader())->load([$this->tmpDir()], null, [])->themes);
        $this->assertFalse(Config::defaults([$this->tmpDir()])->themes);
    }

    public function testConfigFileCarriesArmed(): void
    {
        $dir  = $this->tmpDir();
        $file = $dir . '/phpdup.json';
        file_put_contents($file, json_encode(['themes' => true]));
        $this->assertTrue((new ConfigLoader())->load([$dir], $file)->themes);
    }

    public function testOverrideDictCarriesArmedStance(): void
    {
        // No argv leg at this tip (Command.php roster staging precedent —
        // corpus_rollups/idiom_mining); the programmatic override dict
        // rides the same flat-resolver route an argv leg would.
        $config = (new ConfigLoader())->load(
            [$this->tmpDir()],
            null,
            ['themes' => true],
        );
        $this->assertTrue($config->themes);
    }

    public function testNonBooleanRefusedAtTheDoor(): void
    {
        (new ConfigLoader())->validate(['themes' => true]);
        (new ConfigLoader())->validate(['themes' => false]);
        try {
            (new ConfigLoader())->validate(['themes' => 'yes']);
            $this->fail('a string must not launder the theming demand on');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('themes must be a boolean', $e->getMessage());
        }
        $this->expectException(\RuntimeException::class);
        (new ConfigLoader())->validate(['themes' => 1]);
    }

    public function testRootOnlyRefusedPerDirectory(): void
    {
        // One theming pass over the reported findings per run: a subtree
        // flipping the demand would theme some findings only — the
        // summary grouping must be whole-corpus or absent.
        $dir = $this->tmpDir();
        mkdir($dir . '/sub', 0o777, true);
        file_put_contents($dir . '/sub/.phpdup.json', json_encode(['themes' => true]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('themes');
        (new ConfigLoader())->load([$dir], null);
    }

    public function testDerivedConfigsCopyTheRootStanceNotReplaceIt(): void
    {
        $armed = new Config(paths: ['/nowhere'], exclude: [], themes: true);
        // Per-directory shapes may retune sizes; the DEMAND is root-only,
        // so every per-file derivation carries it verbatim.
        $derived = $armed->withOverrides(['min_block_size' => 4]);
        $this->assertTrue($derived->themes);
        $this->assertSame(4, $derived->minBlockSize);
        $this->assertTrue($armed->withOverrides([])->themes);
        // And the demand never arms a construction precondition — the
        // asymmetry vs idiom_mining (no roster, no invariant, no cache
        // key): an unarmed derivation is inert in every dimension.
        $this->assertFalse((new Config(paths: ['/nowhere'], exclude: []))->withOverrides(['min_block_size' => 4])->themes);
    }
}
