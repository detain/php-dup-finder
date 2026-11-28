<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Refactor;

use PhpParser\Node\Stmt\Nop;
use Phpdup\Census\HelperCopy;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Refactor\Hole;
use Phpdup\Refactor\PlacementAdvisor;
use Phpdup\Util\LineRange;
use PHPUnit\Framework\TestCase;

/**
 * Item 5.3 (W3) — the ARMED depth of the placement advisor. Everything
 * here rides the 3.2 adoption-ledger arm ({@see
 * PlacementAdvisor::armAdoptionProbe()}); the mirror conservative file
 * (PlacementAdvisorTest) keeps the byte-frozen default payloads pinned.
 *
 * Rows are hand-built HelperCopy VOs (the ledger is cluster-independent
 * by construction, so its rows are honest test inputs); the end-to-end
 * ReportStage arm + live corpus run prove the wiring, not this file.
 */
final class PlacementAdvisorArmedTest extends TestCase
{
    protected function tearDown(): void
    {
        // The arm is process-wide by design — disarm or the next test
        // file inherits a full-mode advisor.
        PlacementAdvisor::armAdoptionProbe(null);
        foreach ($this->tmpDirs as $dir) {
            $this->rrmdir($dir);
        }
        $this->tmpDirs = [];
    }

    // ---- (b) first-class forms: trait / base_class ----------------------

    public function testArmedSameClassMembersBecomeATraitLift(): void
    {
        PlacementAdvisor::armAdoptionProbe([]);
        $cluster = $this->cluster(
            $this->member('/r/src/A.php', 'Acme\\X', 'Alpha', 'run'),
            $this->member('/r/src/B.php', 'Acme\\X', 'Alpha', 'run'),
        );
        $cluster->holes[] = new Hole('__P0', 'identifier', ['$u', '$v']);

        $advice = (new PlacementAdvisor([]))->advise($cluster);

        $this->assertSame('trait', $advice['shape']);
        $this->assertStringContainsString('parameterized trait lift', $advice['rationale']);
        // The shape value IS the advice: the lift sentence must not
        // duplicate it under the same roof.
        $this->assertStringNotContainsString('base-class or trait lift', $advice['rationale']);
    }

    public function testArmedSubtreeFamilyCarriesTheTraitCarrierWord(): void
    {
        PlacementAdvisor::armAdoptionProbe([]);
        $cluster = $this->cluster(
            $this->member('/r/src/A.php', 'Acme\\X', 'Alpha', 'run'),
            $this->member('/r/src/B.php', 'Acme\\X', 'Alpha', 'run'),
        );
        $cluster->holes[] = new Hole('__P0', 'subtree', ['if ($s > 1)', 'for (;;)']);

        $advice = (new PlacementAdvisor([]))->advise($cluster);

        $this->assertSame('trait', $advice['shape']);
        $this->assertStringContainsString('extract as a trait taking the differing regions', $advice['rationale']);
    }

    public function testArmedSiblingClassesWithOneNameBecomeABaseClassPullUp(): void
    {
        PlacementAdvisor::armAdoptionProbe([]);
        $cluster = $this->cluster(
            $this->member('/r/src/A.php', 'Acme\\X', 'Alpha', 'run'),
            $this->member('/r/src/B.php', 'Acme\\X', 'Beta', 'run'),
        );
        $cluster->holes[] = new Hole('__P0', 'identifier', ['$u', '$v']);

        $advice = (new PlacementAdvisor([]))->advise($cluster);

        $this->assertSame('base_class', $advice['shape']);
        $this->assertStringContainsString('parameterized base class lift', $advice['rationale']);
        $this->assertStringContainsString('Shared namespace root: Acme\\X.', $advice['rationale']);
    }

