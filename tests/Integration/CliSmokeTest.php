<?php
declare(strict_types=1);

namespace Phpdup\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Phpdup\Parallel\WorkerPool;
use Phpdup\Reporting\JsonSchemaSpec;

/**
 * Item 0.4 — end-to-end CI smoke: drives the REAL `bin/phpdup` binary as a
 * child process (the exact surface CI and users hit), instead of wiring
 * stages in-process.
 *
 * Harness laws (improve_logic_info.md §4/§8, P0b/P0d closeouts):
 *  - stdin is closed (a test that reads stdin must never hang the run);
 *  - every run gets a CONFIG FILE carrying an absolute throwaway
 *    `cache_dir` — `--no-cache` is untrustworthy (it never suppressed the
 *    on-disk AST cache) and `--cache-dir` is not a flag at all;
 *  - analyze targets are always explicit directories/files under the temp
 *    root or absolute repo paths — never bare '/tmp' (a per-directory
 *    override discovery crawl would scan the whole temp tree);
 *  - the repo must survive every run index-pristine: git porcelain for the
 *    code paths is captured before the run and asserted unchanged;
 *  - output is read with a wall deadline and the process is terminated on
 *    timeout, so a hung child fails this test instead of hanging phpunit.
 *
 * Exit-code contract pinned here is the DOCUMENTED one that disk honours
 * (docs/CLI.md "Exit codes"): 0 successful — findings included, `analyze`
 * does NOT exit 1 when clusters are found; 1 Symfony usage errors (unknown
 * option) and internal errors; 2 invalid numeric/enum input; 3 CI gate
 * trips. Unknown-option → 1 is Symfony's path, pinned as-is: the docs'
 * `2` row deliberately does not claim unknown CLI options.
 */
final class CliSmokeTest extends TestCase
{
    /** Wall-clock deadline per child process. */
    private const TIMEOUT_S = 120.0;

    /** Repo paths whose porcelain must not move during any run. */
    private const PRISTINE_PATHS = ['src', 'tests', 'docs', 'bin', '.github'];

    private string $tmp;

    public static function setUpBeforeClass(): void
    {
        if (!is_file(self::repoRoot() . '/bin/phpdup')) {
            throw new \RuntimeException('cli smoke harness cannot find bin/phpdup relative to tests/');
        }
    }

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/phpdup_smoke_' . uniqid('t', true);
        mkdir($this->tmp . '/cache', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
    }

    // ---------------------------------------------------------------- flags

    /**
     * Every numeric option phpdup itself validates must accept its
     * documented domain through the real CLI (P0b coercion + flag-train):
     * a valid value exits 0 with a complete report, never a TypeError, a
     * fatal, or a silent misread.
     *
     * @return array<string, array{0: string}>
     */
    public static function validFlagProvider(): array
    {
        return [
            'min-block-size-lo'  => ['--min-block-size=1'],
            'min-block-size-hi'  => ['--min-block-size=50'],
            'min-impact-zero'    => ['--min-impact=0'],
            'min-impact-five'    => ['--min-impact=5'],
            'similarity-mid'     => ['--similarity=0.5'],
            'similarity-one'     => ['--similarity=1'],
            'max-df-mid'         => ['--max-df=0.5'],
            'candidate-budget-0' => ['--candidate-budget=0'],
            'candidate-budget-n' => ['--candidate-budget=1000'],
            'ir-threshold-mid'   => ['--ir-threshold=0.5'],
            'ml-threshold-mid'   => ['--ml-pair-threshold=0.5'],
            'limit-three'        => ['--limit=3'],
            'workers-auto'       => ['--workers=0'],
            'workers-serial'     => ['--workers=1'],
        ];
    }

    /** @dataProvider validFlagProvider */
    public function testValidNumericFlagExitsClean(string $flag): void
    {
        $corpus = $this->makeDupCorpus(3);
        $run = $this->cli(['analyze', $corpus, '--json', $this->tmp . '/r.json', $flag]);

        $this->assertSame(0, $run['exit'], "flag {$flag}:\n" . $run['combined']);
        $this->assertStringNotContainsString('Fatal error', $run['combined']);
        $this->assertStringNotContainsString('TypeError', $run['combined']);
        $this->assertStringContainsString('json report', $run['combined']);
        $this->assertSame(
            json_decode((string) file_get_contents($this->tmp . '/r.json'), true)['summary']['files_scanned'],
            3,
            "flag {$flag} must not distort the file accounting",
        );
    }

