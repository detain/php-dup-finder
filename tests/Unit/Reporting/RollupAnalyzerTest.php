<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Parsing\AstParser;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\RefactorStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Reporting\CliReporter;
use Phpdup\Reporting\JsonReporter;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\Report;
use Phpdup\Reporting\RollupAnalyzer;
use Phpdup\Util\LineRange;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Item 2.2 (ROLLUP): the corpus-awareness rollups.
 *
 * Hermetic done-when evidence for the f08 design. The filing's headline
 * numbers (1,438 comparable-of-20,789 lines, 36,806 call sites, the 1,286
 * nusoap declarations, blade's 8,802/11,345) belong to the private
 * sugar-vps-master corpus — owner-gated evidence this repo cannot reproduce
 * — so the substitution the lane brief mandates is an END-TO-END pin on the
 * checked-in fixture corpora below: exact expected rollup integers, in the
 * same machine-portable relative-path form the goldens carry.
 *
 * Every behavioural law of f08 §2 gets a named witness: interval-union
 * never sum, both-direction twins, seed-direct comparison on retained bags,
 * declared-only honesty for zero-block seed pairs, demoted-cluster exempt
 * numerators, content-derived ids that survive line shifts, and the bounded
 * payload caps with deterministic truncation.
 */
final class RollupAnalyzerTest extends TestCase
{
    /**
     * The sql fixture, pinned: three methods in one Repos.php, three classes
     * (the file nests two more), three SqlTableExtractor-recognised
     * statements over three distinct tables, nine call sites under the
     * CallGraph species set, and the union coverage the three blocks leave
     * on 35 disk lines.
     */
    private const SQL_TOTALS = [
        'functions' => 0, 'methods' => 3, 'classes' => 3, 'interfaces' => 0, 'traits' => 0, 'enums' => 0,
        'expressions' => 54, 'call_sites' => 9, 'sql_refs' => 3, 'sql_tableless' => 0, 'file_lines' => 35,
        'declarations' => 6,
        'files' => 1, 'blocks' => 3, 'comparable_lines' => 21,
        'distinct_calls' => 3, 'distinct_sql_tables' => 3,
        'twin_file_pairs' => 0, 'mirrored_directories' => 0, 'declared_only_pairs' => 0,
        'direct_comparisons' => 0, 'seed_candidates_dropped' => 0,
    ];

    /**
     * The micro-fixture corpus (item 2.1's family): 14 files / 41 blocks,
     * and the first corpus in this repo where BOTH twin legs earn their
     * keep — 15 cluster-linked pairs plus 8 seed-direct claims
     * (direct_comparisons) that no clustering ever produced. totals-only
     * pin: per-file rows ride the goldens.
     */
    private const MICRO_FIXTURE_TOTALS = [
        'functions' => 0, 'methods' => 12, 'classes' => 11, 'interfaces' => 0, 'traits' => 0, 'enums' => 0,
        'expressions' => 759, 'call_sites' => 101, 'sql_refs' => 11, 'sql_tableless' => 0, 'file_lines' => 342,
        'declarations' => 23,
        'files' => 14, 'blocks' => 41, 'comparable_lines' => 178,
        'distinct_calls' => 12, 'distinct_sql_tables' => 8,
        'twin_file_pairs' => 23, 'mirrored_directories' => 0, 'declared_only_pairs' => 0,
        'direct_comparisons' => 8, 'seed_candidates_dropped' => 0,
    ];

    /**
     * The gitignore walk corpus (W2a item 5.2): 19 one-line clone scripts.
     * All of them mutual twins (identical bodies), 21 mirrored directory
     * pairs past the >=2 floor, and 171-20=151 pairs truncated out of the
     * bounded twin list — the cap story told by a checked-in corpus.
     */
    private const GITIGNORE_FIXTURE_TOTALS = [
        'functions' => 19, 'methods' => 0, 'classes' => 0, 'interfaces' => 0, 'traits' => 0, 'enums' => 0,
        'expressions' => 19, 'call_sites' => 0, 'sql_refs' => 0, 'sql_tableless' => 0, 'file_lines' => 19,
        'declarations' => 19,
        'files' => 19, 'blocks' => 19, 'comparable_lines' => 19,
        'distinct_calls' => 0, 'distinct_sql_tables' => 0,
        'twin_file_pairs' => 171, 'mirrored_directories' => 21, 'declared_only_pairs' => 0,
        'direct_comparisons' => 0, 'seed_candidates_dropped' => 0,
    ];

    private string $scratch;

    protected function setUp(): void
    {
        $this->scratch = '/tmp/opencode/rollup-test-' . bin2hex(random_bytes(6));
        mkdir($this->scratch, 0777, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->scratch)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->scratch, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->scratch);
    }

    // ------------------------------------------------------------------
    // End-to-end pinned integers (the private-corpus done-when analogue)
    // ------------------------------------------------------------------

    public function testClassShapeCorpusPinnedIntegers(): void
    {
        $dir = realpath(__DIR__ . '/../../Fixtures/class-shape');
        $this->assertIsString($dir);
        [$clusters, $blocks, $files] = $this->pipelineFor($dir);
        $rollups = (new RollupAnalyzer(relRoots: [$dir]))->analyze($clusters, $blocks, $files);

        // class-shape is the item-2.3 twin fixture family: tw_a/tw_b mirror
        // each other (a real linked twin at 5/9 union coverage), tp_a/tp_b
        // pair members without mirroring whole files. Both directions at
        // 0.5556 ≥ the 0.5 bar is exactly the f08 §2 judgement; the naive
        // per-member sum would have read differently.
        $this->assertSame([
            'functions' => 0, 'methods' => 8, 'classes' => 4, 'interfaces' => 0, 'traits' => 0, 'enums' => 0,
            'expressions' => 40, 'call_sites' => 2, 'sql_refs' => 0, 'sql_tableless' => 0, 'file_lines' => 85,
            'declarations' => 12,
            'files' => 4, 'blocks' => 8, 'comparable_lines' => 34,
            'distinct_calls' => 1, 'distinct_sql_tables' => 0,
            'twin_file_pairs' => 1, 'mirrored_directories' => 0, 'declared_only_pairs' => 0,
            'direct_comparisons' => 0, 'seed_candidates_dropped' => 0,
        ], $rollups['totals']);

        $this->assertSame('tw_a.php', $rollups['top_files'][0]['path']);
        $this->assertSame('class-shape', $rollups['top_files'][0]['root']);
        $this->assertSame(9, $rollups['top_files'][0]['comparable_lines']);
        $this->assertSame(25, $rollups['top_files'][0]['file_lines']);
        // Content-derived row id (f08: never positional) — the golden-style
        // pin. It moves only when this file's blocks move.
        $this->assertSame('ecfd2d674ae64fb6', $rollups['top_files'][0]['id']);

        $this->assertSame([[
            'a_root' => 'class-shape', 'a_path' => 'tw_a.php',
            'b_root' => 'class-shape', 'b_path' => 'tw_b.php',
            'a_to_b' => 0.5556, 'b_to_a' => 0.5556,
            'seed_best' => 0, 'coverage' => 0.5556,
            'linked_cluster_ids' => ['Fd3d715b22d060c9c'],
            'seed_direct' => false,
        ]], $rollups['twin_pairs']);
        $this->assertSame([], $rollups['mirrored_directories']);
        $this->assertSame([], $rollups['declared_only_pairs']);
        $this->assertSame(0, $rollups['files_truncated']);
    }

    public function testSqlCorpusPinnedIntegers(): void
    {
        $dir = realpath(__DIR__ . '/../../Fixtures/sql');
        $this->assertIsString($dir);
        [$clusters, $blocks, $files] = $this->pipelineFor($dir);
        $rollups = (new RollupAnalyzer(relRoots: [$dir]))->analyze($clusters, $blocks, $files);
        $this->assertSame(self::SQL_TOTALS, $rollups['totals']);
    }

    public function testMicroFixtureCorpusPinnedIntegers(): void
    {
        $dir = realpath(__DIR__ . '/../../Fixtures/micro-fixture');
        $this->assertIsString($dir);
        [$clusters, $blocks, $files] = $this->pipelineFor($dir);
        $rollups = (new RollupAnalyzer(relRoots: [$dir]))->analyze($clusters, $blocks, $files);

        $this->assertSame(self::MICRO_FIXTURE_TOTALS, $rollups['totals']);
        // The twin-list cap + truncation counter, earned on a real corpus:
        // 23 claims, 20 listed, 3 disclosed as dropped.
        $this->assertCount(RollupAnalyzer::TOP_PAIRS, $rollups['twin_pairs']);
        $this->assertSame(3, $rollups['pairs_truncated']);
        // Every inline_/preamble_/scripts_ path is unique under one root, so
        // path seeding never fires here; the eight direct comparisons are all
        // basename-seeded families (inline_N vs inline_M share no name
        // either — the seeding that DOES fire is scripts_/preamble_ prefix
        // clustering). Honest zero for mirrored dirs at this layout:
        $this->assertSame([], $rollups['mirrored_directories']);
    }

    public function testGitignoreCorpusWalksAndPins(): void
    {
        $dir = realpath(__DIR__ . '/../../Fixtures/gitignore');
        $this->assertIsString($dir);
        [$clusters, $blocks, $files] = $this->pipelineFor($dir);
        $rollups = (new RollupAnalyzer(relRoots: [$dir]))->analyze($clusters, $blocks, $files);

        $this->assertSame(self::GITIGNORE_FIXTURE_TOTALS, $rollups['totals']);
        // All 19 clones share one relative-name soup, and the directory
        // roll-up floors at >=2 file pairs per pair-of-dirs (f08 §2).
        $this->assertSame(RollupAnalyzer::TOP_DIRS, count($rollups['mirrored_directories']));
        $this->assertSame(11, $rollups['dirs_truncated']);
        // The W2a honor-ignore posture is off by default, so the walk finds
        // every file under walk/ — the rollup sees the corpus the scanner
        // admitted, no more (the item 5.2 opt-in would shrink files to the
        // pinned 19 from a larger set; this pin is the default-run truth).
        $this->assertSame(19, $rollups['files_truncated'] + count($rollups['top_files']));
    }

    // ------------------------------------------------------------------
    // f08 behavioural laws, synthetic witnesses
    // ------------------------------------------------------------------

    public function testComparableIsIntervalUnionNeverSum(): void
    {
        $dir = $this->tmpDir('union');
        // Ranges 2-20 and 5-10 in one file: the nested 6 lines must not
        // double-count (f08 §2: Vps/Start read 74.5% naive vs 56.9% union).
        $a = $this->writePhp($dir, 'nest_a.php', 2, 20, 5, 10);
        $b = $this->writePhp($dir, 'nest_b.php', 2, 20, 21, 21);

        $rollups = (new RollupAnalyzer(relRoots: [$dir]))->analyze(
            [$this->plainCluster('c1', [
                $this->synthBlock($a, 2, 20, bag: [1 => 9, 2 => 3]),
                $this->synthBlock($a, 5, 10, id: 'a2', bag: [1 => 9, 2 => 3]),
                $this->synthBlock($b, 2, 20, id: 'b1', bag: [3 => 9, 4 => 3]),
                $this->synthBlock($b, 2, 20, id: 'b2', bag: [3 => 9, 4 => 3]),
            ])],
            [
                $this->synthBlock($a, 2, 20, bag: [1 => 9, 2 => 3]),
                $this->synthBlock($a, 5, 10, id: 'a2', bag: [1 => 9, 2 => 3]),
                $this->synthBlock($b, 2, 20, id: 'b1', bag: [3 => 9, 4 => 3]),
                $this->synthBlock($b, 2, 20, id: 'b2', bag: [3 => 9, 4 => 3]),
            ],
            [$a, $b],
        );

        // Per file the union is 19 (2-20 ∪ 5-10 = 2-20; the duplicate block
        // never adds a line) — the sum reading would be 25 and 39.
        $this->assertSame(19, $rollups['top_files'][0]['comparable_lines']);
        $this->assertSame(19, $rollups['top_files'][1]['comparable_lines']);
        $this->assertSame(38, $rollups['totals']['comparable_lines']);
    }

    public function testTwinRequiresBothDirections(): void
    {
        $dir = $this->tmpDir('dirs');
        // A: 30 covered lines inside the cluster, 61 outside → cov(A→B)=30/91
        // B: all 30 of its comparable lines inside → cov(B→A)=1.0.
        // One-sided containment is NOT a twin (f08 §2), and the bags are
        // deliberately dissimilar so the seed-direct leg cannot rescue it.
        $a = $this->writePhp($dir, 'A.php', 1, 30, 40, 100);
        $b = $this->writePhp($dir, 'B.php', 1, 30);

        $a1 = $this->synthBlock($a, 1, 30, bag: [1 => 9, 2 => 3]);
        $a2 = $this->synthBlock($a, 40, 100, id: 'a2', bag: [5 => 2]);
        $b1 = $this->synthBlock($b, 1, 30, id: 'b1', bag: [3 => 9, 4 => 3]);
        $b2 = $this->synthBlock($b, 1, 30, id: 'b2', bag: [6 => 1]);

        $oneWay = (new RollupAnalyzer(relRoots: [$dir]))->analyze(
            [$this->plainCluster('c1', [$a1, $b1])],
            [$a1, $a2, $b1],
            [$a, $b],
        );
        $this->assertSame(0, $oneWay['totals']['twin_file_pairs']);
        // the seed-direct leg RAN (and honestly failed the bar): one pair
        // compared, no rescue.
        $this->assertSame(1, $oneWay['totals']['direct_comparisons']);

        // Same corpus with every block IN the cluster: both directions
        // reach 1.0 and the pair becomes a twin.
        $both = (new RollupAnalyzer(relRoots: [$dir]))->analyze(
            [$this->plainCluster('c1', [$a1, $a2, $b1, $b2])],
            [$a1, $a2, $b1, $b2],
            [$a, $b],
        );
        $this->assertSame(1, $both['totals']['twin_file_pairs']);
        $row = $both['twin_pairs'][0];
        $this->assertSame('A.php', $row['a_path']);
        $this->assertSame(1, $row['a_to_b']);   // Num::json collapses 1.0 to int
        $this->assertSame(1, $row['b_to_a']);
        $this->assertFalse($row['seed_direct']);
    }

    public function testSeedDirectTwinWhenNoClusterLinks(): void
    {
        $dir = $this->tmpDir('seed');
        // Same basename in different directories, NO clusters at all — the
        // f08 Vps/Start case. Identical bags: max(J,C)=1.0 ≥ 0.5 carries the
        // claim the clustering never made.
        $a = $this->writePhp($dir . '/mod_x/', 'Widget.php', 1, 12);
        $b = $this->writePhp($dir . '/mod_y/', 'Widget.php', 1, 12);
        $wa = $this->synthBlock($a, 1, 12, bag: [1 => 5, 2 => 5]);
        $wb = $this->synthBlock($b, 1, 12, id: 'wb', bag: [1 => 5, 2 => 5]);

        $rollups = (new RollupAnalyzer(relRoots: [$dir]))->analyze([], [$wa, $wb], [$a, $b]);
        $this->assertSame(1, $rollups['totals']['twin_file_pairs']);
        $this->assertSame(1, $rollups['totals']['direct_comparisons']);
        $row = $rollups['twin_pairs'][0];
        $this->assertTrue($row['seed_direct']);
        $this->assertSame(1, $row['coverage']);
        $this->assertSame(0, $row['a_to_b']);   // no linked cluster carried it
        $this->assertSame(1, $row['seed_best']);
        $this->assertSame([], $row['linked_cluster_ids']);

        // Same seeding, DISSIMILAR bags: the pair stays below the bar.
        $c = $this->writePhp($dir . '/mod_p/', 'Solo.php', 1, 12);
        $d = $this->writePhp($dir . '/mod_q/', 'Solo.php', 1, 12);
        $sa = $this->synthBlock($c, 1, 12, id: 'sa', bag: [7 => 9]);
        $sb = $this->synthBlock($d, 1, 12, id: 'sb', bag: [8 => 9]);
        $rollups2 = (new RollupAnalyzer(relRoots: [$dir]))->analyze(
            [], [$sa, $sb], [$c, $d],
        );
        $this->assertSame(0, $rollups2['totals']['twin_file_pairs']);
        $this->assertSame(1, $rollups2['totals']['direct_comparisons']);
    }

    public function testZeroBlockSeedPairIsDeclaredOnly(): void
    {
        $dir = $this->tmpDir('decl');
        $a = $this->writePhp($dir . '/x/', 'Ghost.php', 1, 10);
        $b = $this->writePhp($dir . '/y/', 'Ghost.php', 1, 10); // never extracted: zero blocks

        // blocks=[] simulates "file produced no comparable blocks" while the
        // scan still lists it (a script file with nothing above min_block_size).
        $rollups = (new RollupAnalyzer(relRoots: [$dir]))->analyze([], [], [$a, $b]);
        $this->assertSame(0, $rollups['totals']['twin_file_pairs']);
        $this->assertSame(0, $rollups['totals']['direct_comparisons']);
        $this->assertSame(1, $rollups['totals']['declared_only_pairs']);
        $row = $rollups['declared_only_pairs'][0];
        $this->assertSame('x/Ghost.php', $row['a_path']);
        $this->assertSame('y/Ghost.php', $row['b_path']);
        $this->assertSame('name', $row['shared']); // different dirs, same basename

        // The stronger seeding: SAME relative path under two scan roots —
        // a mirrored checkout, where the shared reason is 'path' and the
        // root labels carry the distinction.
        $r1 = $this->writePhp($dir . '/r1/', 'Ghost.php', 1, 10);
        $r2 = $this->writePhp($dir . '/r2/', 'Ghost.php', 1, 10);
        $mirror = (new RollupAnalyzer(relRoots: [$dir . '/r1', $dir . '/r2']))->analyze([], [], [$r1, $r2]);
        $this->assertSame(1, $mirror['totals']['declared_only_pairs']);
        $mrow = $mirror['declared_only_pairs'][0];
        $this->assertSame('r1', $mrow['a_root']);
        $this->assertSame('r2', $mrow['b_root']);
        $this->assertSame('Ghost.php', $mrow['a_path']);
        $this->assertSame($mrow['a_path'], $mrow['b_path']); // the mirror IS the same relpath
        $this->assertSame('path', $mrow['shared']);
    }

    public function testDemotedClustersAreExemptFromNumerators(): void
    {
        $dir = $this->tmpDir('demote');
        // Files share a relative path so they enter the candidate set even
        // when the linked (demoted) cluster must not carry them. Bags are
        // dissimilar (J=0, C=0) so the seed-direct leg cannot rescue the
        // pair — the twin row exists ONLY via a non-demoted cluster's
        // coverage. (f08 risk 1: the 55x toArray mega-cluster must not
        // fake-inflate every pair it touches.)
        $a = $this->writePhp($dir . '/m1/', 'Dto.php', 1, 14);
        $b = $this->writePhp($dir . '/m2/', 'Dto.php', 1, 14);

        $demoted = (new RollupAnalyzer(relRoots: [$dir]))->analyze(
            [$this->plainCluster('cDem', [
                $this->declarativeBlock($a, 1, 14, bag: [1 => 9, 2 => 3]),
                $this->declarativeBlock($b, 1, 14, id: 'b1', bag: [3 => 9, 4 => 3]),
            ])],
            [
                $this->declarativeBlock($a, 1, 14, bag: [1 => 9, 2 => 3]),
                $this->declarativeBlock($b, 1, 14, id: 'b1', bag: [3 => 9, 4 => 3]),
            ],
            [$a, $b],
        );
        $this->assertSame(0, $demoted['totals']['twin_file_pairs']);

        // Control: identical geometry, logic members instead of the DTO
        // serializer shape -> factor 1.0, coverage carries the twin.
        $logic = (new RollupAnalyzer(relRoots: [$dir]))->analyze(
            [$this->plainCluster('cLogic', [
                $this->logicBlock($a, 1, 14, bag: [1 => 9, 2 => 3]),
                $this->logicBlock($b, 1, 14, id: 'b1', bag: [3 => 9, 4 => 3]),
            ])],
            [
                $this->logicBlock($a, 1, 14, bag: [1 => 9, 2 => 3]),
                $this->logicBlock($b, 1, 14, id: 'b1', bag: [3 => 9, 4 => 3]),
            ],
            [$a, $b],
        );
        $this->assertSame(1, $logic['totals']['twin_file_pairs']);
        $this->assertSame(['cLogic'], $logic['twin_pairs'][0]['linked_cluster_ids']);
        $this->assertFalse($logic['twin_pairs'][0]['seed_direct']);
    }

    public function testFileRowIdSurvivesLineShiftAndUnrelatedFile(): void
    {
        $dir1 = $this->tmpDir('id1');
        $dir2 = $this->tmpDir('id2');
        $f1 = $this->writePhp($dir1, 'Stable.php', 1, 10);
        // Same declaration block, 5 lines lower, plus an extra unrelated file
        // in the corpus — positional ids would churn here, content-derived
        // ones must not (f08 §2 / BaselineStore's positional-id lesson).
        // The structural hash is CONTENT-derived in production, so the two
        // clones share it verbatim here; ranges and file positions differ.
        $f2 = $this->writePhp($dir2, 'Preamble.php', 1, 5);
        $f3 = $this->writePhp($dir2, 'Stable.php', 6, 15);

        $clone1 = $this->synthBlock($f1, 1, 10);
        $clone1->structuralHash = 'cafe5eed00001234';
        $clone2 = $this->synthBlock($f3, 6, 15);
        $clone2->structuralHash = 'cafe5eed00001234';
        $other = $this->synthBlock($f2, 1, 5);

        $b1 = $this->stableRow([$f1], [$dir1], [$clone1]);
        $b2 = $this->stableRow([$f2, $f3], [$dir2], [$clone2, $other]);
        $this->assertSame($b1['id'], $b2['id']);
    }

    public function testTopFilesCapAndTruncationAreCounted(): void
    {
        $dir = $this->tmpDir('cap');
        $files = [];
        $blocks = [];
        for ($i = 0; $i < RollupAnalyzer::TOP_FILES + 5; $i++) {
            $p = $this->writePhp($dir, sprintf('F%02d.php', $i), 1, 4);
            $files[] = $p;
            $blocks[] = $this->synthBlock($p, 1, 4);
        }
        $rollups = (new RollupAnalyzer(relRoots: [$dir]))->analyze([], $blocks, $files);
        $this->assertCount(RollupAnalyzer::TOP_FILES, $rollups['top_files']);
        $this->assertSame(5, $rollups['files_truncated']);
        $this->assertSame(25, $rollups['totals']['files']);
    }

    public function testSeedPairCapTruncatesDeterministically(): void
    {
        // 91 same-basename files seed C(91,2)=4095 candidates; the cap keeps
        // the lexicographically-first 4000 and counts the 95 lost.
        $dir = $this->tmpDir('seedcap');
        $files = [];
        for ($i = 0; $i < 91; $i++) {
            $files[] = $this->writePhp($dir . '/' . sprintf('root%03d', $i), 'Dup.php', 1, 4);
        }
        $rollups = (new RollupAnalyzer(relRoots: [$dir]))->analyze([], [], $files);
        $this->assertSame(95, $rollups['totals']['seed_candidates_dropped']);

        // Deterministic regardless of input order (all 4095 share a coverage
        // of zero — the blocks exist nowhere — so the declared-only rows are
        // the whole story, and they sort from the capped candidate slice).
        $shuffled = $files;
        shuffle($shuffled);
        $again = (new RollupAnalyzer(relRoots: [$dir]))->analyze([], [], $shuffled);
        $this->assertSame($rollups['declared_only_pairs'], $again['declared_only_pairs']);
        $this->assertSame(
            $rollups['totals']['declared_only_pairs'],
            $again['totals']['declared_only_pairs'],
        );
    }

    public function testSqlTallyInterpolatedDegradesHonestly(): void
    {
        $dir = $this->tmpDir('sql');
        $php = $dir . '/Q.php';
        file_put_contents($php, <<<'CODE'
            <?php
            class Q {
                public function a(): array { return $this->db->query("SELECT id FROM users WHERE x = 1"); }
                public function b(): array { return $this->db->query('DELETE FROM audit'); }
                public function c(): array { return $this->db->query("SELECT * FROM $tbl"); }
                public function d(): array { return $this->db->query('UPDATE t SET a = 1'); }
                public function e(): array { return $this->db->query('plain text not sql'); }
            }
            CODE);
        $rollups = (new RollupAnalyzer(relRoots: [$dir]))->analyze([], [], [$php]);
        $totals = $rollups['totals'];
        // Four recognised statements (SELECT users, DELETE audit, SELECT
        // <interpolated table>, UPDATE t); the interpolated one extracts no
        // table (the variable degrades to null, never an invented table);
        // the prose string is not SQL at all.
        $this->assertSame(4, $totals['sql_refs']);
        $this->assertSame(1, $totals['sql_tableless']);
        $this->assertSame(3, $totals['distinct_sql_tables']); // users, audit, t
    }

    public function testCallSiteSetFollowsCallGraphPrecedent(): void
    {
        $dir = $this->tmpDir('calls');
        $php = $dir . '/C.php';
        file_put_contents($php, <<<'CODE'
            <?php
            class C {
                public function go(): void {
                    $this->m();
                    Helper::s();
                    plain();
                    $x = new Made();
                    $this?->ns();
                    echo $this->prop;
                }
            }
            CODE);
        $rollups = (new RollupAnalyzer(relRoots: [$dir]))->analyze([], [], [$php]);
        // The CallGraph species set: MethodCall | StaticCall | FuncCall.
        // New / NullsafeMethodCall / bare PropertyFetch are NOT call sites
        // there, and the rollup inherits that set.
        $this->assertSame(3, $rollups['totals']['call_sites']);
        $this->assertSame(3, $rollups['totals']['distinct_calls']);
    }

    public function testMirroredDirectoriesGroupTwinPairs(): void
    {
        $dir = $this->tmpDir('mirror');
        $files = [];
        $blocks = [];
        foreach (['mod_a', 'mod_b'] as $mod) {
            foreach (['Foo.php', 'Bar.php'] as $name) {
                $path = $this->writePhp($dir . '/' . $mod, $name, 1, 12);
                $files[] = $path;
                $blocks[] = $this->synthBlock($path, 1, 12, id: $mod . $name, bag: [1 => 5, 2 => 5]);
            }
        }
        $rollups = (new RollupAnalyzer(relRoots: [$dir]))->analyze([], $blocks, $files);
        $this->assertSame(2, $rollups['totals']['twin_file_pairs']);
        $this->assertSame(1, $rollups['totals']['mirrored_directories']);
        $row = $rollups['mirrored_directories'][0];
        $this->assertSame('mirror:mod_a', $row['dir_a']); // root label = basename of the scan root
        $this->assertSame('mirror:mod_b', $row['dir_b']);
        $this->assertSame(2, $row['file_pairs']);
    }

    public function testAnalyzeNeverMutatesClusters(): void
    {
        $dir = $this->tmpDir('purity');
        $a = $this->writePhp($dir, 'A.php', 1, 12);
        $b = $this->writePhp($dir, 'B.php', 1, 12);
        $a1 = $this->synthBlock($a, 1, 12, bag: [1 => 5]);
        $b1 = $this->synthBlock($b, 1, 12, id: 'b1', bag: [1 => 5]);
        $cluster = $this->plainCluster('c1', [$a1, $b1]);
        $before = serialize([$cluster->id, count($cluster->members), $cluster->similarity, $cluster->exact]);
        (new RollupAnalyzer(relRoots: [$dir]))->analyze([$cluster], [$a1, $b1], [$a, $b]);
        $this->assertSame(
            $before,
            serialize([$cluster->id, count($cluster->members), $cluster->similarity, $cluster->exact]),
        );
    }

    // ------------------------------------------------------------------
    // Report/emit wiring
    // ------------------------------------------------------------------

    public function testLegacyReportEmitsNullRollupsPosture(): void
    {
        $payload = (new JsonReporter())->build(new Report(
            0, 0, 0, [], Config::defaults([]),
        ));
        $this->assertNull($payload['summary']['rollups']);
        // Trailing position: every 1.8 key keeps its place.
        $keys = array_keys($payload['summary']);
        $this->assertSame('rollups', end($keys));
    }

    public function testCliTrailerGatesOnRollupsPresence(): void
    {
        $rollups = (new RollupAnalyzer())->analyze([], [], []);
        $withLine = $this->renderCli(new Report(0, 0, 0, [], Config::defaults([]), rollups: $rollups));
        $this->assertStringContainsString('rollups  0 files', $withLine);

        $without = $this->renderCli(new Report(0, 0, 0, [], Config::defaults([])));
        $this->assertStringNotContainsString('rollups ', $without);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * Run the pipeline exactly the way GoldenTest::buildPayload drives it,
     * then hand the final state to the analyzer.
     *
     * @return array{0: list<Cluster>, 1: list<Block>, 2: list<string>}
     */
    private function pipelineFor(string $fixtureDir): array
    {
        $config = new Config(
            paths: [$fixtureDir],
            exclude: Config::defaults([])->exclude,
            lazyAst: false,
            minClusterImpact: 5,
        );
        $state = new PipelineState($config);
        $out = new NullOutput();
        (new ScanningStage())->run($state, $out);
        (new PreprocessStage(useCache: false))->run($state, $out);
        (new ClusterStage(exactOnly: false, useClusterCache: false))->run($state, $out);
        (new RefactorStage(useCache: false))->run($state, $out);
        return [(new Ranker($config->minClusterImpact))->rank($state->clusters), $state->blocks, $state->files];
    }

    /** @param list<string> $files @param list<string> $roots @return array<string,mixed> */
    private function stableRow(array $files, array $roots, array $blocks): array
    {
        $rollups = (new RollupAnalyzer(relRoots: $roots))->analyze([], $blocks, $files);
        foreach ($rollups['top_files'] as $row) {
            if ($row['path'] === 'Stable.php') {
                return $row;
            }
        }
        $this->fail('Stable.php row missing from top_files');
    }

    private function tmpDir(string $tag): string
    {
        $dir = $this->scratch . '/' . $tag;
        mkdir($dir, 0777, true);
        return $dir;
    }

    /**
     * Write a small class file whose methods land at (roughly) the requested
     * line ranges. The synthetic Block ranges are what the coverage maths
     * reads; this file supplies the scan-listed path and honest token facts.
     *
     * @param list<int> $extra flat pairs [start, end] of extra declaration spans
     */
    private function writePhp(string $dir, string $name, int $start = 1, int $end = 10, int ...$extra): string
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $path = $dir . '/' . $name;
        $body = '';
        $ranges = array_merge([[$start, $end]], array_chunk($extra, 2));
        foreach ($ranges as [$s, $e]) {
            $span = max(1, $e - $s);
            $body .= "    function d{$s}_{$e}() { \$x = {$s};\n"
                . str_repeat("        \$y = \$x + 1;\n", max(0, $span - 1))
                . "        return \$y; }\n";
        }
        // Top padding so the first declaration starts at $start.
        $pad = max(0, $start - 3);
        file_put_contents($path, "<?php\nclass K {\n" . str_repeat("// pad\n", $pad) . $body . "}\n");
        return $path;
    }

    /**
     * A synthetic Block: file/range/bag/id only — enough for coverage and
     * seeding maths, which never re-parses blocks (token facts ride the
     * file path via one throwaway AST parse).
     */
    private function synthBlock(string $file, int $s, int $e, string $id = '', array $bag = []): Block
    {
        $b = new Block($file, new LineRange($s, $e), 'method', null, 'K', 'm', new Node\Stmt\Nop());
        $b->id = $id !== '' ? $id : substr(hash('xxh64', $file . ':' . $s . ':' . $e), 0, 16);
        $b->structuralHash = substr(hash('xxh64', $file . '|' . $s . '|' . $e), 0, 16);
        $b->size = max(1, $e - $s + 1);
        $b->ngramBag = $bag === [] ? null : $bag;
        return $b;
    }

    /** Member of a DTO-serializer cluster: demotion HARD (Ranker shape 2). */
    private function declarativeBlock(string $file, int $s, int $e, string $id = '', array $bag = []): Block
    {
        $b = $this->synthBlock($file, $s, $e, $id, $bag);
        $b->canonical = $this->methodNode(
            '<?php class K { public function toArray(): array { return ["a" => $this->x, "b" => $this->y, "c" => $this->z]; } }'
        );
        return $b;
    }

    /** Member of a real-logic cluster: demotion factor 1.0 (kind entropy > 0.5, callees > 1). */
    private function logicBlock(string $file, int $s, int $e, string $id = '', array $bag = []): Block
    {
        $b = $this->synthBlock($file, $s, $e, $id, $bag);
        $b->canonical = $this->methodNode(
            '<?php class K { public function run(): int { $a = $this->load(); $b = compute($a);'
            . ' if ($b > 2) { $b = $this->fix($b); } try { $c = external($b); } catch (E $e) { $c = 0; }'
            . ' $d = other($c); $e = more($d); return merge($b, $c, $d, $e); } }'
        );
        return $b;
    }

    private function methodNode(string $code): Node
    {
        $stmts = (new AstParser())->parseCode($code);
        $this->assertIsArray($stmts);
        $found = (new NodeFinder())->findFirstInstanceOf($stmts, Node\Stmt\ClassMethod::class);
        $this->assertInstanceOf(Node\Stmt\ClassMethod::class, $found);
        return $found;
    }

    /** @param list<Block> $members */
    private function plainCluster(string $id, array $members): Cluster
    {
        return new Cluster($id, $members, 1.0, true);
    }

    private function renderCli(Report $report): string
    {
        $out = new BufferedOutput();
        (new CliReporter())->render($report, $out);
        return $out->fetch();
    }
}
