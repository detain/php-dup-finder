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
 *
 * shell-S24 (php-dup-finder-shell-013/014) adds the four-flag runtime
 * family: `--optional-blocks-containment` joins NUMERIC_KEYS as a real
 * config key, and `--limit`/`--min-safety`/`--max-memory` — render-only
 * options that never enter Config — are coerced through the same private
 * helpers via ConfigLoader::coerceRuntimeInt/Float. Blind casts are gone:
 * `(int)'abc'`/`(float)'hot'` no longer launder garbage into sentinels
 * behind a full exit-0 run.
 *
 * once-S49 (php-dup-finder-once-054) closes the `--limit` story: the
 * negative arm of the admitted domain exits 2 like the rest, and the
 * render-side semantics are pinned as measured truth — `0` prints NO
 * detail cards (it is NOT the family's show-all sentinel), `1` prints
 * exactly one card while file sinks stay unlimited, and a large `N`
 * prints every card (the documented show-all recipe).
 */
final class NumericFlagCoercionTest extends TestCase
{
    private ?string $tmpDir = null;
    private ?string $s49Dir = null;

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
        if ($this->s49Dir !== null && is_dir($this->s49Dir)) {
            foreach (glob($this->s49Dir . '/*') ?: [] as $f) {
                if (is_file($f)) {
                    unlink($f);
                }
            }
            @rmdir($this->s49Dir);
        }
        $this->s49Dir = null;
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
            // shell-S24: the type-3 containment knob joins the boundary.
            'obc-valid'        => ['optional_blocks_containment', '0.9', 0.9],
            'obc-zero-bound'   => ['optional_blocks_containment', '0',   0.0],
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
            // shell-S24: containment now rides the boundary — the blind
            // (float) cast that laundered 'hot' into 0.0 is gone.
            'obc-word'              => ['optional_blocks_containment', 'hot',  'optional_blocks_containment must be a number'],
            'obc-high'              => ['optional_blocks_containment', '1.5',  'optional_blocks_containment must be in [0, 1]'],
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
            // shell-S24 (shell-013/014): the runtime family used to
            // blind-cast or print-and-continue; every one now lands as a
            // key-named exit 2 before the pipeline exists.
            'limit'             => ['--limit',              'abc',     'limit must be an integer'],
            'min-safety-word'   => ['--min-safety',         'hot',     'min_safety must be a number'],
            'min-safety-high'   => ['--min-safety',         '2.0',     'min_safety must be in [0, 1]'],
            'max-memory'        => ['--max-memory',         'banana',  'max_memory must be an integer'],
            'obc-word'          => ['--optional-blocks-containment', 'hot', 'optional_blocks_containment must be a number'],
            // explicitly pinned unchanged (shell-S24 acceptance): workers
            // already exited 2 here before this step; the recipe holds.
            'workers-banana'    => ['--workers',            'banana',  'workers must be an integer'],
            // once-S49 (php-dup-finder-once-054): the whole admitted domain
            // is "integer >= 0" — the negative arm closes the fence the same
            // way 'abc' does (was laundered to the 0-sentinel pre-S24).
            'limit-negative'    => ['--limit',              '-1',      'limit must be >= 0'],
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

    // ── shell-S24 (php-dup-finder-shell-013/014): runtime admission ──────

    public function testCombinedRuntimeGarbageExitsTwoWithoutRunningPipeline(): void
    {
        // Acceptance probe: `--limit=abc --min-safety=hot
        // --max-memory=banana` → exit 2 (was: three blind casts to the
        // 0-sentinels, full run, exit 0). Fail-fast renders the first
        // rejection; the load-bearing fact is the refusal — the pipeline
        // never starts, so the report sink is never written.
        $jsonPath = $this->corpusDir() . '/never-runs.json';
        @unlink($jsonPath);
        $tester = $this->commandTester();
        $exit = $tester->execute(
            [
                'paths'        => [$this->corpusDir()],
                '--limit'      => 'abc',
                '--min-safety' => 'hot',
                '--max-memory' => 'banana',
                '--json'       => $jsonPath,
            ],
            ['capture_stderr_separately' => true],
        );

        $this->assertSame(2, $exit);
        $combined = $tester->getErrorOutput() . $tester->getDisplay();
        $this->assertStringContainsString('phpdup:', $combined);
        $this->assertStringContainsString('limit must be an integer', $combined);
        $this->assertFileDoesNotExist($jsonPath, 'admission refused the run — the report file must never appear');
    }

    public function testFourRuntimeNumericFlagsValidValuesRunCleanly(): void
    {
        // The fence must not bite its documented domains: limit >= 0,
        // min_safety in [0,1], max_memory >= 0, containment in [0,1].
        $tester = $this->commandTester();
        $exit = $tester->execute([
            'paths'                         => [$this->corpusDir()],
            '--limit'                       => '3',
            '--min-safety'                  => '0.5',
            '--max-memory'                  => '512',
            '--optional-blocks-containment' => '0.9',
            '--plain'                       => true,
        ]);

        $this->assertSame(0, $exit, $tester->getDisplay());
        $this->assertStringNotContainsString('TypeError', $tester->getDisplay());
        $this->assertStringNotContainsString('must be', $tester->getDisplay());
    }

    // ── once-S49 (php-dup-finder-once-054): --limit render semantics ──────
    //
    // The finding's live half (`--limit abc` laundering into the 0-sentinel
    // behind exit 0) was cured by shell-S24's admission fence, pinned above
    // (now incl. the negative arm). What stayed undocumented was what `0`
    // MEANS: measured on this checkout (and with min() in CliReporter since
    // the initial release), `--limit 0` prints NO detail cards — it is not
    // the show-all sentinel the `--candidate-budget`/`max_clusters` family
    // idiom suggests. docs/CLI.md now states that truth; these legs pin it
    // so a future flip to family semantics must land as a deliberate,
    // test-visible change. The show-all recipe is a large N, pinned too.

    public function testLimitZeroPrintsNoClusterCardsButKeepsSummaryTruth(): void
    {
        // Sanity: the corpus must actually cluster before any absence claim
        // means anything (a dead corpus would pin a vacuous zero).
        $baseline = $this->commandTester();
        $this->assertSame(0, $baseline->execute([
            'paths'  => [$this->s49CorpusDir()],
            '--plain' => true,
        ]));
        $this->assertSame(3, substr_count($baseline->getDisplay(), 'Cluster #'),
            'the pinned corpus must carry exactly 3 cluster cards by default');

        $tester = $this->commandTester();
        $this->assertSame(0, $tester->execute([
            'paths'   => [$this->s49CorpusDir()],
            '--limit' => '0',
            '--plain' => true,
        ]));
        $display = $tester->getDisplay();

        $this->assertStringContainsString('showing top 0', $display);
        $this->assertSame(0, substr_count($display, 'Cluster #'),
            '0 is not unlimited: no detail cards are printed');
        // The totals banner and the trailer still describe the FULL set —
        // that half of the output is limit-blind and stays honest.
        $this->assertStringContainsString('3 clusters', $display);
        $this->assertStringContainsString('summary', $display);
    }

    public function testLimitOnePrintsExactlyOneClusterCardWhileFileSinksStayUnlimited(): void
    {
        $jsonPath = $this->s49CorpusDir() . '/s49.json';
        @unlink($jsonPath);

        $tester = $this->commandTester();
        $this->assertSame(0, $tester->execute([
            'paths'   => [$this->s49CorpusDir()],
            '--limit' => '1',
            '--json'  => $jsonPath,
            '--plain' => true,
        ]));
        $display = $tester->getDisplay();

        // CLI: exactly one card — #1 present, #2/#3 not.
        $this->assertStringContainsString('showing top 1', $display);
        $this->assertSame(1, substr_count($display, 'Cluster #'));
        $this->assertStringContainsString('Cluster #1', $display);
        $this->assertStringNotContainsString('Cluster #2', $display);
        $this->assertStringNotContainsString('Cluster #3', $display);

        // JSON sink: --limit is render-only presentation; every cluster is
        // written regardless (docs/CLI.md "Doesn't affect file outputs").
        $this->assertFileExists($jsonPath);
        $decoded = json_decode((string)file_get_contents($jsonPath), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('clusters', $decoded);
        $this->assertCount(3, $decoded['clusters'],
            'the terminal cap must not reach the file sink');
        @unlink($jsonPath);
    }

    public function testLargeLimitPrintsEveryClusterCard(): void
    {
        // docs/CLI.md tells users to "pass a large N" to see everything —
        // pin that the cap saturates at the cluster count, not at overflow.
        $tester = $this->commandTester();
        $this->assertSame(0, $tester->execute([
            'paths'   => [$this->s49CorpusDir()],
            '--limit' => '999999',
            '--plain' => true,
        ]));
        $display = $tester->getDisplay();

        $this->assertStringContainsString('showing top 3', $display);
        $this->assertSame(3, substr_count($display, 'Cluster #'));
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

    /**
     * shell-S10 fix (review L-1): this leg runs bin/phpdup as a real
     * subprocess with STDERR redirected to a file. CommandTester cannot
     * capture ConfigLoader's raw fwrite(STDERR), so the previous in-process
     * form leaked the note line into the Unit suite's output stream. The
     * subprocess shape is hermetic AND pins the note end-to-end: exit 0,
     * no crash, and the warning text on the real error stream.
     */
    public function testHugeWorkersFlagRunsCleanlyAndExitsZero(): void
    {
        $original = getenv('PHPDUP_WORKERS');
        putenv('PHPDUP_WORKERS=2');
        $stderrPath = $this->corpusDir() . '/huge-workers.err';
        try {
            @unlink($stderrPath);
            $binary = dirname(__DIR__, 3) . '/bin/phpdup';
            $this->assertFileExists($binary);
            $exit      = -1;
            $stdout    = [];
            exec(sprintf(
                '%s %s analyze %s --workers=100000 --limit=3 --stage=scanning --plain 2>%s',
                PHP_BINARY,
                escapeshellarg($binary),
                escapeshellarg($this->corpusDir()),
                escapeshellarg($stderrPath),
            ) . ' < /dev/null', $stdout, $exit);
            // The documented posture for this lane ruling: an oversized
            // request is NOT invalid invocation (exit 2) and never a crash —
            // the run happens, clamped.
            $this->assertSame(0, $exit);
            $display = implode("\n", $stdout);
            $this->assertStringNotContainsString('Uncaught', $display);
            // The clamp note must land on the subprocess' STDERR — the same
            // text the runtime echo produces, now asserted instead of leaked.
            $note = is_file($stderrPath) ? (string)file_get_contents($stderrPath) : '';
            $this->assertStringContainsString('phpdup: warning:', $note);
            $this->assertStringContainsString('workers=100000', $note);
            $this->assertStringContainsString(
                'ceiling of 8',
                $note,
                'the note is the user-visible counterpart of the pool clamp'
            );
        } finally {
            @unlink($stderrPath);
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

    /**
     * once-S49: eight tiny files forming four clone pairs, three of which
     * cluster on this checkout's extractor (measured: 3 cards / 3 clusters
     * in a default run — the baseline sanity legs above re-prove it, so a
     * drift that silences the corpus fails loudly instead of pinning
     * absence vacuously). 8 files — at WorkerPool's count<8 serial boundary,
     * so a fork (if any) stays trivially small.
     */
    private function s49CorpusDir(): string
    {
        if ($this->s49Dir === null) {
            $dir = sys_get_temp_dir() . '/phpdup-s49-' . getmypid() . '-' . spl_object_id($this);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($dir . '/w1.php', "<?php\nfunction sendMail(\$to, \$body) { \$m = newMailer(); \$m->to(\$to); \$m->subject('hi'); \$m->body(\$body); \$m->send(); }\n");
            file_put_contents($dir . '/w2.php', "<?php\nfunction sendNote(\$to, \$body) { \$m = newMailer(); \$m->to(\$to); \$m->subject('yo'); \$m->body(\$body); \$m->send(); }\n");
            file_put_contents($dir . '/x1.php', "<?php\nfunction fetchUser(\$id) { \$q = 'SELECT * FROM users WHERE id = ?'; \$s = prep(\$q); \$s->bind(\$id); return \$s->execute(); }\n");
            file_put_contents($dir . '/x2.php', "<?php\nfunction fetchOrder(\$id) { \$q = 'SELECT * FROM orders WHERE id = ?'; \$s = prep(\$q); \$s->bind(\$id); return \$s->execute(); }\n");
            file_put_contents($dir . '/y1.php', "<?php\nfunction parseA(\$txt) { \$r = preg_split('/,/', \$txt); \$o = []; foreach (\$r as \$v) { \$o[] = trim(\$v); } return \$o; }\n");
            file_put_contents($dir . '/y2.php', "<?php\nfunction parseB(\$txt) { \$r = preg_split('/;/', \$txt); \$o = []; foreach (\$r as \$v) { \$o[] = ltrim(\$v); } return \$o; }\n");
            file_put_contents($dir . '/z1.php', "<?php\nfunction sumA(\$xs) { \$t = 0.0; foreach (\$xs as \$x) { \$t += (float)\$x; } return \$t; }\n");
            file_put_contents($dir . '/z2.php', "<?php\nfunction sumB(\$xs) { \$t = 0.0; foreach (\$xs as \$x) { \$t += (float)\$x; } return round(\$t, 2); }\n");
            $this->s49Dir = $dir;
        }
        return $this->s49Dir;
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
            'optional_blocks_containment' => $config->optionalBlocksContainment,
            default                => throw new \LogicException("unmapped key $key"),
        };
    }
}