    public function testDifferentMethodNamesStayInTheFunctionFamily(): void
    {
        PlacementAdvisor::armAdoptionProbe([]);
        $cluster = $this->cluster(
            $this->member('/r/src/A.php', 'Acme\\X', 'Alpha', 'run'),
            $this->member('/r/src/B.php', 'Acme\\X', 'Beta', 'walk'),
        );
        $cluster->holes[] = new Hole('__P0', 'identifier', ['$u', '$v']);

        $advice = (new PlacementAdvisor([]))->advise($cluster);

        $this->assertSame('parameterized_function', $advice['shape']);
        $this->assertStringNotContainsString('base class', $advice['rationale']);
    }

    public function testAllLiteralHolesStayTableDrivenEvenArmed(): void
    {
        // Doc rule: "if every hole is a literal, emit the observed
        // values as a data table and say table-driven" — the table
        // outranks any member-lift form.
        PlacementAdvisor::armAdoptionProbe([]);
        $cluster = $this->cluster(
            $this->member('/r/src/A.php', 'Acme\\X', 'Alpha', 'run'),
            $this->member('/r/src/B.php', 'Acme\\X', 'Alpha', 'run'),
        );
        $cluster->holes[] = new Hole('__P0', 'literal', ['10', '20']);

        $advice = (new PlacementAdvisor([]))->advise($cluster);

        $this->assertSame('data_table', $advice['shape']);
        $this->assertStringContainsString('lookup table', $advice['rationale']);
    }

    public function testZeroHoleMoveKeepsPrecedenceUnderArm(): void
    {
        PlacementAdvisor::armAdoptionProbe([]);
        $cluster = $this->cluster(
            $this->member('/r/src/A.php', 'Acme\\X', 'Alpha', 'run'),
            $this->member('/r/src/B.php', 'Acme\\X', 'Alpha', 'run'),
        );

        $advice = (new PlacementAdvisor([]))->advise($cluster);

        $this->assertSame(['shape', 'rationale', 'same_host', 'lca_namespace', 'host_packages'], array_keys($advice));
        $this->assertSame('move', $advice['shape']);
    }

    public function testDisarmedAdvisorKeepsConservativeShapes(): void
    {
        PlacementAdvisor::armAdoptionProbe(null);
        $sameClass = $this->cluster(
            $this->member('/r/src/A.php', 'Acme\\X', 'Alpha', 'run'),
            $this->member('/r/src/B.php', 'Acme\\X', 'Alpha', 'run'),
        );
        $sameClass->holes[] = new Hole('__P0', 'identifier', ['$u', '$v']);

        $advice = (new PlacementAdvisor([]))->advise($sameClass);
        $this->assertSame('parameterized_function', $advice['shape']);
        $this->assertStringContainsString('base-class or trait lift', $advice['rationale']);
    }

    // ---- (a) canonical-target probe --------------------------------------

    public function testCanonicalProbeNamesExternalHelperWhenEveryMemberIsACopy(): void
    {
        PlacementAdvisor::armAdoptionProbe([
            $this->row('Acme\\Env::isDev', '/r/src/Env.php', 10, 13, 5, 1, ['/r/src/A.php' => 2, '/r/src/B.php' => 2], ['/r/src/A.php:16', '/r/src/B.php:17']),
        ]);
        $cluster = $this->cluster(
            $this->member('/r/src/A.php', 'Acme\\X', 'Alpha', 'run', 14, 20),
            $this->member('/r/src/B.php', 'Acme\\X', 'Beta', 'run', 14, 20),
        );
        $cluster->holes[] = new Hole('__P0', 'identifier', ['$u', '$v']);

        $advice = (new PlacementAdvisor([]))->advise($cluster);

        $this->assertSame([
            'label'         => 'Acme\\Env::isDev',
            'file'          => '/r/src/Env.php',
            'line'          => 10,
            'inline_copies' => 4,
            'is_member'     => false,
        ], $advice['canonical']);
        $this->assertStringContainsString(
            'Canonical target: Acme\\Env::isDev (/r/src/Env.php:10) already implements this body (4 inline copies in the 3.2 ledger)',
            $advice['rationale'],
        );
    }

