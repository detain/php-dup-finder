<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Cli\ConfigLoader;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Item 3.4 ingestion (S4): the `symbols_forced` demand knob's config
 * contract. The symbol lane arms a second candidate stream inside the
 * Clusterer and mounts the SymbolDuplication analyzer in ReportStage, so
 * its OFF posture must be provably inert and its ON posture must never
 * half-arm:
 *
 *  - default OFF: every historical load is byte-identical (goldens, CLI
 *    posture, library defaults);
 *  - boolean-only at the validate door (no 'true'-string laundering);
 *  - NO construction precondition: unlike the 3.1 census (fragment
 *    roster), the symbol routes read fields every named block already
 *    carries — an armed Config over the default kinds roster is VALID;
 *  - root-only: refused per-directory (forced pairs span the whole run;
 *    a subtree flip would fork the candidate stream mid-report);
 *  - root-carried through Config::withOverrides (per-file derivations
 *    keep the run's demand stance);
 *  - the --symbols CLI flag reaches the knob through one Command flip.
 */
final class SymbolsKnobConfigTest extends TestCase
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
        $dir = sys_get_temp_dir() . '/phpdup-symbolscfg-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    public function testDefaultOffOnEveryRoute(): void
    {
        $this->assertFalse((new ConfigLoader())->load([$this->tmpDir()], null)->symbolsForced);
        $this->assertFalse((new ConfigLoader())->load([$this->tmpDir()], null, [])->symbolsForced);
        $this->assertFalse(Config::defaults([$this->tmpDir()])->symbolsForced);
    }

    public function testConfigFileCarriesArmedStance(): void
    {
        $dir  = $this->tmpDir();
        $file = $dir . '/phpdup.json';
        file_put_contents($file, json_encode(['symbols_forced' => true]));
        $this->assertTrue((new ConfigLoader())->load([$dir], $file)->symbolsForced);
    }

    public function testCliOverridesCarryArmedStance(): void
    {
        $config = (new ConfigLoader())->load(
            [$this->tmpDir()],
            null,
            ['symbols_forced' => true],
        );
        $this->assertTrue($config->symbolsForced);
    }

    public function testNonBooleanRefusedAtTheDoor(): void
    {
        (new ConfigLoader())->validate(['symbols_forced' => true]);
        (new ConfigLoader())->validate(['symbols_forced' => false]);
        try {
            (new ConfigLoader())->validate(['symbols_forced' => 'yes']);
            $this->fail('a string must not launder the symbol demand on');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('symbols_forced must be a boolean', $e->getMessage());
        }
        $this->expectException(\RuntimeException::class);
        (new ConfigLoader())->validate(['symbols_forced' => 1]);
    }

    public function testArmedWithoutAnyPreconditionConstructsCleanly(): void
    {
        // Deliberate contrast with 3.1: the symbol routes read name/class/
        // file fields every extracted block carries, so the plain default
        // roster is a valid host — no fragment opt-in needed.
        $config = new Config(paths: ['/nowhere'], exclude: [], symbolsForced: true);
        $this->assertTrue($config->symbolsForced);
    }

    public function testRootOnlyRefusedPerDirectory(): void
    {
        // Forced pairs are run-wide: a subtree flipping the demand would
        // yield one candidate stream per shape and two contradictory
        // cluster sets inside a single report.
        $dir = $this->tmpDir();
        mkdir($dir . '/sub', 0o777, true);
        file_put_contents($dir . '/sub/.phpdup.json', json_encode(['symbols_forced' => true]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('symbols_forced');
        (new ConfigLoader())->load([$dir], null);
    }

    public function testDerivedConfigsCopyTheRootStanceNotReplaceIt(): void
    {
        $armed = new Config(paths: ['/nowhere'], exclude: [], symbolsForced: true);
        $derived = $armed->withOverrides(['min_block_size' => 99]);
        $this->assertTrue($derived->symbolsForced);
        $this->assertTrue($armed->withOverrides([])->symbolsForced);
    }

    public function testSymbolsFlagReachesTheCommandWithoutCrashingARun(): void
    {
        // CLI-wiring smoke: --symbols parses, flips the knob, and the run
        // exits 0 on a tiny corpus (surfacing depth is pinned by the
        // stage-driven analyzer e2e, not by this posture check).
        $dir = $this->tmpDir();
        file_put_contents($dir . '/k1.php', "<?php\nfunction s4_smoke_one(): int { return 1; }\n");
        file_put_contents($dir . '/k2.php', "<?php\nfunction s4_smoke_two(): int { return 2; }\n");

        $app = new Application('phpdup');
        $app->add(new \Phpdup\Cli\Command());
        $tester = new CommandTester($app->find('analyze'));

        $exit = $tester->execute(['paths' => [$dir], '--symbols' => true]);
        $this->assertSame(0, $exit, 'armed command run exits 0; display was: ' . $tester->getDisplay());
    }
}
