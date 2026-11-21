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

    public function testCandidateBudgetHaltRendersCleanErrorExit1(): void
    {
        // ft-n2: the generation-guard halt must surface as the clean
        // `phpdup:` error line naming its remedies — never Symfony's
        // throwable render with its usage-dump footer. The exit code
        // stays 1 (runtime-halt per docs/CLI.md: 0 = ok incl findings,
        // 2 = invalid invocation, 3 = gate trip).
        // The corpus + cfg mirror the unit-pinned armed guard
        // (CandidateBudgetFlagTest::makeChainCorpus): three blocks over
        // a one-pair budget halt in Phase 2 candidate generation.
        $corpus = $this->makeNearDupCorpus(3);
        $run = $this->cli(['analyze', $corpus], [
            'min_block_size'   => 2,
            'max_df'           => 1.0,
            'workers'          => 1,
            'lazy_ast'         => false,
            'candidate_budget' => 1,
        ]);

        $this->assertSame(1, $run['exit'], $run['combined']);
        $this->assertStringContainsString('phpdup: Candidate generation exceeded', $run['combined']);
        $this->assertStringContainsString('--candidate-budget', $run['combined'], 'the halt must name the live knob');
        $this->assertStringNotContainsString('Usage:', $run['combined'], 'no Symfony usage dump may ride the halt render');
        $this->assertStringNotContainsString('Stack trace', $run['combined']);
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

    public function testJsonReportCarriesSchemaOneThreeAccountingKeys(): void
    {
        $corpus = $this->makeDupCorpus(2);
        $run = $this->cli(['analyze', $corpus, '--json', $this->tmp . '/r.json']);
        $this->assertSame(0, $run['exit'], $run['combined']);

        $decoded = json_decode((string) file_get_contents($this->tmp . '/r.json'), true);
        $this->assertSame('1.6', $decoded['schema_version']);
        $this->assertSame(JsonSchemaSpec::SCHEMA_VERSION, $decoded['schema_version']);
        $this->assertSame(array_keys(JsonSchemaSpec::summaryShape()), array_keys($decoded['summary']));
        // Item 4.3: `declared_twins` is the trailing top-level key and
        // always present (empty array when the corpus declares nothing).
        $this->assertSame(array_keys(JsonSchemaSpec::topLevelShape()), array_keys($decoded));
        $this->assertSame([], $decoded['declared_twins']);
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
            'fork vs serial must agree on every cluster, id and member',
        );
        foreach ($js['clusters'] as $c) {
            $this->assertMatchesRegularExpression(
                '/^F[0-9a-f]{16}$/', (string) $c['id'],
                'external cluster identity is the canonical FindingId stream (item 4.4)',
            );
        }
    }

    public function testWarmClusterCacheRelocatesAfterTopCommentDrift(): void
    {
        // Review item [M]-1 pin: warm cluster-cache replay + comment-only
        // drift above clustered members. structuralHash is comment-
        // invariant, so the cluster cache MUST hit (correct by design) —
        // the lazy AST loader then has to re-anchor the stale member
        // ranges from content instead of throwing 'could not relocate'.
        // Pre-fix, the warm re-runs below exited 1. The >8-file corpus
        // keeps the forked RefactorStage workers' relocation path in scope
        // for the third leg.
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl unavailable: fork zone cannot be exercised on this box');
        }
        $corpus = $this->makeDupCorpus(12);
        $cache  = $this->tmp . '/warm-relocate';

        $cold = $this->cli(
            ['analyze', $corpus, '--workers=1', '--json', $this->tmp . '/warm-a.json'],
            cacheDir: $cache,
        );
        $this->assertSame(0, $cold['exit'], $cold['combined']);
        $ja = json_decode((string) file_get_contents($this->tmp . '/warm-a.json'), true);
        $this->assertIsArray($ja);
        $this->assertGreaterThan(0, $ja['summary']['clusters']);

        // Comment-only edit at the top of every file: every real block
        // shifts down two lines while every structuralHash stays put.
        foreach (glob($corpus . '/*.php') ?: [] as $f) {
            $src = (string) file_get_contents($f);
            $this->assertStringStartsWith("<?php\n", $src);
            file_put_contents($f, "<?php\n// drift\n// drift\n" . substr($src, 6));
        }

        $warm = $this->cli(
            ['analyze', $corpus, '--workers=1', '--json', $this->tmp . '/warm-b.json'],
            cacheDir: $cache,
        );
        $this->assertSame(0, $warm['exit'], $warm['combined']);
        $this->assertStringContainsString(
            'clusters from cache', $warm['combined'],
            'the corpus must genuinely replay the warm cluster cache',
        );
        $jb = json_decode((string) file_get_contents($this->tmp . '/warm-b.json'), true);
        $this->assertIsArray($jb);

        $ids = static function (array $j): array {
            $out = array_map(static fn (array $c): string => (string) $c['id'], $j['clusters']);
            sort($out);
            return $out;
        };
        $this->assertSame($ids($ja), $ids($jb), 'comment-only drift must not move any finding id');

        $lines = static function (array $j): array {
            $out = [];
            foreach ($j['clusters'] as $c) {
                foreach ($c['members'] ?? [] as $m) {
                    $out[(string) $c['id'] . '|' . (string) $m['file']] = [(int) $m['start'], (int) $m['end']];
                }
            }
            ksort($out);
            return $out;
        };
        $la = $lines($ja);
        $lb = $lines($jb);
        $this->assertSame(array_keys($la), array_keys($lb), 'membership must be unchanged by the replay');
        foreach ($la as $key => $pair) {
            $this->assertSame([$pair[0] + 2, $pair[1] + 2], $lb[$key],
                "warm-replayed member $key must carry the relocated (+2) lines");
        }

        $fork = $this->cli(
            ['analyze', $corpus, '--workers=2', '--json', $this->tmp . '/warm-c.json', '--stats'],
            cacheDir: $cache,
        );
        $this->assertSame(0, $fork['exit'], $fork['combined']);
        $this->assertStringContainsString('workers: 2 (pcntl available)', $fork['combined']);
        $jc = json_decode((string) file_get_contents($this->tmp . '/warm-c.json'), true);
        $this->assertIsArray($jc);
        $this->assertSame(
            $this->normalizeClusters($jb['clusters']),
            $this->normalizeClusters($jc['clusters']),
            'forked workers relocating from the same warm cache must agree with serial, id for id',
        );
    }

    public function testWarmDeletionDriftIsLoudOrHonestNeverDoubleAnchored(): void
    {
        // Review round-3 [M-1] pin (vector 1): content-preserving DELETION
        // of the filler block moves both twin members UP 15 lines while the
        // cluster cache still hits (correct by design). The monotone claim
        // ledger must then either produce the honest pairing (each member on
        // its own live block) or die with 'could not relocate' — silently
        // double-anchoring two members onto one node (the r2 behaviour the
        // reviewer reproduced at RC=0) is the corruption this pins shut.
        // The unit suite proves both branches deterministically per resolve
        // order; through the real pipeline the member order decides which
        // branch fires, so this pin asserts the union — minus silence.
        $corpus = $this->makeTwinForeachCorpus();
        $cache  = $this->tmp . '/warm-del';

        $cold = $this->cli(
            ['analyze', $corpus, '--workers=1', '--json', $this->tmp . '/del-a.json'],
            cacheDir: $cache,
        );
        $this->assertSame(0, $cold['exit'], $cold['combined']);
        $ja = json_decode((string) file_get_contents($this->tmp . '/del-a.json'), true);
        $this->assertIsArray($ja);

        $rowsBefore = $this->memberRows($ja);
        $this->deleteTwinFillers($corpus);
        // Snapshot AFTER our own drift edit: whatever the warm run does to
        // corpus bytes from here on is strictly the analyzer's doing.
        $twinBefore = (string) file_get_contents($corpus . '/impl_12.php');

        $warm = $this->cli(
            ['analyze', $corpus, '--workers=1', '--json', $this->tmp . '/del-b.json'],
            cacheDir: $cache,
        );

        if ($warm['exit'] === 0) {
            $this->assertStringContainsString('clusters from cache', $warm['combined']);
            $jb = json_decode((string) file_get_contents($this->tmp . '/del-b.json'), true);
            $this->assertIsArray($jb);
            $rowsAfter = $this->memberRows($jb);
            $this->assertSame(array_keys($rowsBefore), array_keys($rowsAfter), 'membership stable');
            foreach ($rowsBefore as $id => $pairs) {
                $seen = [];
                foreach ($pairs as $i => $pair) {
                    [$file, $s, $e] = $pair;
                    [$aFile, $aS, $aE] = $rowsAfter[$id][$i];
                    $this->assertSame($file, $aFile, 'member file order must be stable across replay');
                    // Either genuinely relocated (a member the deletion
                    // moved: -15) or untouched (a member elsewhere).
                    $this->assertTrue(
                        [$aS, $aE] === [$s - 15, $e - 15] || [$aS, $aE] === [$s, $e],
                        "warm member of $id must be relocated -15 or untouched, got $s-$e -> $aS-$aE",
                    );
                    $key = $file . '#' . $aS . '-' . $aE;
                    $this->assertArrayNotHasKey($key, $seen, "two members of $id silently share live span $key");
                    $seen[$key] = true;
                }
            }
            // The twin file's two members specifically must sit on THEIR
            // lines: after deleting lines 5-19, foreach #1/#2 live at 5-7
            // and 12-14 (their cold spans minus 15).
            $this->assertStringContainsString('// g1', (string) file_get_contents($corpus . '/impl_12.php'));
        } else {
            $this->assertStringContainsString('could not relocate', $warm['combined'],
                'a non-zero warm exit must be the loud relocation spine, not some unrelated failure');
            $this->assertStringContainsString('clusters from cache', $warm['combined']);
            $this->assertFileDoesNotExist($this->tmp . '/del-b.json', 'a crashed run ships no report');
        }
        $this->assertSame(
            $twinBefore,
            (string) file_get_contents($corpus . '/impl_12.php'),
            'analyze alone must never touch corpus bytes',
        );
    }

    public function testWarmDeletionDriftApplyNeverWritesSilently(): void
    {
        // Review round-3 pin (vector 1 + --apply trace): the r1 reviewer
        // traced that double-anchored members make ApplyExtractor::applyMember
        // (src/Refactor/ApplyExtractor.php:338-341,357-417) silently SKIP the
        // second site and still report 'applied' — a partial refactor shipped
        // as complete. The loader spine now dies BEFORE any write, or
        // resolves honestly. Assert: loud ⇒ corpus bytes byte-identical;
        // honest ⇒ no member pair shares a span (the precondition of the
        // silent skip cannot exist).
        $corpus = $this->makeTwinForeachCorpus();
        $cache  = $this->tmp . '/warm-del-apply';

        $cold = $this->cli(
            ['analyze', $corpus, '--workers=1', '--json', $this->tmp . '/ap-a.json'],
            cacheDir: $cache,
        );
        $this->assertSame(0, $cold['exit'], $cold['combined']);

        $this->deleteTwinFillers($corpus);
        $bytes = [];
        foreach (glob($corpus . '/*.php') ?: [] as $f) {
            $bytes[$f] = sha1_file($f);
        }

        $run = $this->cli(
            ['analyze', $corpus, '--workers=1', '--apply', '--json', $this->tmp . '/ap-b.json'],
            cacheDir: $cache,
        );

        if ($run['exit'] !== 0) {
            $this->assertStringContainsString('could not relocate', $run['combined']);
            foreach ($bytes as $f => $sum) {
                $this->assertSame($sum, sha1_file($f), "loud warm --apply must leave $f untouched");
            }
        } else {
            $jb = json_decode((string) file_get_contents($this->tmp . '/ap-b.json'), true);
            $this->assertIsArray($jb);
            foreach ($this->memberRows($jb) as $id => $pairs) {
                $keys = array_map(static fn (array $p): string => $p[0] . '#' . $p[1] . '-' . $p[2], $pairs);
                $this->assertCount(count(array_unique($keys)), $keys, "cluster $id double-anchors under --apply");
            }
        }
    }

    public function testForkWarmDeletionDriftMatchesSerialAfterContentBinding(): void
    {
        // Review round-4 [M-2] parity pin (+ [M-1] cure at pipeline level):
        // on a corpus with enough clusters that RefactorStage genuinely
        // forks (PARALLEL_MIN_CLUSTERS=8), a warm deletion-drift replay
        // must produce BYTE-IDENTICAL member rows at workers=1 and
        // workers=2. Pre-r4 the fork path reported the COLD (stale) lines
        // for relocated members while serial re-anchored — the parent
        // never merged children's range refreshes. The fix: one
        // deterministic parent-side rebase sweep BEFORE the fork/serial
        // split, so every worker count shares the same assignment and
        // children's re-entries are strict no-ops. Truth geometry after
        // deleting the 15 filler lines above the first twin clone:
        // the two fam_1t foreach members land on [5,7] and [12,14].
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl unavailable: fork zone cannot be exercised on this box');
        }
        $corpus = $this->makeForkReadyTwinCorpus();
        $cache  = $this->tmp . '/fork-parity';

        $cold = $this->cli(
            ['analyze', $corpus, '--workers=1', '--json', $this->tmp . '/fp-cold.json'],
            cacheDir: $cache,
        );
        $this->assertSame(0, $cold['exit'], $cold['combined']);
        $jc = json_decode((string) file_get_contents($this->tmp . '/fp-cold.json'), true);
        $this->assertIsArray($jc);
        $this->assertGreaterThanOrEqual(
            8, $jc['summary']['clusters'],
            'the corpus must clear PARALLEL_MIN_CLUSTERS so the -j2 leg really forks',
        );

        $this->deleteTopFillers($corpus . '/fam_1t.php', '/^\/\/ f\d\d$/');

        $w1 = $this->cli(
            ['analyze', $corpus, '--workers=1', '--json', $this->tmp . '/fp-w1.json'],
            cacheDir: $cache,
        );
        $this->assertSame(0, $w1['exit'], $w1['combined']);
        $this->assertStringContainsString('clusters from cache', $w1['combined']);

        $w2 = $this->cli(
            ['analyze', $corpus, '--workers=2', '--stats', '--json', $this->tmp . '/fp-w2.json'],
            cacheDir: $cache,
        );
        $this->assertSame(0, $w2['exit'], $w2['combined']);
        $this->assertStringContainsString('workers: 2 (pcntl available)', $w2['combined'],
            'the fork path must actually dispatch');
        $this->assertStringContainsString('clusters from cache', $w2['combined']);

        $j1 = json_decode((string) file_get_contents($this->tmp . '/fp-w1.json'), true);
        $j2 = json_decode((string) file_get_contents($this->tmp . '/fp-w2.json'), true);
        $this->assertIsArray($j1);
        $this->assertIsArray($j2);

        $this->assertSame(
            $this->memberRows($j1),
            $this->memberRows($j2),
            'PARITY: forked and serial warm replays must agree on every member line',
        );

        $twinRows = static function (array $rows): array {
            $out = [];
            foreach ($rows as $list) {
                foreach ($list as [$file, $start, $end]) {
                    if (basename($file) === 'fam_1t.php') {
                        $out[] = [$start, $end];
                    }
                }
            }
            sort($out);
            return $out;
        };
        $this->assertSame([[5, 7], [12, 14]], $twinRows($this->memberRows($j1)),
            'serial replay must carry the live (post-deletion) truth lines');
        $this->assertSame([[5, 7], [12, 14]], $twinRows($this->memberRows($j2)),
            'fork replay must carry the same live truth lines');

        $ids = static function (array $j): array {
            $out = array_map(static fn (array $c): string => (string) $c['id'], $j['clusters']);
            sort($out);
            return $out;
        };
        $this->assertSame($ids($jc), $ids($j1), 'deletion drift must not move finding ids');
        $this->assertSame($ids($jc), $ids($j2), 'worker count must not move finding ids');

        // And ONLY the twin file's two rows moved between cold and warm.
        $strip = static function (array $rows): array {
            $out = [];
            foreach ($rows as $id => $list) {
                foreach ($list as [$file, $start, $end]) {
                    if (basename($file) !== 'fam_1t.php') {
                        $out[$id][] = [$file, $start, $end];
                }
                }
            }
            return $out;
        };
        $this->assertSame($strip($this->memberRows($jc)), $strip($this->memberRows($j1)),
            'untouched files must keep their rows exactly');
    }

    public function testWarmDeletionDriftApplyForkWritesAtLivePositions(): void
    {
        // Review round-4 [M-2] --apply posture pin on the reviewer's
        // cross-hint (fork5) geometry: two same-scope if-twins whose cold
        // hints [26,29]/[30,33] stop existing after the 15 filler lines
        // above them are deleted (truth [11,14]/[15,18]), with three
        // decoy echo-ifs of DIFFERENT content in the same group — the
        // exact corpus where r3 spread-mis-anchored at w=1 and served
        // silently stale lines at w>=2. --apply rides the same loader,
        // so after the r4 cure the patch must annotate LIVE positions (or
        // die loud with zero writes — tolerated symmetrically).
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl unavailable: fork zone cannot be exercised on this box');
        }
        $corpus = $this->makeCrossHintFork5Corpus();
        $cache  = $this->tmp . '/fork5-apply';

        $cold = $this->cli(
            ['analyze', $corpus, '--workers=1', '--json', $this->tmp . '/f5-cold.json'],
            cacheDir: $cache,
        );
        $this->assertSame(0, $cold['exit'], $cold['combined']);

        $this->deleteTopFillers($corpus . '/t0.php', '/^\/\/ filler \d\d$/');

        $before = [];
        foreach (glob($corpus . '/*.php') ?: [] as $f) {
            $before[basename($f)] = sha1((string) file_get_contents($f));
        }

        $apply = $this->cli(
            ['analyze', $corpus, '--workers=2', '--apply', '--json', $this->tmp . '/f5-af.json'],
            cacheDir: $cache,
        );

        $after = [];
        foreach (glob($corpus . '/*.php') ?: [] as $f) {
            $after[basename($f)] = sha1((string) file_get_contents($f));
        }
        $this->assertSame($before, $after, 'the analyze path must never mutate corpus bytes');

        if ($apply['exit'] !== 0) {
            // Loud branch: the fail-loud spine must precede every write.
            $this->assertStringContainsString('could not relocate', $apply['combined']);
            $this->assertFileDoesNotExist($this->tmp . '/f5-af.json');
            $this->assertFileDoesNotExist($this->tmp . '/refactored/apply.diff');
            return;
        }

        $this->assertStringContainsString('clusters from cache', $apply['combined']);
        $patch = $this->tmp . '/refactored/apply.diff';
        $this->assertFileExists($patch, '--apply dry-run must emit the refactor patch');
        $diff = (string) file_get_contents($patch);
        $this->assertStringContainsString('t0.php:11-14', $diff,
            'member[0] must be annotated at its LIVE post-deletion span');
        $this->assertStringContainsString('t0.php:15-18', $diff,
            'member[1] must be annotated at its LIVE post-deletion span');
        $this->assertStringNotContainsString('t0.php:26-29', $diff,
            'the stale cold hint must be gone (r3 fork served it silently)');
        $this->assertStringNotContainsString('t0.php:30-33', $diff,
            'the stale cold hint must be gone');
    }

    // ------------------------------------------------------ repo corpora

    public function testFixturesCorpusMatchesCommittedContract(): void
    {
        // Strict mirror of the CI smoke step (see .github/workflows/ci.yml).
        // Truth at writing: 20 files, 19 clusters, 356 duplicated lines,
        // zero parse errors — the planted clones in tests/Fixtures are a
        // contract, so these numbers may only move with the fixtures (or
        // with an engine fix that revives detection: engine-S01 lifted the
        // df-cap floor from 1 to 2, and with 71 blocks in this corpus the
        // old cap of 1 left the ENTIRE near-dup tier dead at the shipped
        // default — the 4 extra clusters are planted near-dups finally
        // being found, not noise).
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
        $this->assertSame('1.6', $decoded['schema_version']);
        $this->assertSame(20, $s['files_scanned']);
        $this->assertSame(0, $s['parse_errors'], 'the fixture corpus must parse cleanly');
        $this->assertSame(19, $s['clusters'], 'planted fixture clones must keep being found (re-pin only WITH fixture changes)');
        $this->assertSame(356, $s['duplicated_lines']);
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
            // Item 4.4: cluster ids are content-derived FindingIds — the
            // same canonical set in any run order. Folding the id into the
            // comparison key means every existing fork-vs-serial and
            // warm-cache leg now pins id equality, not just membership.
            $keys[] = implode('|', [
                (string) $c['id'],
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
    /**
     * Review [M-1] round-3 corpus: eleven plain files + one twin file whose
     * body carries the SAME foreach twice, separated by 15 deletable
     * filler lines above the first clone and 4 gap lines between. Deleting
     * the fillers is comment-only drift: every structuralHash (and thus
     * the cluster-cache corpus token multiset) is untouched, so a warm run
     * MUST hit the cache — and the lazy loader then replays two stale
     * hints whose upward shift has no forward-bias answer.
     */
    private function makeTwinForeachCorpus(): string
    {
        $dir = $this->tmp . '/twins';
        mkdir($dir, 0o775, true);
        for ($i = 1; $i <= 11; $i++) {
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
        $fillers = '';
        for ($f = 1; $f <= 15; $f++) {
            $fillers .= sprintf("        // f%02d\n", $f);
        }
        $gaps = "        // g1\n        // g2\n        // g3\n        // g4\n";
        $clone = "                    foreach (\$rows as \$row) {\n                        \$sum += \$row['amount'] * \$row['rate'];\n                    }\n";
        file_put_contents($dir . '/impl_12.php', <<<PHP
            <?php
            function report_totals(array \$rows): float
            {
                \$sum = 0.0;

            PHP . $fillers . $clone . $gaps . $clone . <<<PHP
                    return round(\$sum, 2);
                }
            PHP);
        return $dir;
    }

    /**
     * Fork-ready twin corpus (r4 parity pin): ten DISTINCT statement-kind
     * families (aggressive normalization folds numeric literals, so the
     * KIND of statement is what separates structural hashes) × two
     * byte-identical files, plus a twin file carrying family 1's foreach
     * clone twice under 15 unindented '// fNN' filler lines. Cold run
     * yields 10 rendered clusters (> PARALLEL_MIN_CLUSTERS=8 ⇒ -j2 really
     * forks) and a 13-member foreach bucket including the twin's pair at
     * [20,22]/[27,29].
     */
    private function makeForkReadyTwinCorpus(): string
    {
        $dir = $this->tmp . '/forkfam';
        mkdir($dir, 0o775, true);
        $bodies = [
            1  => "    \$acc = \$acc + \$row['amount'];\n",
            2  => "    \$acc .= (string) \$row['amount'];\n",
            3  => "    if (isset(\$row['amount'])) {\n        \$seen = true;\n    }\n",
            4  => "    for (\$i = 0; \$i < 2; \$i++) {\n        \$acc += \$i;\n    }\n",
            5  => "    while (false) {\n        break;\n    }\n",
            6  => "    try {\n        \$acc += 1;\n    } catch (Exception \$e) {\n        \$acc -= 1;\n    }\n",
            7  => "    \$acc = match (true) {\n        default => \$acc,\n    };\n",
            8  => "    \$acc = array_map('intval', \$rows);\n",
            9  => "    switch (\$row['kind']) {\n        case 'a': break;\n    }\n",
            10 => "    do {\n        \$acc[] = 1;\n    } while (false);\n",
        ];
        foreach ($bodies as $n => $inner) {
            $src = "<?php\nfunction report_{$n}(array \$rows): array\n{\n    \$acc = [];\n"
                . "    foreach (\$rows as \$row) {\n" . $inner . "    }\n"
                . "    return \$acc;\n}\n";
            file_put_contents(sprintf($dir . '/fam_%da.php', $n), $src);
            file_put_contents(sprintf($dir . '/fam_%db.php', $n), $src);
        }
        $fillers = '';
        for ($k = 1; $k <= 15; $k++) {
            $fillers .= sprintf("// f%02d\n", $k);
        }
        $oneClone = "    foreach (\$rows as \$row) {\n" . $bodies[1] . "    }\n";
        $twin = "<?php\nfunction report_twin1(array \$rows): array\n{\n    \$acc = [];\n"
            . $fillers . $oneClone
            . "// g1\n// g2\n// g3\n// g4\n"
            . $oneClone
            . "    return \$acc;\n}\n";
        file_put_contents($dir . '/fam_1t.php', $twin);
        return $dir;
    }

    /**
     * Cross-hint decoy corpus (r4 apply-fork pin; ported verbatim from the
     * reviewer's fork-cross.sh recipe): t0.php holds two list-assign
     * if-twins (cold [26,29]/[30,33]) and three echo-if decoys in the same
     * top-level scope, under 15 '// filler NN' lines; eight decoy twin
     * function files keep the cold cluster count at/above the fork gate.
     */
    private function makeCrossHintFork5Corpus(): string
    {
        $dir = $this->tmp . '/crosshint';
        mkdir($dir, 0o775, true);
        $t0 = "<?php\nnamespace CL;\n";
        for ($i = 1; $i <= 15; $i++) {
            $t0 .= sprintf("// filler %02d\n", $i);
        }
        $t0 .= implode('', [
            "if (\$x > 1) {\n    echo 'x';\n}\n",
            "// c1\n// c2\n",
            "if (\$x > 2) {\n    echo 'x';\n}\n",
            "if (\$x > 3) {\n    list(\$q, \$w) = [1, 2];\n    \$w += 1;\n}\n",
            "if (\$x > 4) {\n    list(\$q, \$w) = [1, 2];\n    \$w += 1;\n}\n",
            "// c3\n// c4\n",
            "if (\$x > 5) {\n    echo 'x';\n}\n",
        ]);
        file_put_contents($dir . '/t0.php', $t0);
        foreach ([3, 5, 7, 9, 11, 13, 15, 17] as $n) {
            $stmts = str_repeat("        \$sum += \$r['a'] * \$r['b'];\n", $n);
            $fn = static fn (string $name): string => "function {$name}(array \$rows): float\n{\n    \$sum = 0.0;\n"
                . $stmts . "    return round(\$sum, 2);\n}\n";
            file_put_contents($dir . sprintf('/g%d.php', $n), "<?php\nnamespace CL;\n" . $fn('g' . $n) . $fn('h' . $n));
        }
        return $dir;
    }

    /**
     * Delete every line matching $pattern from $file, asserting exactly 15
     * went away (the content-preserving drift protocol shared by both r4
     * pins — comment lines only, so structuralHash and the cache stay hot).
     */
    private function deleteTopFillers(string $file, string $pattern): void
    {
        $lines = explode("\n", (string) file_get_contents($file));
        $kept  = [];
        foreach ($lines as $line) {
            if (preg_match($pattern, $line) === 1) {
                continue;
            }
            $kept[] = $line;
        }
        $removed = count($lines) - count($kept);
        $this->assertSame(15, $removed, "drift protocol on $file must remove exactly 15 filler lines");
        file_put_contents($file, implode("\n", $kept));
    }

    /** Delete the 15 '// fNN' filler lines from the twin file (content-preserving). */
    private function deleteTwinFillers(string $corpus): void
    {
        $file = $corpus . '/impl_12.php';
        $lines = explode("\n", (string) file_get_contents($file));
        $kept  = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s+\/\/ f\d\d$/', $line)) {
                continue;
            }
            $kept[] = $line;
        }
        $removed = count($lines) - count($kept);
        $this->assertSame(15, $removed, 'the deletion protocol must remove exactly the filler block');
        file_put_contents($file, implode("\n", $kept));
    }

    /** @return array<string, list<array{0:string,1:int,2:int}>> cluster-id => member [file,start,end] rows */
    private function memberRows(array $json): array
    {
        $out = [];
        foreach ($json['clusters'] as $c) {
            foreach ($c['members'] ?? [] as $m) {
                $out[(string) $c['id']][] = [(string) $m['file'], (int) $m['start'], (int) $m['end']];
            }
        }
        ksort($out);
        return $out;
    }

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

    /**
     * Mirror of the unit-pinned armed-guard corpus (CandidateBudgetFlagTest
     * ::makeChainCorpus): n copies of one small function, byte-identical so
     * Phase 2 candidate generation crosses any pair budget below the full
     * pair count — used where a test needs the budget halt, not clusters.
     */
    private function makeNearDupCorpus(int $n): string
    {
        $dir = $this->tmp . '/chain';
        mkdir($dir, 0o775, true);
        $body = "<?php\nfunction probe_%d(\$x) {\n    \$y = \$x + 1;\n    return \$y;\n}\n";
        for ($i = 0; $i < $n; $i++) {
            file_put_contents($dir . "/f$i.php", sprintf($body, 0));
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
