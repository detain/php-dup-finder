<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Reporting\ClusterScope;
use Phpdup\Util\LineRange;

/**
 * Item 5.1 — cluster scope computation + the per-repo namespace
 * grouping that breaks the scoring double-dip. Synthetic clusters
 * (the RankerTest fake-cluster pattern), no pipeline needed.
 */
final class ClusterScopeTest extends TestCase
{
    public function testSingleRepoClusterIsSingle(): void
    {
        $c = $this->cluster('S', [
            self::member('/r1/A.php', 'Acme\\A', 'repo1'),
            self::member('/r1/B.php', 'Acme\\B', 'repo1'),
        ]);
        $this->assertSame(ClusterScope::SINGLE, ClusterScope::of($c));
        $this->assertFalse(ClusterScope::isCrossRepo($c));
        $this->assertSame(['repo1'], ClusterScope::repos($c));
    }

    public function testTwoDistinctReposIsMulti(): void
    {
        $c = $this->cluster('M', [
            self::member('/r1/A.php', 'Acme\\A', 'repo1'),
            self::member('/r2/B.php', 'Acme\\B', 'repo2'),
        ]);
        $this->assertSame(ClusterScope::MULTI, ClusterScope::of($c));
        $this->assertTrue(ClusterScope::isCrossRepo($c));
        $this->assertSame(['repo1', 'repo2'], ClusterScope::repos($c),
            'repos are sorted — payload order is deterministic');
    }

    public function testUnlabeledMembersNeverFabricateMulti(): void
    {
        $c = $this->cluster('U', [
            self::member('/r1/A.php', 'Acme\\A', 'repo1'),
            self::member('/ghost/B.php', 'Other\\B', ''),
        ]);
        $this->assertSame(ClusterScope::SINGLE, ClusterScope::of($c),
            'an unknown label is not evidence of a second repo');
    }

    public function testEmptyClusterDegradesToSingle(): void
    {
        $c = new Cluster('E', [], 1.0, true);
        $this->assertSame(ClusterScope::SINGLE, ClusterScope::of($c));
        $this->assertSame(0, ClusterScope::maxRepoNamespaceSpread($c));
    }

    public function testNamespaceSpreadGroupsPerRepo(): void
    {
        // The phlix double-dip shape: 3 subsystems × 2 repos. Raw ns
        // count is 6 (>3 ⇒ legacy −0.05/−0.10 penalties fire on the
        // MIRRORED structure itself); the per-repo spread is 3 — the
        // honest "one repo's worth of unrelated namespaces" reading.
        $c = $this->cluster('D', [
            self::member('/r1/A.php', 'Shop\\Admin', 'repo1'),
            self::member('/r1/B.php', 'Shop\\Api',   'repo1'),
            self::member('/r1/C.php', 'Shop\\Cron',  'repo1'),
            self::member('/r2/A.php', 'Shop2\\Admin', 'repo2'),
            self::member('/r2/B.php', 'Shop2\\Api',   'repo2'),
            self::member('/r2/C.php', 'Shop2\\Cron',  'repo2'),
        ]);
        $this->assertCount(6, self::rawNamespaceCount($c), 'fixture: raw spread really is 6');
        $this->assertSame(3, ClusterScope::maxRepoNamespaceSpread($c));
        $this->assertTrue(ClusterScope::isCrossRepo($c));
    }

    public function testSpreadDegradesExactlyToLegacyCountForOneRepo(): void
    {
        $c = $this->cluster('1', [
            self::member('/r1/A.php', 'Acme\\A', 'repo1'),
            self::member('/r1/B.php', 'Acme\\B', 'repo1'),
            self::member('/r1/C.php', 'Acme\\C', 'repo1'),
            self::member('/r1/D.php', 'Acme\\D', 'repo1'),
        ]);
        $this->assertSame(
            count(self::rawNamespaceCount($c)),
            ClusterScope::maxRepoNamespaceSpread($c),
            'single-repo clusters must read the same number the legacy path used',
        );
    }