    /**
     * Invalid values must exit 2 with the friendly `phpdup:` message
     * naming the rejected config key (never a raw Symfony/PHP traceback),
     * per docs/CLI.md. Coercion + exit-2 only — scorer DEFAULT semantics
     * belong to the CLI lane (P5A), so `--scorer` appears here solely as
     * an invalid-value case.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidFlagProvider(): array
    {
        return [
            'min-block-size-zero'   => ['--min-block-size=0', 'min_block_size'],
            'min-block-size-text'   => ['--min-block-size=abc', 'min_block_size'],
            'min-impact-text'       => ['--min-impact=abc', 'min_cluster_impact'],
            'min-impact-float'      => ['--min-impact=8.5', 'min_cluster_impact'],
            'similarity-high'       => ['--similarity=1.5', 'similarity_threshold'],
            'similarity-text'       => ['--similarity=abc', 'similarity_threshold'],
            'max-df-high'           => ['--max-df=2', 'max_df'],
            'max-df-text'           => ['--max-df=abc', 'max_df'],
            'workers-text'          => ['--workers=abc', 'workers'],
            'workers-negative'      => ['--workers=-1', 'workers'],
            'candidate-budget-text' => ['--candidate-budget=abc', 'candidate_budget'],
            // ft-n1: a digit string beyond the 64-bit range must fail loud
            // (exit 2), never saturate to PHP_INT_MAX and read as unlimited.
            'candidate-budget-huge' => ['--candidate-budget=99999999999999999999', 'candidate_budget'],
            'fail-on-impact-text'   => ['--fail-on-impact=abc', 'fail_on_impact'],
            'max-clusters-text'     => ['--max-clusters=abc', 'max_clusters'],
            'ir-threshold-high'     => ['--ir-threshold=2', 'ir_threshold'],
            'scorer-garbage'        => ['--scorer=nope', '--scorer'],
        ];
    }

    /** @dataProvider invalidFlagProvider */
    public function testInvalidNumericFlagExits2WithFriendlyMessage(string $flag, string $expectedKey): void
    {
        $corpus = $this->makeDupCorpus(3);
        $run = $this->cli(['analyze', $corpus, $flag]);

        $this->assertSame(2, $run['exit'], "flag {$flag}:\n" . $run['combined']);
        $this->assertStringContainsString('phpdup:', $run['combined']);
        $this->assertStringContainsString($expectedKey, $run['combined']);
        $this->assertStringNotContainsString('Fatal error', $run['combined']);
    }

    public function testUnknownOptionIsUsageErrorExit1(): void
    {
        // Pinned as-is: unknown options never reach phpdup's boundary
        // validation; Symfony renders the boxed usage error with its own
        // exit 1 (docs' `2` row deliberately does not cover CLI options).
        // If a future wave routes these to 2, this test must flip WITH
        // docs/CLI.md — not silently.
        $corpus = $this->makeDupCorpus(1);
        $run = $this->cli(['analyze', $corpus, '--definitely-not-a-flag']);

        $this->assertSame(1, $run['exit']);
        $this->assertStringContainsString('does not exist', $run['combined']);
    }

    public function testWorkersZeroSentinelAutoRunsToForkZone(): void
    {
        // `--workers 0` is the documented AUTO sentinel: 12 files must take
        // the fork path (WorkerPool streams serially only below 8 items or
        // with exactly 1 worker), proving 0 is accepted AND dispatches.
        $corpus = $this->makeDupCorpus(12);
        $run = $this->cli(['analyze', $corpus, '--workers=0', '--stats']);

        $this->assertSame(0, $run['exit'], $run['combined']);
        if (WorkerPool::isAvailable()) {
            // Auto sentinel must dispatch and print the honest pcntl state;
            // the exact count is cpuinfo-dependent so only its presence is
            // pinned (must be >1 on any shared CI runner).
            $this->assertMatchesRegularExpression('/workers: \d+ \(pcntl available\)/', $run['combined']);
        } else {
            $this->assertStringContainsString('pcntl unavailable', $run['combined']);
        }
    }

    // ------------------------------------------------------- exit contract