    public function testCanonicalProbeFlagsTheDefinitionWhenItIsAClusterMember(): void
    {
        // The doc's other arm: "a member in a shared namespace that the
        // others could call" — the cluster already contains the canonical.
        PlacementAdvisor::armAdoptionProbe([
            $this->row('Acme\\X\\Alpha::flag', '/r/src/A.php', 15, 18, 5, 1, ['/r/src/B.php' => 3], ['/r/src/B.php:16', '/r/src/B.php:30', '/r/src/B.php:44']),
        ]);
        $cluster = $this->cluster(
            $this->member('/r/src/A.php', 'Acme\\X', 'Alpha', 'run', 14, 20), // contains the def
            $this->member('/r/src/B.php', 'Acme\\X', 'Beta', 'run', 14, 50),  // carries 3 copies
        );
        $cluster->holes[] = new Hole('__P0', 'identifier', ['$u', '$v']);

        $advice = (new PlacementAdvisor([]))->advise($cluster);

        $this->assertTrue($advice['canonical']['is_member']);
        $this->assertStringContainsString('is itself one of the members — keep it', $advice['rationale']);
    }

    public function testCanonicalProbeStaysSilentOnPartialCoverage(): void
    {
        // A member the ledger cannot account for (its file carries no
        // recorded copy) means the helper is not the cluster's canonical
        // — stay conservative, name nothing.
        PlacementAdvisor::armAdoptionProbe([
            $this->row('Acme\\Env::isDev', '/r/src/Env.php', 10, 13, 3, 1, ['/r/src/A.php' => 1], ['/r/src/A.php:16']),
        ]);
        $cluster = $this->cluster(
            $this->member('/r/src/A.php', 'Acme\\X', 'Alpha', 'run', 14, 20),
            $this->member('/r/src/B.php', 'Acme\\X', 'Beta', 'run', 14, 20),
        );
        $cluster->holes[] = new Hole('__P0', 'identifier', ['$u', '$v']);

        $advice = (new PlacementAdvisor([]))->advise($cluster);

        $this->assertArrayNotHasKey('canonical', $advice);
        $this->assertStringNotContainsString('Canonical target', $advice['rationale']);
    }

    public function testLineExactJoinWhenSampleIsComplete(): void
    {
        // File is listed, but the single recorded copy line sits OUTSIDE
        // the member's range — the helper pattern is not this member's.
        PlacementAdvisor::armAdoptionProbe([
            $this->row('Acme\\Env::isDev', '/r/src/Env.php', 10, 13, 3, 1, ['/r/src/A.php' => 1, '/r/src/B.php' => 1], ['/r/src/A.php:99', '/r/src/B.php:17']),
        ]);
        $cluster = $this->cluster(
            $this->member('/r/src/A.php', 'Acme\\X', 'Alpha', 'run', 14, 20),
            $this->member('/r/src/B.php', 'Acme\\X', 'Beta', 'run', 14, 20),
        );
        $cluster->holes[] = new Hole('__P0', 'identifier', ['$u', '$v']);

        $advice = (new PlacementAdvisor([]))->advise($cluster);

        $this->assertArrayNotHasKey('canonical', $advice);
    }

    public function testFileLevelJoinWhenSampleIsCapped(): void
    {
        // 19 total sites vs 10 sampled copies: the row is capped, so
        // file-level attribution is the honest ceiling — and it names.
        // The asymmetry is the pin: A.php's range CONTAINS a sampled
        // line (:16 sits inside 14-20) while B.php joins on file-count
        // ALONE (every sampled B line sits outside 14-20). Flip the
        // join to line-exact regardless of capping and B can no longer
        // be accounted for — this test fails on the B member, not the
        // A one. The line-exact-when-complete sibling test pins the
        // other direction, so together the pair proves the arms are
        // distinct and the gate is the sample-count, not the member.
        $locs = [
            '/r/src/A.php:16', '/r/src/B.php:31', '/r/src/B.php:32', '/r/src/A.php:33',
            '/r/src/B.php:34', '/r/src/B.php:35', '/r/src/A.php:36', '/r/src/A.php:37',
            '/r/src/B.php:38', '/r/src/B.php:39',
        ];
        PlacementAdvisor::armAdoptionProbe([
            $this->row('Acme\\Env::isDev', '/r/src/Env.php', 10, 13, 19, 1, ['/r/src/A.php' => 10, '/r/src/B.php' => 8], $locs),
        ]);
        $cluster = $this->cluster(
            $this->member('/r/src/A.php', 'Acme\\X', 'Alpha', 'run', 14, 20),
            $this->member('/r/src/B.php', 'Acme\\X', 'Beta', 'run', 14, 20),
        );
        $cluster->holes[] = new Hole('__P0', 'identifier', ['$u', '$v']);

        $advice = (new PlacementAdvisor([]))->advise($cluster);

        $this->assertSame([
            'label'         => 'Acme\\Env::isDev',
            'file'          => '/r/src/Env.php',
            'line'          => 10,
            'inline_copies' => 18,
            'is_member'     => false,
        ], $advice['canonical']);
    }