    public function testUnlabeledMembersShareTheirOwnSpreadBucket(): void
    {
        $c = $this->cluster('W', [
            self::member('/r1/A.php', 'Acme\\A', ''),
            self::member('/r1/B.php', 'Acme\\B', ''),
            self::member('/r2/C.php', 'Other\\C', 'repo2'),
        ]);
        $this->assertSame(2, ClusterScope::maxRepoNamespaceSpread($c),
            "the '' bucket counts (max of 2 vs 1) — grouping is about the densest repo");
    }

    public function testFilterAllIsIdentity(): void
    {
        $clusters = [$this->cluster('X', [self::member('/a/A.php', 'A', 'a')])];
        $this->assertSame($clusters, ClusterScope::filter($clusters, ClusterScope::FILTER_ALL));
    }

    public function testFilterSingleMultiAndLabel(): void
    {
        $single = $this->cluster('S', [
            self::member('/a/A.php', 'A', 'alpha'),
            self::member('/a/B.php', 'B', 'alpha'),
        ]);
        $multi  = $this->cluster('M', [
            self::member('/a/C.php', 'C', 'alpha'),
            self::member('/b/D.php', 'D', 'beta'),
        ]);
        $clusters = [$single, $multi];

        $this->assertSame(['S'], array_map(fn(Cluster $c) => $c->id,
            ClusterScope::filter($clusters, ClusterScope::FILTER_SINGLE)));
        $this->assertSame(['M'], array_map(fn(Cluster $c) => $c->id,
            ClusterScope::filter($clusters, ClusterScope::FILTER_MULTI)));
        $this->assertSame(['S', 'M'], array_map(fn(Cluster $c) => $c->id,
            ClusterScope::filter($clusters, 'alpha')),
            'a repo label keeps every cluster touching that repo');
        $this->assertSame(['M'], array_map(fn(Cluster $c) => $c->id,
            ClusterScope::filter($clusters, 'beta')));
        $this->assertSame([], ClusterScope::filter($clusters, 'no-such-repo'));
    }

    // ── output-S42 (php-dup-finder-output-059) — run-scoped spread memo ─
    // Each pin names the arm it guards (S15-vacuity discipline); the
    // walk counter is observed as a DELTA after this test's own
    // beginRun(), so no assertion depends on leftover window state
    // from earlier classes in the process.

    public function testSpreadMemoServesEveryAccessorFromOneWalkPerRun(): void
    {
        // Guarded arm: spread() memo hit + compute() attach. Without
        // the memo the last counter assert reads 5 (the finding's
        // repeat pattern), not 1.
        $c = $this->cluster('MEMO', [
            self::member('/r1/A.php', 'Acme\\A', 'repo1'),
            self::member('/r1/B.php', 'Acme\\B', 'repo1'),
            self::member('/r2/A.php', 'Other\\A', 'repo2'),
            self::member('/g/B.php', 'Ghost\\B', ''),
        ]);
        ClusterScope::beginRun();
        $this->assertSame(0, ClusterScope::memberWalks(), 'beginRun() zeroes the walk counter');

        $repos  = ClusterScope::repos($c);
        $this->assertSame(1, ClusterScope::memberWalks(), 'the first read walks the members once');
        $scope  = ClusterScope::of($c);
        $cross  = ClusterScope::isCrossRepo($c);
        $spread = ClusterScope::maxRepoNamespaceSpread($c);
        $byRepo = ClusterScope::namespacesByRepo($c);
        $this->assertSame(1, ClusterScope::memberWalks(),
            'every later accessor rides the memo — one spread computation per cluster per run');

        // The memoized answers are the real semantics, not vacuous reads.
        $this->assertSame(['repo1', 'repo2'], $repos);
        $this->assertSame(ClusterScope::MULTI, $scope);
        $this->assertTrue($cross);
        $this->assertSame(2, $spread);
        $this->assertSame(
            [
                'repo1' => ['Acme\\A' => true, 'Acme\\B' => true],
                'repo2' => ['Other\\A' => true],
                ''      => ['Ghost\\B' => true],
            ],
            $byRepo,
        );
    }