    public function testFindingsExitZeroNotError(): void
    {
        // The old CI smoke trusted exit codes only in one direction; pin
        // the documented contract both ways: clusters found ≠ failure.
        $corpus = $this->makeDupCorpus(4);
        $run = $this->cli(['analyze', $corpus, '--min-impact', '0', '--json', $this->tmp . '/r.json']);

        $this->assertSame(0, $run['exit']);
        $decoded = json_decode((string) file_get_contents($this->tmp . '/r.json'), true);
        $this->assertGreaterThan(0, $decoded['summary']['clusters'], 'corpus must actually produce findings');
    }

    public function testCleanCorpusExitsZero(): void
    {
        $corpus = $this->makeCleanCorpus();
        $run = $this->cli(['analyze', $corpus, '--json', $this->tmp . '/r.json']);

        $this->assertSame(0, $run['exit'], $run['combined']);
        $decoded = json_decode((string) file_get_contents($this->tmp . '/r.json'), true);
        $this->assertSame(0, $decoded['summary']['clusters']);
        $this->assertStringContainsString('clean.', $run['combined'], 'an genuinely clean corpus still gets the bare clean verdict');
    }

    public function testFailOnImpactGateTripsExit3(): void
    {
        $corpus = $this->makeDupCorpus(4);
        $run = $this->cli(['analyze', $corpus, '--fail-on-impact=1']);

        $this->assertSame(3, $run['exit'], $run['combined']);
    }

    public function testMaxClustersGateTripsExit3(): void
    {
        // 0 is the documented UNLIMITED sentinel (verified: a single
        // cluster passes --max-clusters=0 with exit 0), so the trip test
        // needs a genuinely over-limit corpus: two independent clone
        // families against a budget of one.
        $corpus = $this->makeTwoClusterCorpus();
        $check = $this->cli(['analyze', $corpus, '--max-clusters=0']);
        $this->assertSame(0, $check['exit'], 'sentinel 0 must not trip: ' . $check['combined']);

        $run = $this->cli(['analyze', $corpus, '--max-clusters=1']);
        $this->assertSame(3, $run['exit'], $run['combined']);
    }

    // ------------------------------------------------- accounting surfaces

    public function testUncomparableFilesCannotHideBehindCleanVerdict(): void
    {
        // 0.6's headline: a corpus that scanned files but compared none must
        // say so, in both the verdict and the per-bucket accounting line.
        $corpus = $this->makeZeroBlockCorpus();
        $run = $this->cli(['analyze', $corpus, '--min-block-size=1', '--json', $this->tmp . '/r.json']);

        $this->assertSame(0, $run['exit'], $run['combined']);
        $this->assertStringNotContainsString('— clean.', $run['combined']);
        $this->assertStringContainsString('nothing comparable', $run['combined']);
        $this->assertStringContainsString('3 scanned · 0 reused · 0 processed · 3 no blocks · 0 parse errors', $run['combined']);

        $decoded = json_decode((string) file_get_contents($this->tmp . '/r.json'), true);
        $this->assertSame(3, $decoded['summary']['files_without_blocks']);
        $this->assertSame(0, $decoded['summary']['clusters']);
        $this->assertSame(
            $decoded['summary']['files_scanned'],
            $decoded['summary']['files_reused'] + $decoded['summary']['files_processed']
                + $decoded['summary']['files_without_blocks'] + $decoded['summary']['parse_errors'],
        );
    }

    public function testJsonReportCarriesSchemaOneTwoAccountingKeys(): void
    {
        $corpus = $this->makeDupCorpus(2);
        $run = $this->cli(['analyze', $corpus, '--json', $this->tmp . '/r.json']);
        $this->assertSame(0, $run['exit'], $run['combined']);

        $decoded = json_decode((string) file_get_contents($this->tmp . '/r.json'), true);
        $this->assertSame('1.2', $decoded['schema_version']);
        $this->assertSame(JsonSchemaSpec::SCHEMA_VERSION, $decoded['schema_version']);
        $this->assertSame(array_keys(JsonSchemaSpec::summaryShape()), array_keys($decoded['summary']));
    }

