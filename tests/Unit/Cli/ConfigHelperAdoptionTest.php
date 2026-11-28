<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Cli\ConfigLoader;
use Phpdup\Extraction\BlockExtractor;

/**
 * Item 3.2 adoption (MINE-D-2): the `helper_adoption` demand knob's
 * config contract, mirroring the 3.1 idiom_mining posture key for key —
 * the adoption ledger is only honest if the demand can never half-arm:
 *
 *  - default OFF: every historical load is byte-identical;
 *  - boolean-only at the validate door (no 'true'-string laundering);
 *  - the idiom_mining PRECONDITION as a Config CONSTRUCTION invariant,
 *    enforced on every route: adoption re-queries the mined census
 *    (§5c one-shared-miner law — no second lookup index exists), so an
 *    armed adoption over an unmined corpus is the quiet zero this
 *    family refuses;
 *  - root-only: refused per-directory (one adoption ledger per run);
 *  - root-carried through Config::withOverrides.
 *
 * The fragment-roster invariant is NOT duplicated here: helper_adoption
 * requires idiom_mining, and idiom_mining already requires fragment —
 * the chain is stated once, at each link, exactly like the loader's.
 */
final class ConfigHelperAdoptionTest extends TestCase
{
    /** @var list<string> */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            ) as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($dir);
        }
        $this->tmpDirs = [];
    }

    private function tmpDir(): string
    {
        $dir = sys_get_temp_dir() . '/phpdup-adoptcfg-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    public function testDefaultOffOnEveryRoute(): void
    {
        $this->assertFalse((new ConfigLoader())->load([$this->tmpDir()], null)->helperAdoption);
        $this->assertFalse((new ConfigLoader())->load([$this->tmpDir()], null, [])->helperAdoption);
        $this->assertFalse(Config::defaults([$this->tmpDir()])->helperAdoption);
    }

    public function testConfigFileCarriesArmedWithIdiomChain(): void
    {
        $dir  = $this->tmpDir();
        $file = $dir . '/phpdup.json';
        file_put_contents($file, json_encode([
            'idiom_mining'    => true,
            'helper_adoption' => true,
            'kinds'           => ['function', 'method', 'fragment'],
        ]));
        $config = (new ConfigLoader())->load([$dir], $file);
        $this->assertTrue($config->helperAdoption);
        $this->assertTrue($config->idiomMining);
        $this->assertContains(BlockExtractor::FRAGMENT_KIND, $config->allowedKinds);
    }

    public function testCliOverridesCarryArmedStance(): void
    {
        $config = (new ConfigLoader())->load(
            [$this->tmpDir()],
            null,
            ['helper_adoption' => true, 'idiom_mining' => true, 'allowed_kinds' => ['function', 'fragment']],
        );
        $this->assertTrue($config->helperAdoption);
    }

    public function testNonBooleanRefusedAtTheDoor(): void
    {
        (new ConfigLoader())->validate(['helper_adoption' => true]);
        (new ConfigLoader())->validate(['helper_adoption' => false]);
        try {
            (new ConfigLoader())->validate(['helper_adoption' => 'yes']);
            $this->fail('a string must not launder the adoption demand on');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('helper_adoption must be a boolean', $e->getMessage());
        }
        $this->expectException(\RuntimeException::class);
        (new ConfigLoader())->validate(['helper_adoption' => 1]);
    }

    public function testArmedWithoutIdiomFailsAsConstructionInvariant(): void
    {
        // The census is the lookup substrate; adoption without it could
        // only ever report a quiet none over an unbuilt census.
        try {
            new Config(paths: ['/nowhere'], exclude: [], helperAdoption: true);
            $this->fail('idiom-less arm must not construct');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('helper_adoption', $e->getMessage());
            $this->assertStringContainsString('idiom_mining', $e->getMessage());
        }
        // A load through every channel lands on the same invariant:
        $dir  = $this->tmpDir();
        $file = $dir . '/phpdup.json';
        file_put_contents($file, json_encode([
            'helper_adoption' => true,
            'kinds'           => ['function', 'fragment'],
        ]));
        $this->expectException(\InvalidArgumentException::class);
        (new ConfigLoader())->load([$dir], $file);
    }

    public function testArmedWithIdiomConstructsCleanly(): void
    {
        $config = new Config(
            paths: ['/nowhere'],
            exclude: [],
            allowedKinds: ['function', BlockExtractor::FRAGMENT_KIND],
            idiomMining: true,
            helperAdoption: true,
        );
        $this->assertTrue($config->helperAdoption);
    }

    public function testRootOnlyRefusedPerDirectory(): void
    {
        // One adoption ledger per run: a subtree flipping the demand
        // would report copies for part of the corpus inside one run.
        $dir = $this->tmpDir();
        mkdir($dir . '/sub', 0o777, true);
        file_put_contents($dir . '/sub/.phpdup.json', json_encode(['helper_adoption' => true]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('helper_adoption');
        (new ConfigLoader())->load([$dir], null);
    }

    public function testDerivedConfigsCopyTheRootStanceNotReplaceIt(): void
    {
        $armed = new Config(
            paths: ['/nowhere'],
            exclude: [],
            allowedKinds: ['method', BlockExtractor::FRAGMENT_KIND],
            idiomMining: true,
            helperAdoption: true,
        );
        // Per-directory shapes may retune kinds; the DEMANDS are
        // root-only, so every derivation carries them verbatim (a
        // subtree roster that strips fragment then fails loudly at the
        // derived Config's own idiom invariant, never mid-adoption).
        $derived = $armed->withOverrides(['allowed_kinds' => ['method', BlockExtractor::FRAGMENT_KIND]]);
        $this->assertTrue($derived->helperAdoption);
        $this->assertTrue($armed->withOverrides([])->helperAdoption);
    }
}
