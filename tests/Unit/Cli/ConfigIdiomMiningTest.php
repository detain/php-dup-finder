<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Cli\ConfigLoader;
use Phpdup\Extraction\BlockExtractor;

/**
 * Item 3.1 ingestion (MINE-D): the `idiom_mining` demand knob's config
 * contract. The census is only honest if the demand can never half-arm,
 * so the knob's guarantees are pinned here, at the boundary:
 *
 *  - default OFF: every historical load is byte-identical (goldens, CLI
 *    posture, library defaults);
 *  - boolean-only at the validate door (no 'true'-string laundering);
 *  - the fragment-roster PRECONDITION as a Config CONSTRUCTION invariant,
 *    enforced on every route (CLI overrides, config file, programmatic):
 *    the empty all-default roster does NOT mine (fragment is 2.1 opt-in
 *    posture), and an armed census over a non-mining roster is the quiet
 *    zero this family refuses — the load fails loudly with guidance;
 *  - root-only: refused per-directory (one census per run, never one per
 *    subtree — a subtree split would silently starve part of the walk);
 *  - root-carried through Config::withOverrides (per-file derivations keep
 *    the run's demand stance).
 */
final class ConfigIdiomMiningTest extends TestCase
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
        $dir = sys_get_temp_dir() . '/phpdup-idiomcfg-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    public function testDefaultOffOnEveryRoute(): void
    {
        $this->assertFalse((new ConfigLoader())->load([$this->tmpDir()], null)->idiomMining);
        $this->assertFalse((new ConfigLoader())->load([$this->tmpDir()], null, [])->idiomMining);
        $this->assertFalse(Config::defaults([$this->tmpDir()])->idiomMining);
    }

    public function testConfigFileCarriesArmedWithFragmentRoster(): void
    {
        $dir  = $this->tmpDir();
        $file = $dir . '/phpdup.json';
        file_put_contents($file, json_encode([
            'idiom_mining' => true,
            'kinds'        => ['function', 'method', 'fragment'],
        ]));
        $config = (new ConfigLoader())->load([$dir], $file);
        $this->assertTrue($config->idiomMining);
        $this->assertContains(BlockExtractor::FRAGMENT_KIND, $config->allowedKinds);
    }

    public function testCliOverridesCarryArmedStance(): void
    {
        $config = (new ConfigLoader())->load(
            [$this->tmpDir()],
            null,
            ['idiom_mining' => true, 'allowed_kinds' => ['function', 'fragment']],
        );
        $this->assertTrue($config->idiomMining);
    }

    public function testNonBooleanRefusedAtTheDoor(): void
    {
        (new ConfigLoader())->validate(['idiom_mining' => true]);
        (new ConfigLoader())->validate(['idiom_mining' => false]);
        try {
            (new ConfigLoader())->validate(['idiom_mining' => 'yes']);
            $this->fail('a string must not launder the census demand on');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('idiom_mining must be a boolean', $e->getMessage());
        }
        $this->expectException(\RuntimeException::class);
        (new ConfigLoader())->validate(['idiom_mining' => 1]);
    }

    public function testArmedWithoutFragmentFailsAsConstructionInvariant(): void
    {
        // The empty roster means "all the DEFAULT kinds", and the 2.1
        // posture keeps fragment out of them — arming on top of it would
        // ingest a census that silently never saw a statement list.
        try {
            new Config(paths: ['/nowhere'], exclude: [], idiomMining: true);
            $this->fail('empty-roster arm must not construct');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('fragment', $e->getMessage());
            $this->assertStringContainsString('idiom_mining', $e->getMessage());
        }
        try {
            new Config(paths: ['/nowhere'], exclude: [], allowedKinds: ['function', 'method'], idiomMining: true);
            $this->fail('fragment-less roster arm must not construct');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('fragment', $e->getMessage());
        }
        // A load through every channel lands on the same invariant:
        $dir  = $this->tmpDir();
        $file = $dir . '/phpdup.json';
        file_put_contents($file, json_encode(['idiom_mining' => true]));
        $this->expectException(\InvalidArgumentException::class);
        (new ConfigLoader())->load([$dir], $file);
    }

    public function testArmedWithFragmentConstructsCleanly(): void
    {
        $config = new Config(
            paths: ['/nowhere'],
            exclude: [],
            allowedKinds: ['function', BlockExtractor::FRAGMENT_KIND],
            idiomMining: true,
        );
        $this->assertTrue($config->idiomMining);
    }

    public function testRootOnlyRefusedPerDirectory(): void
    {
        // One census per run: a subtree flipping the demand would mint
        // rows for some files and skip others, all inside one report.
        $dir = $this->tmpDir();
        mkdir($dir . '/sub', 0o777, true);
        file_put_contents($dir . '/sub/.phpdup.json', json_encode(['idiom_mining' => true]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('idiom_mining');
        (new ConfigLoader())->load([$dir], null);
    }

    public function testDerivedConfigsCopyTheRootStanceNotReplaceIt(): void
    {
        $armed = new Config(
            paths: ['/nowhere'],
            exclude: [],
            allowedKinds: ['method', BlockExtractor::FRAGMENT_KIND],
            idiomMining: true,
        );
        // Per-directory shapes may retune kinds; the DEMAND is root-only,
        // so every per-file derivation carries it verbatim (a subtree
        // roster that strips fragment then fails loudly at the derived
        // Config's own invariant, never mid-census).
        $derived = $armed->withOverrides(['allowed_kinds' => ['method', BlockExtractor::FRAGMENT_KIND]]);
        $this->assertTrue($derived->idiomMining);
        $this->assertTrue($armed->withOverrides([])->idiomMining);
    }
}
