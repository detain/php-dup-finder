<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Cli;

use Phpdup\Cli\Command;
use Phpdup\Cli\Config;
use Phpdup\Cli\ConfigLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Regression net for plan item 0.2.
 *
 * Symfony Console hands every VALUE_REQUIRED option to the command as a
 * raw string; the seven numeric flags exercised here that flow through
 * OverrideResolver into Config's strict-typed constructor
 * (--min-block-size, --similarity, --max-df, --min-impact,
 * --fail-on-impact, --max-clusters, -j/--workers) used to die with a
 * TypeError at exit 255. The coercion boundary (ConfigLoader::NUMERIC_KEYS)
 * has since grown past these seven — the flag-train additions are pinned
 * in their own tests. Coercion now happens at the
 * ConfigLoader/OverrideResolver boundary: valid numeric strings arrive
 * as real int/float, invalid ones surface the conventional friendly
 * exit 2 (same convention as invalid-config-schema input).
 */
final class NumericFlagCoercionTest extends TestCase
{
    private ?string $tmpDir = null;

    protected function tearDown(): void
    {
        if ($this->tmpDir !== null && is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
                if (is_file($f)) {
                    unlink($f);
                }
            }
            @rmdir($this->tmpDir);
        }
        $this->tmpDir = null;
    }

    // ── Boundary matrix: ConfigLoader casts ─────────────────────────────

    /**
     * @return array<string, array{0: string, 1: string, 2: int|float}>
     */
    public static function validNumericStrings(): array
    {
        return [
            'min-block-size'   => ['min_block_size',       '10',    10],
            'similarity'       => ['similarity_threshold', '0.7',   0.7],
            'max-df'           => ['max_df',               '0.05',  0.05],
            'min-impact'       => ['min_cluster_impact',   '5',     5],
            'fail-on-impact'   => ['fail_on_impact',       '100',   100],
            'max-clusters'     => ['max_clusters',         '5',     5],
            'workers-two'      => ['workers',              '2',     2],
            // documented sentinels: 0 = auto / disabled gates
            'workers-zero'     => ['workers',              '0',     0],
            'fail-on-zero'     => ['fail_on_impact',       '0',     0],
            'max-clusters-zero'=> ['max_clusters',         '0',     0],
            'min-impact-zero'  => ['min_cluster_impact',   '0',     0],
            // numeric-string tolerance mirrored from validate()'s domain
            'similarity-sci'   => ['max_df',               '1e-2',  0.01],
            'similarity-bound' => ['similarity_threshold', '1',     1.0],
            'padded-int'       => ['workers',              ' 3 ',   3],
            // m1: ir/ml-pair thresholds now arrive raw; exotic valid forms
            // pinned through the boundary. Trim policy: surrounding
            // whitespace is TRIMMED and accepted (' .85 ' == 0.85).
            'ir-trimmed'       => ['ir_threshold',         ' .85 ', 0.85],
            'ml-integer-str'   => ['ml_pair_threshold',    '1',     1.0],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('validNumericStrings')]
    public function testNumericOverrideStringsAreCoercedToTheirDeclaredType(string $key, string $raw, int|float $expected): void
    {
        $config = (new ConfigLoader())->load([], null, [$key => $raw]);

        $actual = self::fieldFor($config, $key);
        $this->assertSame($expected, $actual, "$key from '$raw'");
        if (is_float($expected)) {
            $this->assertIsFloat($actual, "$key must land as float");
        } else {
            $this->assertIsInt($actual, "$key must land as int");
        }
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function invalidNumericStrings(): array
    {
        return [
            'min-block-size-word'   => ['min_block_size',       'abc',  'min_block_size must be an integer'],
            'min-block-size-decimal'=> ['min_block_size',       '10.5', 'min_block_size must be an integer'],
            'min-block-size-empty'  => ['min_block_size',       '',     'min_block_size must be an integer'],
            'min-block-size-zero'   => ['min_block_size',       '0',    'min_block_size must be >= 1'],
            'similarity-word'       => ['similarity_threshold', 'abc',  'similarity_threshold must be a number'],
            'similarity-high'       => ['similarity_threshold', '1.5',  'similarity_threshold must be in [0, 1]'],
            'similarity-negative'   => ['similarity_threshold', '-0.1', 'similarity_threshold must be in [0, 1]'],
            'max-df-word'           => ['max_df',               'x',    'max_df must be a number'],
            'max-df-high'           => ['max_df',               '2',    'max_df must be in [0, 1]'],
            'min-impact-word'       => ['min_cluster_impact',   'lots', 'min_cluster_impact must be an integer'],
            'workers-negative'      => ['workers',              '-1',   'workers must be >= 0'],
            'workers-word'          => ['workers',              'auto', 'workers must be an integer'],
            'fail-on-impact-word'   => ['fail_on_impact',       'yes',  'fail_on_impact must be an integer'],
            'fail-on-impact-neg'    => ['fail_on_impact',       '-5',   'fail_on_impact must be >= 0'],
            'max-clusters-word'     => ['max_clusters',         'ten',  'max_clusters must be an integer'],
            'max-clusters-neg'      => ['max_clusters',         '-2',   'max_clusters must be >= 0'],
            'ngram-too-small'       => ['ngram_size',           '1',    'ngram_size must be >= 2'],
            'ngram-too-big'         => ['ngram_size',           '99',   'ngram_size must be <= 10'],
            'ir-threshold-word'     => ['ir_threshold',         'hot',  'ir_threshold must be a number'],
            'ir-threshold-high'     => ['ir_threshold',         '1.5',  'ir_threshold must be in [0, 1]'],
            'ml-pair-threshold-word'=> ['ml_pair_threshold',    'hot',  'ml_pair_threshold must be a number'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidNumericStrings')]
    public function testInvalidNumericOverrideStringsThrowWithKeyNamedMessage(string $key, string $raw, string $messageFragment): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($messageFragment);
        (new ConfigLoader())->load([], null, [$key => $raw]);
    }

    public function testNativeTypesFromConfigFilePassThroughUnchanged(): void
    {
        // json-decoded values are already int/float; coercion must be identity.
        $config = (new ConfigLoader())->load(
            [],
            null,
            ['min_block_size' => 7, 'similarity_threshold' => 0.9, 'workers' => 0],
        );
        $this->assertSame(7, $config->minBlockSize);
        $this->assertSame(0.9, $config->similarityThreshold);
        $this->assertSame(0, $config->workers);
    }

    // ── CLI surface: exit-2 convention ──────────────────────────────────

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function cliNumericFlags(): array
    {
        return [
            'min-block-size'    => ['--min-block-size', 'abc',  'min_block_size must be an integer'],
            'similarity'        => ['--similarity',     'abc',  'similarity_threshold must be a number'],
            'max-df'            => ['--max-df',         'huge', 'max_df must be a number'],
            'min-impact'        => ['--min-impact',     'lots', 'min_cluster_impact must be an integer'],
            'fail-on-impact'    => ['--fail-on-impact', 'yes',  'fail_on_impact must be an integer'],
            'max-clusters'      => ['--max-clusters',   'ten',  'max_clusters must be an integer'],
            'workers'           => ['--workers',        'auto', 'workers must be an integer'],
            // m1: these two USED to blind-cast at 250 lines earlier and die
            // silently at 0.0/exit 0 — raw passthrough makes them key-named
            // exit-2 errors like the rest of the family.
            'ir-threshold'      => ['--ir-threshold',      'hot', 'ir_threshold must be a number'],
            'ml-pair-threshold' => ['--ml-pair-threshold', 'hot', 'ml_pair_threshold must be a number'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cliNumericFlags')]
    public function testInvalidNumericFlagExitsTwoWithFriendlyMessage(string $flag, string $junk, string $messageFragment): void
    {
        $tester = $this->commandTester();
        $exit = $tester->execute(
            ['paths' => [$this->corpusDir()], $flag => $junk],
            ['capture_stderr_separately' => true],
        );

        $this->assertSame(2, $exit);
        $combined = $tester->getErrorOutput() . $tester->getDisplay();
        $this->assertStringContainsString('phpdup:', $combined);
        $this->assertStringContainsString($messageFragment, $combined);
        $this->assertStringNotContainsString('Uncaught', $combined);
        $this->assertStringNotContainsString('TypeError', $combined);
    }

    public function testAllNineNumericFlagsValidValuesRunCleanlyAndWorkersZeroSentinelWorks(): void
    {
        $tester = $this->commandTester();
        $exit = $tester->execute([
            'paths'               => [$this->corpusDir()],
            '--min-block-size'    => '10',
            '--similarity'        => '0.7',
            '--max-df'            => '0.05',
            '--min-impact'        => '5',
            '--fail-on-impact'    => '100',
            '--max-clusters'      => '5',
            '--workers'           => '0',
            '--ir-threshold'      => ' .85 ',
            '--ml-pair-threshold' => '1',
            '--limit'             => '3',
            '--stage'             => 'scanning',
            '--plain'             => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString('TypeError', $tester->getDisplay());
    }

    public function testInvalidConfigFileAlsoSurfacesExitTwo(): void
    {
        $configPath = $this->corpusDir() . '/broken.json';
        file_put_contents($configPath, '{"workers": "plenty"}');

        $tester = $this->commandTester();
        $exit = $tester->execute(
            ['paths' => [$this->corpusDir()], '--config' => $configPath],
            ['capture_stderr_separately' => true],
        );

        $this->assertSame(2, $exit);
        $combined = $tester->getErrorOutput() . $tester->getDisplay();
        $this->assertStringContainsString('workers', $combined);
    }

    private function commandTester(): CommandTester
    {
        $app = new Application('phpdup', '0.1.0');
        $app->add(new Command());
        return new CommandTester($app->find('analyze'));
    }

    // ── shell-S10 (shell-038): the workers-ceiling NOTE posture ──────────
    //
    // Lane ruling pinned here: an above-ceiling workers value is admitted
    // unchanged (exit 0, Config->workers untouched) — enforcement is the
    // pool's clamp (WorkerPool::clampWorkers), not a config-schema error.
    // The note is the user-visible counterpart of that silent clamp.
    // PHPDUP_WORKERS pins the ceiling to a deterministic 8 (4 × 2).

    public function testWorkersAbovePoolCeilingIsAdmittedWithCeilingNote(): void
    {
        $original = getenv('PHPDUP_WORKERS');
        putenv('PHPDUP_WORKERS=2');
        try {
            $loader = new ConfigLoader(warnOnSkip: false);
            $config = $loader->load([], null, ['workers' => '100000']);

            $this->assertSame(100000, $config->workers, 'admission unchanged: the clamp is the pool\'s job, not exit 2');
            $this->assertSame([], $loader->skipNotes(), 'a ceiling note is not a discovery skip');
            $this->assertCount(1, $loader->loadNotes());
            $this->assertStringContainsString('workers=100000', $loader->loadNotes()[0]);
            $this->assertStringContainsString('ceiling of 8', $loader->loadNotes()[0]);
            $this->assertStringContainsString('clamps', $loader->loadNotes()[0]);
        } finally {
            if ($original === false) {
                putenv('PHPDUP_WORKERS');
            } else {
                putenv("PHPDUP_WORKERS=$original");
            }
        }
    }

    public function testWorkersAtOrBelowPoolCeilingLoadSilently(): void
    {
        $original = getenv('PHPDUP_WORKERS');
        putenv('PHPDUP_WORKERS=2');
        try {
            // Boundary (== ceiling), one below, the auto sentinel, and a
            // padded string — none of these may produce a note, and none are
            // rewritten by the loader (the clamp is the pool's, not here).
            foreach ([['8', 8], ['7', 7], ['0', 0], [' 6 ', 6]] as [$raw, $expected]) {
                $loader = new ConfigLoader(warnOnSkip: false);
                $config = $loader->load([], null, ['workers' => $raw]);
                $this->assertSame($expected, $config->workers, "workers='$raw' passes through");
                $this->assertSame([], $loader->loadNotes(), "workers='$raw' must stay silent");
            }
        } finally {
            if ($original === false) {
                putenv('PHPDUP_WORKERS');
            } else {
                putenv("PHPDUP_WORKERS=$original");
            }
        }
    }

    public function testConfigFileWorkersAboveCeilingAlsoNoted(): void
    {
        $original = getenv('PHPDUP_WORKERS');
        putenv('PHPDUP_WORKERS=2');
        try {
            $configPath = $this->corpusDir() . '/big-workers.json';
            file_put_contents($configPath, '{"workers": 40}');
            $loader = new ConfigLoader(warnOnSkip: false);
            $config = $loader->load([], $configPath, []);
            $this->assertSame(40, $config->workers, 'config-file layer rides the same admitted-then-clamped posture');
            $this->assertCount(1, $loader->loadNotes());
            $this->assertStringContainsString('workers=40', $loader->loadNotes()[0]);
        } finally {
            if ($original === false) {
                putenv('PHPDUP_WORKERS');
            } else {
                putenv("PHPDUP_WORKERS=$original");
            }
        }
    }

    public function testHugeWorkersFlagRunsCleanlyAndExitsZero(): void
    {
        $original = getenv('PHPDUP_WORKERS');
        putenv('PHPDUP_WORKERS=2');
        try {
            $tester = $this->commandTester();
            $exit = $tester->execute([
                'paths'        => [$this->corpusDir()],
                '--workers'    => '100000',
                '--limit'      => '3',
                '--stage'      => 'scanning',
                '--plain'      => true,
            ]);
            // The documented posture for this lane ruling: an oversized
            // request is NOT invalid invocation (exit 2) and never a crash —
            // the run happens, clamped.
            $this->assertSame(0, $exit);
            $this->assertStringNotContainsString('Uncaught', $tester->getDisplay());
        } finally {
            if ($original === false) {
                putenv('PHPDUP_WORKERS');
            } else {
                putenv("PHPDUP_WORKERS=$original");
            }
        }
    }

    /** Two tiny PHP files, absolute temp path — no repo litter, <8 files so the fork path never spawns. */
    private function corpusDir(): string
    {
        if ($this->tmpDir === null) {
            $dir = sys_get_temp_dir() . '/phpdup-flags-' . getmypid() . '-' . spl_object_id($this);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($dir . '/a.php', "<?php\nfunction a() { \$x = 1; if (\$x) { echo 'hit'; } }\n");
            file_put_contents($dir . '/b.php', "<?php\nfunction b() { \$y = 1; if (\$y) { echo 'hit'; } }\n");
            $this->tmpDir = $dir;
        }
        return $this->tmpDir;
    }

    private static function fieldFor(Config $config, string $key): int|float
    {
        return match ($key) {
            'min_block_size'       => $config->minBlockSize,
            'similarity_threshold' => $config->similarityThreshold,
            'max_df'               => $config->maxDocumentFrequency,
            'min_cluster_impact'   => $config->minClusterImpact,
            'fail_on_impact'       => $config->failOnImpact,
            'max_clusters'         => $config->maxClusters,
            'workers'              => $config->workers,
            'ir_threshold'         => $config->irThreshold,
            'ml_pair_threshold'    => $config->mlPairThreshold,
            default                => throw new \LogicException("unmapped key $key"),
        };
    }
}