    public function testStrongestRowWinsDeterministically(): void
    {
        PlacementAdvisor::armAdoptionProbe([
            $this->row('Acme\\Wide::isDev', '/r/src/Wide.php', 4, 7, 7, 1, ['/r/src/A.php' => 3, '/r/src/B.php' => 3], ['/r/src/A.php:16', '/r/src/A.php:18', '/r/src/B.php:17']),
            $this->row('Acme\\Deep::isDev', '/r/src/Deep.php', 9, 12, 9, 1, ['/r/src/A.php' => 4, '/r/src/B.php' => 4], ['/r/src/A.php:16', '/r/src/B.php:17']),
        ]);
        $cluster = $this->cluster(
            $this->member('/r/src/A.php', 'Acme\\X', 'Alpha', 'run', 14, 20),
            $this->member('/r/src/B.php', 'Acme\\X', 'Beta', 'run', 14, 20),
        );
        $cluster->holes[] = new Hole('__P0', 'identifier', ['$u', '$v']);

        $advice = (new PlacementAdvisor([]))->advise($cluster);

        // Both cover; 8 inline copies beat 6 — strongest evidence,
        // independent of row input order.
        $this->assertSame('Acme\\Deep::isDev', $advice['canonical']['label']);
        $this->assertSame(8, $advice['canonical']['inline_copies']);
    }

    public function testEqualStrengthRowsTieBreakByAscendingLabelUnderPermutedOrders(): void
    {
        // The reviewer probe's committed twin: two rows with IDENTICAL
        // evidence (same inline count, both cover every member
        // line-exactly) decide on the comparator's next key legs —
        // [-inlineSites, label, defFile, defStart] — so the ascending
        // label wins regardless of the ledger's input order.
        $abe = $this->row('Acme\\Abe::isDev', '/r/src/Abe.php', 4, 7, 5, 1,
            ['/r/src/A.php' => 2, '/r/src/B.php' => 2],
            ['/r/src/A.php:16', '/r/src/A.php:18', '/r/src/B.php:17', '/r/src/B.php:19']);
        $zed = $this->row('Acme\\Zed::isDev', '/r/src/Zed.php', 9, 12, 5, 1,
            ['/r/src/A.php' => 2, '/r/src/B.php' => 2],
            ['/r/src/A.php:16', '/r/src/A.php:18', '/r/src/B.php:17', '/r/src/B.php:19']);

        $winner = function (array $rows): array {
            PlacementAdvisor::armAdoptionProbe($rows);
            $cluster = $this->cluster(
                $this->member('/r/src/A.php', 'Acme\\X', 'Alpha', 'run', 14, 20),
                $this->member('/r/src/B.php', 'Acme\\X', 'Beta', 'run', 14, 20),
            );
            $cluster->holes[] = new Hole('__P0', 'identifier', ['$u', '$v']);
            return (new PlacementAdvisor([]))->advise($cluster)['canonical'];
        };

        $expected = [
            'label'         => 'Acme\\Abe::isDev',
            'file'          => '/r/src/Abe.php',
            'line'          => 4,
            'inline_copies' => 4,
            'is_member'     => false,
        ];
        $this->assertSame($expected, $winner([$zed, $abe]));
        $this->assertSame($expected, $winner([$abe, $zed]));
    }