    public function testMemoizedReadsEqualFreshComputesOnATwinCluster(): void
    {
        // Guarded arm: memo() and the direct compute() serve the SAME
        // values — two structurally identical clusters (one object
        // first, then its freshly built twin, so the twin's first read
        // is an unavoidable cold compute) agree on every accessor.
        $members = static fn(): array => [
            self::member('/r1/A.php', 'Acme\\A', 'repo1'),
            self::member('/r1/B.php', 'Acme\\B', 'repo1'),
            self::member('/r2/A.php', 'Other\\A', 'repo2'),
        ];
        $first = $this->cluster('TWIN1', $members());
        ClusterScope::beginRun();
        $firstReads = [
            'repos'    => ClusterScope::repos($first),
            'of'       => ClusterScope::of($first),
            'cross'    => ClusterScope::isCrossRepo($first),
            'spread'   => ClusterScope::maxRepoNamespaceSpread($first),
            'byRepo'   => ClusterScope::namespacesByRepo($first),
        ];

        $twin  = $this->cluster('TWIN2', $members());
        $twins = [
            'repos'  => ClusterScope::repos($twin),
            'of'     => ClusterScope::of($twin),
            'cross'  => ClusterScope::isCrossRepo($twin),
            'spread' => ClusterScope::maxRepoNamespaceSpread($twin),
            'byRepo' => ClusterScope::namespacesByRepo($twin),
        ];
        $this->assertSame($firstReads, $twins, 'memo-served reads == fresh cold-compute reads');
        $this->assertSame(2, ClusterScope::memberWalks(), 'still one walk per cluster, not per read');
    }

    public function testRestampedMembersRecomputeAfterWindowSwap(): void
    {
        // Guarded arm: the beginRun() invalidation — THE determinism
        // contract. Neutering the window swap leaves run 2 answering
        // this cluster from run 1's memo and this pin goes RED on the
        // stale MULTI. Mirrors the live hazard exactly: cluster-cache
        // replay hands the SAME Cluster objects to the next run, whose
        // stampAll mutates members before the next report reads them.
        $c = $this->cluster('RESTAMP', [
            self::member('/r1/A.php', 'Acme\\A', 'repo1'),
            self::member('/r2/B.php', 'Acme\\B', 'repo2'),
        ]);
        ClusterScope::beginRun();
        $this->assertSame(ClusterScope::MULTI, ClusterScope::of($c),
            'run 1 reads the roots it was stamped with');

        // The restamp itself (Block::$repo is public-mutable by design —
        // RepoLabels::stampAll assigns it the same way).
        $c->members[0]->repo = 'solo';
        $c->members[1]->repo = 'solo';

        ClusterScope::beginRun(); // run 2 opens
        $this->assertSame(ClusterScope::SINGLE, ClusterScope::of($c),
            'no spread of the previous run leaks across the window boundary');
        $this->assertSame(['solo'], ClusterScope::repos($c));
        $this->assertSame(1, ClusterScope::memberWalks(),
            'the swapped window recomputes once, then re-memoizes');
    }

    public function testScopeFilterConsumesTheMemo(): void
    {
        // Guarded arm: the label branch reads the spread struct — the
        // finding's 4th walk. Routing it back to a raw member foreach
        // makes the total-walks assert read >1 and goes RED.
        $c = $this->cluster('FILTER', [
            self::member('/r1/A.php', 'Acme\\A', 'repo1'),
            self::member('/g/B.php', 'Ghost\\B', ''),
            self::member('/r2/A.php', 'Other\\A', 'repo2'),
        ]);
        ClusterScope::beginRun();
        ClusterScope::of($c);
        $this->assertSame(1, ClusterScope::memberWalks(), 'pre-read walks once');

        $this->assertSame([$c], ClusterScope::filter([$c], 'repo1'));
        $this->assertSame([], ClusterScope::filter([$c], 'no-such-repo'));
        $this->assertSame([$c], ClusterScope::filter([$c], ''),
            "literal '' scope keeps the legacy unlabeled-member reading");
        $this->assertSame([$c], ClusterScope::filter([$c], ClusterScope::FILTER_MULTI));
        $this->assertSame([], ClusterScope::filter([$c], ClusterScope::FILTER_SINGLE));
        $this->assertSame(1, ClusterScope::memberWalks(),
            'every filter arm rides the memo — zero extra walks');
    }