    public function testWarmCacheRunReusesAndKeepsBalance(): void
    {
        $corpus = $this->makeDupCorpus(3);
        $first = $this->cli(['analyze', $corpus, '--json', $this->tmp . '/r1.json']);
        $second = $this->cli(['analyze', $corpus, '--json', $this->tmp . '/r2.json']);

        $this->assertSame(0, $first['exit'], $first['combined']);
        $this->assertSame(0, $second['exit'], $second['combined']);
        $s1 = json_decode((string) file_get_contents($this->tmp . '/r1.json'), true)['summary'];
        $s2 = json_decode((string) file_get_contents($this->tmp . '/r2.json'), true)['summary'];

        $this->assertSame(0, $s1['files_reused'], 'cold cache: nothing to reuse');
        $this->assertSame(3, $s2['files_reused'], 'warm cache: every block-bearing file comes from disk');
        $this->assertSame(0, $s2['files_processed']);
        $this->assertSame(
            $s2['files_scanned'],
            $s2['files_reused'] + $s2['files_processed'] + $s2['files_without_blocks'] + $s2['parse_errors'],
        );
    }

    // ------------------------------------------------ mode + corpus parity

    public function testForkAndSerialProduceIdenticalFindings(): void
    {
        // The crash#1 zone: >8 files dispatch to forked workers, which used
        // to corrupt the shared AST cache and lose whole files. Fork mode
        // must find EXACTLY what serial mode finds — same accounting, same
        // clusters, member-for-member.
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl unavailable: fork zone cannot be exercised on this box');
        }
        $corpus = $this->makeDupCorpus(12);

        $serial = $this->cli(
            ['analyze', $corpus, '--workers=1', '--json', $this->tmp . '/serial.json'],
            cacheDir: $this->tmp . '/cache-serial',
        );
        $forked = $this->cli(
            ['analyze', $corpus, '--workers=2', '--json', $this->tmp . '/forked.json', '--stats'],
            cacheDir: $this->tmp . '/cache-fork',
        );

        $this->assertSame(0, $serial['exit'], $serial['combined']);
        $this->assertSame(0, $forked['exit'], $forked['combined']);
        $this->assertStringContainsString('workers: 2 (pcntl available)', $forked['combined'], 'the fork path must actually run');

        $js = json_decode((string) file_get_contents($this->tmp . '/serial.json'), true);
        $jf = json_decode((string) file_get_contents($this->tmp . '/forked.json'), true);