    public function testTieBreakDescendsToDefFileThenLineWhenLabelsMatch(): void
    {
        // Same label on every row: the file leg decides first (Aar.php
        // sorts before Abe.php, even at a LATER def line — file outranks
        // line in the key), and with one file left standing the line
        // leg decides (4 before 9). All four input permutations of each
        // lineup must agree — order never sways the winner.
        $abe9 = $this->row('Acme\\Abe::isDev', '/r/src/Abe.php', 9, 12, 5, 1,
            ['/r/src/A.php' => 2, '/r/src/B.php' => 2],
            ['/r/src/A.php:16', '/r/src/A.php:18', '/r/src/B.php:17', '/r/src/B.php:19']);
        $abe4 = $this->row('Acme\\Abe::isDev', '/r/src/Abe.php', 4, 7, 5, 1,
            ['/r/src/A.php' => 2, '/r/src/B.php' => 2],
            ['/r/src/A.php:16', '/r/src/A.php:18', '/r/src/B.php:17', '/r/src/B.php:19']);
        $aar9 = $this->row('Acme\\Abe::isDev', '/r/src/Aar.php', 9, 12, 5, 1,
            ['/r/src/A.php' => 2, '/r/src/B.php' => 2],
            ['/r/src/A.php:16', '/r/src/A.php:18', '/r/src/B.php:17', '/r/src/B.php:19']);

        $winner = function (array $rows): array {
            PlacementAdvisor::armAdoptionProbe($rows);
            $cluster = $this->cluster(
                $this->member('/r/src/A.php', 'Acme\\X', 'Alpha', 'run', 14, 20),
                $this->member('/r/src/B.php', 'Acme\\X', 'Beta', 'run', 14, 20),
            );
            $cluster->holes[] = new Hole('__P0', 'identifier', ['$u', '$v']);
            return (new PlacementAdvisor([]))->advise($cluster)['canonical'];
        };

        // File leg beats line leg: the :9 row in the earlier file wins
        // over the :4 row in the later one.
        $byFile = [
            'label'         => 'Acme\\Abe::isDev',
            'file'          => '/r/src/Aar.php',
            'line'          => 9,
            'inline_copies' => 4,
            'is_member'     => false,
        ];
        $this->assertSame($byFile, $winner([$abe9, $abe4, $aar9]));
        $this->assertSame($byFile, $winner([$aar9, $abe9, $abe4]));
        $this->assertSame($byFile, $winner([$abe4, $aar9, $abe9]));

        // Line leg decides within one file.
        $byLine = [
            'label'         => 'Acme\\Abe::isDev',
            'file'          => '/r/src/Abe.php',
            'line'          => 4,
            'inline_copies' => 4,
            'is_member'     => false,
        ];
        $this->assertSame($byLine, $winner([$abe9, $abe4]));
        $this->assertSame($byLine, $winner([$abe4, $abe9]));
    }

    public function testCanonicalProbeNeedsInlineCopiesNotJustADefinition(): void
    {
        // inlineSites < 1 rows are definitions without copies — no
        // advice to give from them.
        PlacementAdvisor::armAdoptionProbe([
            $this->row('Acme\\Lone::only', '/r/src/Lone.php', 3, 6, 1, 1, [], []),
        ]);
        $cluster = $this->cluster(
            $this->member('/r/src/A.php', 'Acme\\X', 'Alpha', 'run', 14, 20),
            $this->member('/r/src/B.php', 'Acme\\X', 'Beta', 'run', 14, 20),
        );
        $cluster->holes[] = new Hole('__P0', 'identifier', ['$u', '$v']);

        $advice = (new PlacementAdvisor([]))->advise($cluster);

        $this->assertArrayNotHasKey('canonical', $advice);
    }

    // ---- (c) fallbacks ----------------------------------------------------