    public function testFilterAndAccessorsCarryNoMemberWalkSite(): void
    {
        // Guarded arm (structural, S34 doctrine): output-059's cure is
        // that compute() is the ONLY method in ClusterScope that walks
        // a cluster's members. A raw member walk re-added to any other
        // reader (even without touching the counter) would resurrect
        // the repeat walk invisibly to every delta assert — this pin
        // catches it at the source level, where a counter alone cannot
        // reach. The scan is NAME-INDEPENDENT (r1 M-1 cure): every
        // method the class declares is checked, so a walk smuggled
        // into a brand-new method — reachable via one indirection from
        // a scanned reader, counter-blind behind its own loop — flips
        // this pin regardless of what the method is called or who
        // calls it. compute() keeps its exactly-one-site assertion.
        $r     = new \ReflectionClass(ClusterScope::class);
        $lines = \file((string) $r->getFileName());
        foreach ($r->getMethods() as $method) {
            $body = \implode('', \array_slice(
                $lines,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1,
            ));
            if ($method->getName() === 'compute') {
                $this->assertSame(
                    1,
                    \substr_count($body, 'foreach ($c->members'),
                    'compute() is the single member-walk site of the class',
                );
                continue;
            }
            $this->assertStringNotContainsString(
                '->members',
                $body,
                'ClusterScope::' . $method->getName()
                    . '() must read through the spread memo, never walk members itself',
            );
        }
    }

    public function testRealConsumersPayOneWalkPerClusterPerRun(): void
    {
        // Guarded arm: the FULL consumer chain (Ranker::confidenceOf +
        // SafetyScorer::score, both spread readers) shares one spread
        // per cluster instead of the pre-S42 2-3 walks per scorer.
        $multi = $this->cluster('RCM', [
            self::member('/r1/A.php', 'Acme\\A', 'repo1'),
            self::member('/r1/B.php', 'Acme\\B', 'repo1'),
            self::member('/r2/A.php', 'Other\\A', 'repo2'),
            self::member('/r2/B.php', 'Other\\B', 'repo2'),
        ]);
        $single = $this->cluster('RCS', [
            self::member('/r1/C.php', 'Acme\\C', 'repo1'),
            self::member('/r1/D.php', 'Acme\\D', 'repo1'),
        ]);

        ClusterScope::beginRun();
        (new \Phpdup\Reporting\Ranker(
            minImpact: 0,
            scoreMode: \Phpdup\Cli\Config::SCORE_MODE_PER_REPO,
        ))->rank([$multi, $single]);
        $this->assertSame(2, ClusterScope::memberWalks(),
            'per-repo rank + safety across two clusters: one walk each');

        ClusterScope::beginRun();
        (new \Phpdup\Reporting\Ranker(
            minImpact: 0,
            scoreMode: \Phpdup\Cli\Config::SCORE_MODE_TYPED,
        ))->rank([$multi, $single]);
        $this->assertSame(2, ClusterScope::memberWalks(), 'typed arm identical');

        // Non-vacuity control: the GLOBAL (default) path runs the legacy
        // inline math and must enter the memo ZERO times — proves the
        // counter is not just tracking every rank call, and that the
        // memoized path did not silently widen the default scoring route.
        ClusterScope::beginRun();
        (new \Phpdup\Reporting\Ranker(
            minImpact: 0,
            scoreMode: \Phpdup\Cli\Config::SCORE_MODE_GLOBAL,
        ))->rank([$multi, $single]);
        $this->assertSame(0, ClusterScope::memberWalks(),
            'global score mode never touches the spread memo (legacy math untouched)');
    }