        $this->assertSame($js['summary'], $jf['summary'], 'fork vs serial summaries must be identical (both cold caches)');
        $this->assertSame(12, $js['summary']['files_scanned']);
        $this->assertGreaterThan(0, $js['summary']['clusters']);
        $this->assertSame(
            $this->normalizeClusters($js['clusters']),
            $this->normalizeClusters($jf['clusters']),
            'fork vs serial must agree on every cluster and every member',
        );
    }

    // ------------------------------------------------------ repo corpora

    public function testFixturesCorpusMatchesCommittedContract(): void
    {
        // Strict mirror of the CI smoke step (see .github/workflows/ci.yml).
        // Truth at writing: 20 files, 15 clusters, 260 duplicated lines,
        // zero parse errors — the planted clones in tests/Fixtures are a
        // contract, so these numbers may only move with the fixtures.
        $fixtures = realpath(__DIR__ . '/../Fixtures');
        $this->assertIsString($fixtures);

        $run = $this->cli([
            'analyze', $fixtures,
            '--min-impact', '5', '--limit', '3',
            '--json', $this->tmp . '/r.json',
        ]);
        $this->assertSame(0, $run['exit'], $run['combined']);

        $decoded = json_decode((string) file_get_contents($this->tmp . '/r.json'), true);
        $s = $decoded['summary'];
        $this->assertSame('1.2', $decoded['schema_version']);
        $this->assertSame(20, $s['files_scanned']);
        $this->assertSame(0, $s['parse_errors'], 'the fixture corpus must parse cleanly');
        $this->assertSame(15, $s['clusters'], 'planted fixture clones must keep being found (re-pin only WITH fixture changes)');
        $this->assertSame(260, $s['duplicated_lines']);
        $this->assertSame(
            $s['files_scanned'],
            $s['files_reused'] + $s['files_processed'] + $s['files_without_blocks'] + $s['parse_errors'],
            'balance law must hold on the fixture corpus',
        );
    }

    public function testSelfScanOfSrcRunsGreenWithBalancedAccounting(): void
    {
        // The corpus that exposed every historical bug class: 156 files,
        // forked workers, the file that vanished before its shape was
        // handled (crash#1), and six enum/shape files that produce no
        // blocks. A real run of the repo on itself is the only smoke that
        // covers all three at once.
        $src = realpath(__DIR__ . '/../../src');
        $this->assertIsString($src);

        $run = $this->cli([
            'analyze', $src,
            '--min-impact', '5', '--limit', '3',
            '--json', $this->tmp . '/r.json',
        ], timeout: 300.0);
        $this->assertSame(0, $run['exit'], $run['combined']);

        $s = json_decode((string) file_get_contents($this->tmp . '/r.json'), true)['summary'];
        $this->assertSame(0, $s['parse_errors'], 'src must parse clean (crash#1 class regression)');
        $this->assertGreaterThan(100, $s['files_scanned']);
        $this->assertGreaterThan(0, $s['files_without_blocks'], 'src contains block-free shapes; they must be counted, not vanished');
        $this->assertGreaterThan(0, $s['files_processed']);
        $this->assertSame(
            $s['files_scanned'],
            $s['files_reused'] + $s['files_processed'] + $s['files_without_blocks'] + $s['parse_errors'],
        );
    }

    public function testBareReturnOrphanFixtureIsDeliberatelyEnumerated(): void
    {
        // N1 law: orphan fixtures are scanned EXPLICITLY and accounted for,
        // never accidentally swept into a broader corpus and ignored. The
        // dir holds exactly one file; it must land in exactly one bucket.
        $dir = realpath(__DIR__ . '/../Fixtures/bare-return');
        $this->assertIsString($dir);
        $phpFiles = glob($dir . '/*.php');
        $this->assertIsArray($phpFiles);

        $run = $this->cli(['analyze', $dir, '--json', $this->tmp . '/r.json']);
        $this->assertSame(0, $run['exit'], $run['combined']);

        $s = json_decode((string) file_get_contents($this->tmp . '/r.json'), true)['summary'];
        $this->assertSame(count($phpFiles), $s['files_scanned'], 'the scan must see exactly the enumerated fixture files');
        $this->assertSame(0, $s['parse_errors']);
        $this->assertSame(
            $s['files_scanned'],
            $s['files_processed'] + $s['files_without_blocks'],
            'every enumerated orphan lands in exactly one bucket',
        );
    }

    // ------------------------------------------------------------ hygiene

    public function testRepoStaysIndexPristineAcrossRealRuns(): void
    {
        $before = $this->gitPorcelain();
        $corpus = $this->makeDupCorpus(12);

        foreach ([['--workers=1'], ['--workers=2']] as $extra) {
            $run = $this->cli(['analyze', $corpus, '--min-impact', '0', ...$extra]);
            $this->assertSame(0, $run['exit'], $run['combined']);
        }
        $gate = $this->cli(['analyze', $corpus, '--fail-on-impact=1']);
        $this->assertSame(3, $gate['exit']);

        $this->assertSame($before, $this->gitPorcelain(), 'binary runs must never dirty the repo code surface');
    }

    // ----------------------------------------------------------- plumbing

    /**
     * @param list<string> $args
     * @param array<string, mixed> $cfgExtra
     * @return array{exit: int, out: string, err: string, combined: string}
     */
    private function cli(array $args, array $cfgExtra = [], ?string $cacheDir = null, float $timeout = self::TIMEOUT_S): array
    {
        $cfgPath      = $this->tmp . '/cfg.json';
        $cfgExtraFull = ['cache_dir' => $cacheDir ?? $this->tmp . '/cache'] + $cfgExtra;
        file_put_contents($cfgPath, (string) json_encode($cfgExtraFull));

        $cmd = array_merge([PHP_BINARY, self::repoRoot() . '/bin/phpdup'], $args, ['-c', $cfgPath]);
        $proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->tmp,
        );
        $this->assertIsResource($proc, 'proc_open refused ' . implode(' ', $cmd));

        // stdin closed immediately: equivalent to the </dev/null law, no
        // interactive prompt can block the child.
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = '';
        $err = '';
        $deadline = microtime(true) + $timeout;
        $outOpen  = true;
        $errOpen  = true;
        while ($outOpen || $errOpen) {
            $read  = array_filter([$outOpen ? $pipes[1] : null, $errOpen ? $pipes[2] : null]);
            $w = $e = null;
            if ($read !== [] && stream_select($read, $w, $e, 1) > 0) {
                foreach ($read as $stream) {
                    $chunk = fread($stream, 65536);
                    if ($stream === $pipes[1]) {
                        $out .= $chunk === false ? '' : $chunk;
                    } else {
                        $err .= $chunk === false ? '' : $chunk;
                    }
                }
            }
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                $this->fail(sprintf('phpdup child timed out after %.0fs: %s', $timeout, implode(' ', $args)));
            }
            if (@feof($pipes[1])) {
                $outOpen = false;
            }
            if (@feof($pipes[2])) {
                $errOpen = false;
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return ['exit' => $exit, 'out' => $out, 'err' => $err, 'combined' => $out . $err];
    }

    /** @param array<int, array<string, mixed>> $clusters */
    private function normalizeClusters(array $clusters): array
    {
        $keys = [];
        foreach ($clusters as $c) {
            $members = array_map(
                static fn (array $m): string => $m['file'] . ':' . $m['start'] . '-' . $m['end'],
                $c['members'] ?? [],
            );
            sort($members);
            $keys[] = implode('|', [
                $c['exact'] ? 'exact' : 'near',
                round((float) $c['similarity'], 4),
                (int) $c['impact'],
                (string) ($c['signature'] ?? ''),
                implode(';', $members),
            ]);
        }
        sort($keys);
        return $keys;
    }

    private function gitPorcelain(): string
    {
        $cmd = array_merge(
            ['git', 'status', '--porcelain', '--'],
            array_map(fn (string $p): string => self::repoRoot() . '/' . $p, self::PRISTINE_PATHS),
        );
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::repoRoot());
        $this->assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return $out;
    }

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * $n files, each with the SAME function body → one big clone cluster;
     * >= 8 files cross WorkerPool's fork threshold.
     */
    private function makeDupCorpus(int $n): string
    {
        $dir = $this->tmp . '/dup';
        mkdir($dir, 0o775, true);
        for ($i = 1; $i <= $n; $i++) {
            file_put_contents($dir . sprintf('/impl_%02d.php', $i), <<<'PHP'
                <?php
                function report_totals(array $rows): float
                {
                    $sum = 0.0;
                    foreach ($rows as $row) {
                        $sum += $row['amount'] * $row['rate'];
                    }
                    return round($sum, 2);
                }
                PHP);
        }
        return $dir;
    }

    /** Two genuinely unrelated files → zero clusters. */
    private function makeCleanCorpus(): string
    {
        $dir = $this->tmp . '/clean';
        mkdir($dir, 0o775, true);
        file_put_contents($dir . '/alpha.php', <<<'PHP'
            <?php
            function slugify(string $text): string
            {
                return strtolower(preg_replace('/[^a-z0-9]+/i', '-', $text) ?? $text);
            }
            PHP);
        file_put_contents($dir . '/omega.php', <<<'PHP'
            <?php
            function celsius_to_fahrenheit(float $c): float
            {
                return $c * 1.8 + 32;
            }
            PHP);
        return $dir;
    }

    /** Two independent clone families planted in every file → 2 clusters. */
    private function makeTwoClusterCorpus(): string
    {
        $dir = $this->tmp . '/twofam';
        mkdir($dir, 0o775, true);
        foreach ([1, 2, 3, 4] as $i) {
            file_put_contents($dir . '/fam_' . $i . '.php', <<<'PHP'
                <?php
                function report_totals(array $rows): float
                {
                    $sum = 0.0;
                    foreach ($rows as $row) {
                        $sum += $row['amount'] * $row['rate'];
                    }
                    return round($sum, 2);
                }
                function normalize_labels(array $items): array
                {
                    $out = [];
                    foreach ($items as $item) {
                        $out[] = trim(strtolower($item));
                    }
                    return $out;
                }
                PHP);
        }
        return $dir;
    }

    /** Three files that parse cleanly but yield no extractable blocks. */
    private function makeZeroBlockCorpus(): string
    {
        $dir = $this->tmp . '/zeroblock';
        mkdir($dir, 0o775, true);
        file_put_contents($dir . '/suit.php', "<?php\nenum Suit: string\n{\n    case Hearts = 'H';\n    case Spades = 'S';\n}\n");
        file_put_contents($dir . '/consts.php', "<?php\nfinal class Consts\n{\n    public const A = 1;\n    public const B = 2;\n}\n");
        file_put_contents($dir . '/notes.php', "<?php\n// a file of pure commentary\n");
        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;
            is_dir($full) ? $this->removeDir($full) : unlink($full);
        }
        rmdir($dir);
    }
}