    public function testDirectoryLcaAppearsWhenNamespacesShareNoRoot(): void
    {
        PlacementAdvisor::armAdoptionProbe([]);
        $cluster = $this->cluster(
            $this->member('/r/pkg/src/A.php', 'Acme', 'Alpha', 'run'),
            $this->member('/r/pkg/lib/B.php', 'Other', 'Beta', 'run'),
        );
        $cluster->holes[] = new Hole('__P0', 'identifier', ['$u', '$v']);

        $advice = (new PlacementAdvisor([]))->advise($cluster);

        $this->assertSame('', $advice['lca_namespace']);
        $this->assertSame('/r/pkg', $advice['lca_directory']);
        $this->assertStringContainsString('lowest common directory: /r/pkg', $advice['rationale']);
    }

    public function testDirectoryLcaIsTheOneDirWhenMembersCoReside(): void
    {
        PlacementAdvisor::armAdoptionProbe([]);
        $cluster = $this->cluster(
            $this->member('/r/pkg/src/A.php', '', 'Alpha', 'run'),
            $this->member('/r/pkg/src/B.php', '', 'Beta', 'go'),
        );
        $cluster->holes[] = new Hole('__P0', 'identifier', ['$u', '$v']);

        $advice = (new PlacementAdvisor([]))->advise($cluster);

        $this->assertSame('/r/pkg/src', $advice['lca_directory']);
    }

    public function testNoDirectoryLcaWhenFilesystemRootsDiverge(): void
    {
        PlacementAdvisor::armAdoptionProbe([]);
        $cluster = $this->cluster(
            $this->member('/disk-a/pkg/A.php', 'Acme', 'Alpha', 'run'),
            $this->member('/disk-b/pkg/B.php', 'Other', 'Beta', 'run'),
        );
        $cluster->holes[] = new Hole('__P0', 'identifier', ['$u', '$v']);

        $advice = (new PlacementAdvisor([]))->advise($cluster);

        $this->assertArrayNotHasKey('lca_directory', $advice);
        $this->assertStringNotContainsString('lowest common directory', $advice['rationale']);
    }

    public function testDisarmedRunsNeverSeeTheDirectoryFallback(): void
    {
        PlacementAdvisor::armAdoptionProbe(null);
        $cluster = $this->cluster(
            $this->member('/r/pkg/src/A.php', 'Acme', 'Alpha', 'run'),
            $this->member('/r/pkg/lib/B.php', 'Other', 'Beta', 'run'),
        );
        $cluster->holes[] = new Hole('__P0', 'identifier', ['$u', '$v']);

        $advice = (new PlacementAdvisor([]))->advise($cluster);

        $this->assertSame(['shape', 'rationale', 'same_host', 'lca_namespace', 'host_packages'], array_keys($advice));
    }

    public function testEmptyIntersectionArmsTheSharedPackageGuidance(): void
    {
        [$root1, $root2] = $this->twoRoots(
            json_encode(['require' => ['acme/one' => '^1']]),
            json_encode(['require' => ['acme/two' => '^1']]),
        );
        $cluster = $this->cluster(
            $this->member($root1 . '/src/A.php', 'Acme\\X', 'Alpha', 'run'),
            $this->member($root2 . '/src/B.php', 'Acme\\X', 'Beta', 'go'),
        );
        $cluster->holes[] = new Hole('__P0', 'identifier', ['$u', '$v']);

        PlacementAdvisor::armAdoptionProbe([]);
        $armed = (new PlacementAdvisor([$root1, $root2]))->advise($cluster);
        $this->assertSame([], $armed['host_packages']);
        $this->assertStringContainsString('create a shared package', $armed['rationale']);

        // Disarmed: the SAME payload as before the guidance existed —
        // the empty list keeps saying so silently.
        PlacementAdvisor::armAdoptionProbe(null);
        $off = (new PlacementAdvisor([$root1, $root2]))->advise($cluster);
        $this->assertSame([], $off['host_packages']);
        $this->assertStringNotContainsString('shared package', $off['rationale']);
    }