    public function testReadsAreIdenticalAcrossConsecutiveWindowSwaps(): void
    {
        // Guarded arm: determinism of the whole memo cycle — the same
        // unmuted cluster read across three window swaps answers the
        // SAME values (including array key order), i.e. a recompute can
        // never disagree with the memo it replaced.
        $c = $this->cluster('DETERMINISM', [
            self::member('/r1/A.php', 'Acme\\A', 'repo1'),
            self::member('/r1/B.php', 'Acme\\B', 'repo1'),
            self::member('/r2/A.php', 'Other\\A', 'repo2'),
        ]);
        $reads = [];
        $walks = [];
        for ($run = 0; $run < 3; $run++) {
            ClusterScope::beginRun();
            $reads[] = [
                'repos'  => ClusterScope::repos($c),
                'of'     => ClusterScope::of($c),
                'spread' => ClusterScope::maxRepoNamespaceSpread($c),
                'byRepo' => ClusterScope::namespacesByRepo($c),
            ];
            $walks[] = ClusterScope::memberWalks();
        }
        $this->assertSame($reads[0], $reads[1], 'run 2 recompute agrees byte-for-byte');
        $this->assertSame($reads[1], $reads[2], 'run 3 likewise — key order included');
        $this->assertSame([1, 1, 1], $walks,
            'each swapped window pays its one walk — no cross-window serving, no repeat walks within one');
    }

    private function cluster(string $id, array $members): Cluster
    {
        return new Cluster($id, $members, 1.0, true);
    }

    // ── Item 5.1 (ladder leg) ──────────────────────────────────────────

    public function testLadderCoversAllFourRungs(): void
    {
        $intraFile = $this->cluster('L1', [
            self::member('/r1/Same.php', 'Acme\\A', 'repo1', 'acme/pkg'),
            self::member('/r1/Same.php', 'Acme\\B', 'repo1', 'acme/pkg'),
        ]);
        $intraPkg = $this->cluster('L2', [
            self::member('/r1/A.php', 'Acme\\A', 'repo1', 'acme/pkg'),
            self::member('/r1/B.php', 'Acme\\B', 'repo1', 'acme/pkg'),
        ]);
        $crossPkg = $this->cluster('L3', [
            self::member('/r1/pkgA/A.php', 'PkgA\\A', 'repo1', 'acme/pkg-a'),
            self::member('/r1/pkgB/B.php', 'PkgB\\B', 'repo1', 'acme/pkg-b'),
        ]);
        $crossRepo = $this->cluster('L4', [
            self::member('/r1/A.php', 'Acme\\A', 'repo1', 'acme/one'),
            self::member('/r2/B.php', 'Acme\\B', 'repo2', 'acme/two'),
        ]);
        $this->assertSame(ClusterScope::LADDER_INTRA_FILE, ClusterScope::ladder($intraFile));
        $this->assertSame(ClusterScope::LADDER_INTRA_PACKAGE, ClusterScope::ladder($intraPkg));
        $this->assertSame(ClusterScope::LADDER_CROSS_PACKAGE, ClusterScope::ladder($crossPkg));
        $this->assertSame(ClusterScope::LADDER_CROSS_REPO, ClusterScope::ladder($crossRepo));
        $this->assertSame(
            [
                ClusterScope::LADDER_INTRA_FILE,
                ClusterScope::LADDER_INTRA_PACKAGE,
                ClusterScope::LADDER_CROSS_PACKAGE,
                ClusterScope::LADDER_CROSS_REPO,
            ],
            ClusterScope::LADDER_ORDER,
        );
    }

    public function testLadderCoarsestRungWins(): void
    {
        // Two repos AND two packages AND one file each: the ladder is
        // coarsest-first — cross-repo answers, never cross-package.
        $c = $this->cluster('LW', [
            self::member('/r1/A.php', 'Acme\\A', 'repo1', 'acme/one'),
            self::member('/r2/B.php', 'Acme\\B', 'repo2', 'acme/two'),
        ]);
        $this->assertSame(ClusterScope::LADDER_CROSS_REPO, ClusterScope::ladder($c));
        // Repo spread over one package label is still cross-repo.
        $samePkg = $this->cluster('LW2', [
            self::member('/r1/A.php', 'Acme\\A', 'repo1', 'acme/one'),
            self::member('/r2/B.php', 'Acme\\B', 'repo2', 'acme/one'),
        ]);
        $this->assertSame(ClusterScope::LADDER_CROSS_REPO, ClusterScope::ladder($samePkg));
    }

