<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Cli\ConfigLoader;
use Phpdup\Extraction\BlockExtractor;

/**
 * Items 3.5/3.3 demand knobs (MINE-E): the config contract for
 * `call_sequence` and `literal_census`, each key for key against the
 * 3.2 helper_adoption precedent.
 *
 *  - default OFF on every historical load;
 *  - boolean-only at the validate door;
 *  - call_sequence rides the idiom_mining PRECONDITION (the ordered
 *    capture consumes the same fragment-gated statement lists — no
 *    lists, no sequences: a loud refusal, not a quiet zero);
 *  - literal_census stands ALONE: it walks the whole raw tree, so it
 *    needs no fragment roster and no sibling demand;
 *  - both root-only (refused per-directory) and root-carried through
 *    Config::withOverrides.
 */
final class ConfigCallLiteralDoorsTest extends TestCase
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
        $dir = sys_get_temp_dir() . '/phpdup-callcfg-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    public function testDefaultOffOnEveryRoute(): void
    {
        $this->assertFalse((new ConfigLoader())->load([$this->tmpDir()], null)->callSequence);
        $this->assertFalse((new ConfigLoader())->load([$this->tmpDir()], null, [])->literalCensus);
        $this->assertFalse(Config::defaults([$this->tmpDir()])->callSequence);
        $this->assertFalse(Config::defaults([$this->tmpDir()])->literalCensus);
    }

    public function testConfigFileCarriesCallArmedWithIdiomChain(): void
    {
        $dir  = $this->tmpDir();
        $file = $dir . '/phpdup.json';
        file_put_contents($file, json_encode([
            'idiom_mining'  => true,
            'call_sequence' => true,
            'kinds'         => ['function', 'method', 'fragment'],
        ]));
        $config = (new ConfigLoader())->load([$dir], $file);
        $this->assertTrue($config->callSequence);
        $this->assertTrue($config->idiomMining);
        $this->assertContains(BlockExtractor::FRAGMENT_KIND, $config->allowedKinds);
    }

    public function testLiteralArmsStandalone(): void
    {
        $dir  = $this->tmpDir();
        $file = $dir . '/phpdup.json';
        file_put_contents($file, json_encode(['literal_census' => true]));
        $config = (new ConfigLoader())->load([$dir], $file);
        $this->assertTrue($config->literalCensus);
        $this->assertFalse($config->idiomMining, 'the literal census demands nothing beside it');
    }

    public function testCliOverridesCarryArmedStance(): void
    {
        $config = (new ConfigLoader())->load(
            [$this->tmpDir()],
            null,
            ['call_sequence' => true, 'idiom_mining' => true, 'allowed_kinds' => ['function', 'fragment'], 'literal_census' => true],
        );
        $this->assertTrue($config->callSequence);
        $this->assertTrue($config->literalCensus);
    }

    public function testNonBooleanRefusedAtTheDoor(): void
    {
        (new ConfigLoader())->validate(['call_sequence' => true]);
        (new ConfigLoader())->validate(['literal_census' => false]);
        try {
            (new ConfigLoader())->validate(['call_sequence' => 'yes']);
            $this->fail('a string must not launder the call demand on');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('call_sequence must be a boolean', $e->getMessage());
        }
        $this->expectException(\RuntimeException::class);
        (new ConfigLoader())->validate(['literal_census' => 1]);
    }

    public function testCallArmedWithoutIdiomFailsAsConstructionInvariant(): void
    {
        // Ordered capture consumes the fragment-gated statement lists;
        // an idiom-less corpus has no lists, so a call arm could only
        // ever report a quiet zero — the family refuses that shape.
        try {
            new Config(paths: ['/nowhere'], exclude: [], callSequence: true);
            $this->fail('idiom-less call arm must not construct');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('call_sequence', $e->getMessage());
            $this->assertStringContainsString('idiom_mining', $e->getMessage());
        }
        $dir  = $this->tmpDir();
        $file = $dir . '/phpdup.json';
        file_put_contents($file, json_encode([
            'call_sequence' => true,
            'kinds'         => ['function', 'fragment'],
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
            callSequence: true,
            literalCensus: true,
        );
        $this->assertTrue($config->callSequence);
        $this->assertTrue($config->literalCensus);
    }

    public function testRootOnlyRefusedPerDirectory(): void
    {
        foreach (['call_sequence', 'literal_census'] as $knob) {
            $dir = $this->tmpDir();
            mkdir($dir . '/sub', 0o777, true);
            file_put_contents($dir . '/sub/.phpdup.json', json_encode([$knob => true]));
            try {
                (new ConfigLoader())->load([$dir], null);
                $this->fail("$knob must be refused per-directory");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($knob, $e->getMessage());
            }
        }
    }

    public function testDerivedConfigsCopyTheRootStanceNotReplaceIt(): void
    {
        $armed = new Config(
            paths: ['/nowhere'],
            exclude: [],
            allowedKinds: ['method', BlockExtractor::FRAGMENT_KIND],
            idiomMining: true,
            callSequence: true,
            literalCensus: true,
        );
        $derived = $armed->withOverrides(['kinds' => ['method', BlockExtractor::FRAGMENT_KIND]]);
        $this->assertTrue($derived->callSequence, 'root demands ride every derivation');
        $this->assertTrue($derived->literalCensus);
    }
}