    public function testNonEmptyIntersectionNeverSaysCreateSharedPackage(): void
    {
        [$root1, $root2] = $this->twoRoots(
            json_encode(['require' => ['acme/shared' => '^1']]),
            json_encode(['require' => ['acme/shared' => '^2']]),
        );
        $cluster = $this->cluster(
            $this->member($root1 . '/src/A.php', 'Acme\\X', 'Alpha', 'run'),
            $this->member($root2 . '/src/B.php', 'Acme\\X', 'Beta', 'go'),
        );
        $cluster->holes[] = new Hole('__P0', 'identifier', ['$u', '$v']);

        PlacementAdvisor::armAdoptionProbe([]);
        $advice = (new PlacementAdvisor([$root1, $root2]))->advise($cluster);

        $this->assertSame(['acme/shared'], $advice['host_packages']);
        $this->assertStringNotContainsString('create a shared package', $advice['rationale']);
    }

    public function testAdviceIsDeterministicUnderArm(): void
    {
        PlacementAdvisor::armAdoptionProbe([
            $this->row('Acme\\Env::isDev', '/r/src/Env.php', 10, 13, 5, 1, ['/r/src/A.php' => 2, '/r/src/B.php' => 2], ['/r/src/A.php:16', '/r/src/B.php:17']),
        ]);
        $cluster = $this->cluster(
            $this->member('/r/src/A.php', 'Acme\\X', 'Alpha', 'run', 14, 20),
            $this->member('/r/src/B.php', 'Acme\\X', 'Beta', 'run', 14, 20),
        );
        $cluster->holes[] = new Hole('__P0', 'identifier', ['$u', '$v']);

        $advisor = new PlacementAdvisor([]);
        $this->assertSame($advisor->advise($cluster), $advisor->advise($cluster));
    }

    // ---- helpers ----------------------------------------------------------

    /** @param list<Block> $members */
    private function cluster(Block ...$members): Cluster
    {
        return new Cluster('T', $members, 0.9, false);
    }

    private function member(string $file, string $namespace, string $class, string $name, int $start = 1, int $end = 3): Block
    {
        return new Block($file, new LineRange($start, $end), 'method', $namespace, $class, $name, new Nop());
    }

    /**
     * @param array<string,int>   $inlineFiles
     * @param list<string>        $copyLocations
     */
    private function row(
        string $label,
        string $defFile,
        int $defStart,
        int $defEnd,
        int $sitesTotal,
        int $defSites,
        array $inlineFiles,
        array $copyLocations,
    ): HelperCopy {
        return new HelperCopy(
            helperLabel: $label,
            defFile: $defFile,
            defStart: $defStart,
            defEnd: $defEnd,
            helperKind: 'method',
            defStatements: 3,
            patternExact: 'e' . substr(sha1($label), 0, 12),
            patternAbstract: 'a' . substr(sha1($label), 0, 12),
            nodes: 5,
            rootType: 'Expr Equal',
            sitesTotal: $sitesTotal,
            defSites: $defSites,
            inlineSites: $sitesTotal - $defSites,
            inlineFiles: $inlineFiles,
            copyLocations: $copyLocations,
        );
    }

    /** @return array{0:string,1:string} two fresh scan roots with composer.json bodies */
    private function twoRoots(string $manifest1, string $manifest2): array
    {
        $parent = $this->tmpDir();
        $roots  = [];
        foreach ([$manifest1, $manifest2] as $i => $body) {
            $root = $parent . '/r' . $i;
            mkdir($root . '/src', 0777, true);
            file_put_contents($root . '/composer.json', $body);
            $roots[] = $root;
        }
        return $roots;
    }

    /** @var list<string> */
    private array $tmpDirs = [];

    protected function setUp(): void
    {
        $this->tmpDirs = [];
    }

    private function tmpDir(): string
    {
        $dir = sys_get_temp_dir() . '/phpdup-p3-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->tmpDirs[] = $dir;
        return $dir;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