    public function testLadderUnlabeledMembersFabricateNothing(): void
    {
        // Mirror of the isCrossRepo honesty law: empty labels join no
        // second bucket. Multi-file, no repo, no package => intra-package
        // (files sit side by side — the strongest evidence-backed claim),
        // never a speculative cross-*.
        $c = $this->cluster('LU', [
            self::member('/r1/A.php', 'Acme\\A', '', ''),
            self::member('/r1/B.php', 'Acme\\B', '', ''),
        ]);
        $this->assertSame(ClusterScope::LADDER_INTRA_PACKAGE, ClusterScope::ladder($c));
        $oneFile = $this->cluster('LU2', [
            self::member('/r1/Same.php', 'Acme\\A', '', ''),
            self::member('/r1/Same.php', 'Acme\\B', '', ''),
        ]);
        $this->assertSame(ClusterScope::LADDER_INTRA_FILE, ClusterScope::ladder($oneFile));
        // Half-labeled: one repo, no packages, two files => intra-package.
        $half = $this->cluster('LU3', [
            self::member('/r1/A.php', 'Acme\\A', 'repo1', ''),
            self::member('/r1/B.php', 'Acme\\B', 'repo1', ''),
        ]);
        $this->assertSame(ClusterScope::LADDER_INTRA_PACKAGE, ClusterScope::ladder($half));
    }

    public function testCountsAreAlwaysFourKeysWithSumIdentity(): void
    {
        $clusters = [
            $this->cluster('C1', [self::member('/f/A.php', 'N', 'r1', 'p')]),
            $this->cluster('C2', [
                self::member('/f/A.php', 'N', 'r1', 'p'),
                self::member('/f/B.php', 'N', 'r1', 'p'),
            ]),
            $this->cluster('C3', [
                self::member('/f/pkgA/A.php', 'N', 'r1', 'p-a'),
                self::member('/f/pkgB/B.php', 'N', 'r1', 'p-b'),
            ]),
            $this->cluster('C4', [
                self::member('/r1/A.php', 'N', 'r1', ''),
                self::member('/r2/B.php', 'N', 'r2', ''),
            ]),
        ];
        $counts = ClusterScope::counts($clusters);
        $this->assertSame(ClusterScope::LADDER_ORDER, array_keys($counts),
            'all four keys present in doc order, always');
        $this->assertSame(
            ['intra-file' => 1, 'intra-package' => 1, 'cross-package' => 1, 'cross-repo' => 1],
            $counts,
        );
        $this->assertCount(array_sum($counts), $clusters, 'sum identity');

        $empty = ClusterScope::counts([]);
        $this->assertSame(ClusterScope::LADDER_ORDER, array_keys($empty));
        $this->assertSame([0, 0, 0, 0], array_values($empty),
            'an empty reported set is a measured zero census, never a missing key');
    }

    public function testCountsAreListOrderIndependent(): void
    {
        $a = $this->cluster('OA', [
            self::member('/r1/A.php', 'N', 'r1', ''),
            self::member('/r2/B.php', 'N', 'r2', ''),
        ]);
        $b = $this->cluster('OB', [self::member('/r1/A.php', 'N', 'r1', 'p')]);
        $this->assertSame(ClusterScope::counts([$a, $b]), ClusterScope::counts([$b, $a]),
            'the census is deterministic in list content, not order');
    }

