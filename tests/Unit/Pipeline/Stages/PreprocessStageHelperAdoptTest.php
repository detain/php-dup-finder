<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Pipeline\Stages;

use Phpdup\Cli\Config;
use Phpdup\Census\HelperAdoption;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Item 3.2 (MINE-D-2) — the consumer wiring on PreprocessStage: the
 * armed run derives the HelperAdoption ledger over the census 3.1
 * mined (ONE substrate, no second walker, no new wire flavor), the
 * unarmed run stays byte-identical to history, and the knob provably
 * does NOT join the IndexStore config key — it changes no mined row and
 * persists nothing, so warm-reuse posture is a derivation detail, not a
 * cache identity (the documented, pinned departure from idiom_mining).
 */
final class PreprocessStageHelperAdoptTest extends TestCase
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

    /**
     * Corpus: H::flag holds the idiom once; three files re-implement it
     * inline (count 4, of which def 1 → 3 copies); a 7-statement method
     * repeats it twice — its occurrences are copies too, but the big
     * body is never itself a helper candidate.
     */
    private function corpus(): string
    {
        $dir = sys_get_temp_dir() . '/phpdup-adoptwt-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        $this->tmpDirs[] = $dir;

        file_put_contents($dir . '/helper.php', implode("\n", [
            '<?php',
            'class H',
            '{',
            '    public function flag(): bool',
            '    {',
            "        return getenv('X') === 'y';",
            '    }',
            '',
            '    public function big(): int',
            '    {',
            '        $a = 1;',
            '        $b = 2;',
            "        if (getenv('X') === 'y') {",
            '            $a = 3;',
            '        }',
            '        $c = 4;',
            '        $d = 5;',
            "        if (getenv('X') === 'y') {",
            '            $d = 6;',
            '        }',
            '        return $a + $b + $c + $d;',
            '    }',
            '}',
            '',
        ]));
        foreach ([1, 2, 3] as $i) {
            file_put_contents($dir . '/c' . $i . '.php', implode("\n", [
                '<?php',
                'function check' . $i . '(): string',
                '{',
                '    $z = 1;',
                "    \$w = getenv('X') === 'y' ? 'a' : 'b';",
                '    return $w;',
                '}',
                '',
            ]));
        }
        return $dir;
    }

    /** @return array{0: PipelineState, 1: Config} */
    private function runStages(string $dir, bool $idiom, bool $adopt): PipelineState
    {
        $config = new Config(
            paths: [$dir],
            exclude: Config::defaults([])->exclude,
            workers: 1,
            lazyAst: false,
            allowedKinds: [
                'function', 'method', 'closure', 'arrow', 'if', 'for', 'foreach', 'while', 'do',
                'try', 'switch', 'match', BlockExtractor::FRAGMENT_KIND,
            ],
            idiomMining: $idiom,
            helperAdoption: $adopt,
            cacheDir: $dir . '/.phpdup-cache',
        );
        $state = new PipelineState($config);
        (new ScanningStage())->run($state, new NullOutput());
        (new PreprocessStage(useCache: false))->run($state, new NullOutput());
        self::assertSame(0, $state->parseErrors, 'synthetic corpus must parse cleanly (string-quoting drift guard)');
        return $state;
    }

    public function testArmedRunDerivesTheAdoptionLedger(): void
    {
        $state  = $this->runStages($this->corpus(), idiom: true, adopt: true);
        $index  = $state->sequenceIndex;
        self::assertNotNull($index);
        self::assertTrue($index->idiomsArmed());
        self::assertTrue($index->helpersArmed());

        $adoption = $index->helperAdoption();
        self::assertNotNull($adoption, 'the armed run must finish with a built ledger');

        $flagRows = array_values(array_filter(
            $adoption->rows(),
            static fn($r) => $r->helperLabel === 'H::flag',
        ));
        self::assertCount(1, $flagRows, 'the idiom group the helper body owns reports once');
        $row = $flagRows[0];
        self::assertSame('method', $row->helperKind);
        self::assertSame(1, $row->defStatements);
        self::assertSame(6, $row->sitesTotal);
        self::assertSame(1, $row->defSites, 'the helper owns exactly one in-body occurrence');
        self::assertSame(5, $row->inlineSites);
        $byBase = [];
        foreach ($row->inlineFiles as $f => $c) {
            $byBase[basename($f)] = $c;
        }
        self::assertSame(
            ['c1.php' => 1, 'c2.php' => 1, 'c3.php' => 1, 'helper.php' => 2],
            $byBase,
            'the three ternary copies (abstract-wildcard match through the ternary shell) '
            . 'and the two same-file copies inside the 7-statement big() body',
        );
        foreach ($row->copyLocations as $loc) {
            self::assertStringEndsNotWith(':6', $loc, 'flag own line never listed as a copy');
        }
        self::assertCount(5, $row->copyLocations);

        $labels = array_unique(array_map(static fn($r) => $r->helperLabel, $adoption->rows()));
        self::assertContains('check1', $labels, 'the 3-statement c-files are helper candidates too (twin posture)');
        self::assertNotContains('H::big', $labels, 'the 7-statement body exceeds the helper cap');
    }

    public function testDefaultOffKeepsHistoryByteIdentical(): void
    {
        $state = $this->runStages($this->corpus(), idiom: false, adopt: false);
        $index = $state->sequenceIndex;
        // The 2.1 stmtseq demand (fragment kind in the roster) still builds
        // its index — that is history, not 3.x surface. What must be dead:
        // the census, the adoption flavor, and the ledger.
        self::assertNotNull($index, 'stmtseq keeps its index (2.1 posture untouched)');
        self::assertFalse($index->idiomsArmed(), 'no idiom census without idiom_mining');
        self::assertFalse($index->helpersArmed(), 'no adoption flavor without helper_adoption');
        self::assertNull($index->helperAdoption(), 'no ledger while unarmed');
    }

    public function testIdiomOnAdoptionOffArmsCensusOnly(): void
    {
        $state = $this->runStages($this->corpus(), idiom: true, adopt: false);
        $index = $state->sequenceIndex;
        self::assertNotNull($index);
        self::assertTrue($index->idiomsArmed());
        self::assertFalse($index->helpersArmed());
        self::assertNull($index->helperAdoption());
    }

    public function testAdoptionFlipLeavesTheIndexStoreKeyInvariant(): void
    {
        $key = static function (bool $adopt): string {
            $config = new Config(
                paths: ['/nowhere'],
                exclude: [],
                allowedKinds: ['method', BlockExtractor::FRAGMENT_KIND],
                idiomMining: true,
                helperAdoption: $adopt,
            );
            $stage = new PreprocessStage(useCache: false);
            $m     = new \ReflectionMethod(PreprocessStage::class, 'indexStoreConfigKey');
            $m->setAccessible(true);
            return $m->invoke($stage, $config);
        };
        self::assertSame($key(false), $key(true), 'adoption persists nothing: the cache identity must not move with it');

        $idiomKey = static function (bool $idiom): string {
            $config = new Config(
                paths: ['/nowhere'],
                exclude: [],
                allowedKinds: ['method', BlockExtractor::FRAGMENT_KIND],
                idiomMining: $idiom,
            );
            $stage = new PreprocessStage(useCache: false);
            $m     = new \ReflectionMethod(PreprocessStage::class, 'indexStoreConfigKey');
            $m->setAccessible(true);
            return $m->invoke($stage, $config);
        };
        self::assertNotSame($idiomKey(false), $idiomKey(true), 'the contrast belt: idiom DOES bind (MINE-D posture unchanged)');
    }

    public function testHelperCapConstantMatchesTheLetter(): void
    {
        self::assertSame(5, HelperAdoption::HELPER_MAX_STATEMENTS);
    }
}