    public function testFilterAcceptsLadderWordsAndCrossRepoEqualsMulti(): void
    {
        $intraFile  = $this->cluster('FI', [self::member('/f/A.php', 'N', 'r1', 'p')]);
        $intraPkg   = $this->cluster('FP', [
            self::member('/f/A.php', 'N', 'r1', 'p'),
            self::member('/f/B.php', 'N', 'r1', 'p'),
        ]);
        $crossPkg   = $this->cluster('FX', [
            self::member('/f/a/A.php', 'N', 'r1', 'p-a'),
            self::member('/f/b/B.php', 'N', 'r1', 'p-b'),
        ]);
        $crossRepo  = $this->cluster('FR', [
            self::member('/r1/A.php', 'N', 'r1', 'p-a'),
            self::member('/r2/B.php', 'N', 'r2', 'p-b'),
        ]);
        $all = [$intraFile, $intraPkg, $crossPkg, $crossRepo];

        $this->assertSame([$intraFile], ClusterScope::filter($all, ClusterScope::LADDER_INTRA_FILE));
        $this->assertSame([$intraPkg], ClusterScope::filter($all, ClusterScope::LADDER_INTRA_PACKAGE));
        $this->assertSame([$crossPkg], ClusterScope::filter($all, ClusterScope::LADDER_CROSS_PACKAGE));
        $this->assertSame([$crossRepo], ClusterScope::filter($all, ClusterScope::LADDER_CROSS_REPO));
        $this->assertSame(
            ClusterScope::filter($all, ClusterScope::FILTER_MULTI),
            ClusterScope::filter($all, ClusterScope::LADDER_CROSS_REPO),
            'cross-repo is the spelled form of multi — identical selection',
        );
    }

    public function testLadderWordsBeatRepoLabelsInFilter(): void
    {
        // Precedence pin: a scan root LITERALLY named 'cross-repo' cannot
        // shadow the word's meaning. The lone cluster lives in one repo
        // called 'cross-repo'; the word filter drops it (it is not
        // cross-repo in ladder terms), while the same-word-as-label route
        // is unreachable — exactly the shadow the doc grammar had to close.
        $shadow = $this->cluster('SH', [
            self::member('/vol/cross-repo/A.php', 'N', 'cross-repo', 'x/y'),
            self::member('/vol/cross-repo/B.php', 'N', 'cross-repo', 'x/y'),
        ]);
        $kept = ClusterScope::filter([$shadow], ClusterScope::LADDER_CROSS_REPO);
        $this->assertSame([], $kept,
            "the closed-set word wins over the same-spelled repo label — a single-repo cluster never passes 'cross-repo'");
        // The label is still reachable under a non-shadowed word context:
        // 'x/y' package, one repo => intra-package ladder, selectable.
        $this->assertSame([$shadow], ClusterScope::filter([$shadow], ClusterScope::LADDER_INTRA_PACKAGE));
    }

    public function testLadderAndCountsRideTheSpreadMemo(): void
    {
        // Walk-count law: ladder()/counts() share the single compute()
        // per cluster per run — adding the item-5.1 legs must not widen
        // the pre-S42 one-walk-per-cluster-per-run budget.
        $c = $this->cluster('WM', [
            self::member('/f/A.php', 'N', 'r1', 'p-a'),
            self::member('/f/B.php', 'N', 'r1', 'p-b'),
        ]);
        ClusterScope::beginRun();
        $this->assertSame(ClusterScope::LADDER_CROSS_PACKAGE, ClusterScope::ladder($c));
        $this->assertSame(1, ClusterScope::memberWalks());
        $this->assertSame(ClusterScope::SINGLE, ClusterScope::of($c));
        ClusterScope::counts([$c]);
        ClusterScope::filter([$c], ClusterScope::LADDER_INTRA_FILE);
        $this->assertSame(1, ClusterScope::memberWalks(),
            'ladder + of + counts + filter in one run window: still exactly one member walk');
    }

    /** @return array<string, true> */
    private static function rawNamespaceCount(Cluster $c): array
    {
        $namespaces = [];
        foreach ($c->members as $m) {
            $namespaces[$m->namespace ?? ''] = true;
        }
        return $namespaces;
    }

    private static function member(string $file, string $namespace, string $repo, string $package = ''): Block
    {
        $b = new Block(
            file:      $file,
            range:     new LineRange(1, 10),
            kind:      'method',
            namespace: $namespace,
            class:     null,
            name:      'm',
            ast:       new Node\Stmt\Nop(),
        );
        $b->repo     = $repo;
        $b->scope    = $repo;
        $b->package  = $package;
        return $b;
    }
}
