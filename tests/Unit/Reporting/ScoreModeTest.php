<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Clustering\Cluster;
use Phpdup\Cli\Config;
use Phpdup\Extraction\Block;
use Phpdup\Reporting\Ranker;
use Phpdup\Reporting\SafetyScorer;
use Phpdup\Util\LineRange;

/**
 * Item 5.1 — the score-mode stance table.
 *
 * 'global' must reproduce the legacy math exactly (byte-identity is
 * pinned harder by the untouched-golden gate; here it is pinned as
 * raw float equality between the default construction and an explicit
 * global, plus literal pins of the historical deltas); 'per-repo'
 * groups the namespace penalty so cross-repo mirroring is counted at
 * most once; 'typed' rewards the same cross-repo clusters.
 */
final class ScoreModeTest extends TestCase
{
    public function testGlobalIsTheDefaultAndEqualsExplicitGlobalByteForByte(): void
    {
        foreach ([self::oneRepoFourNs(), self::mirroredThreeNsTwoRepos(), self::singleNs()] as $c) {
            $legacy   = (new SafetyScorer())->score($c);
            $explicit = (new SafetyScorer(Config::SCORE_MODE_GLOBAL))->score($c);
            $this->assertSame($legacy, $explicit,
                "safety: default construction must run the identical float path as 'global' on {$c->id}");

            $rankLegacy = self::rankConfidence([$c], Config::SCORE_MODE_GLOBAL)[$c->id];
            $rankDefault = self::rankConfidence([$c], null)[$c->id];
            $this->assertSame($rankLegacy, $rankDefault,
                "confidence: default Ranker must equal explicit global on {$c->id}");
        }
    }

    public function testLegacyLiteralPins(): void
    {
        // Historical arithmetic, written out as literals so a future
        // refactor of the global branch cannot silently move numbers.
        // member-count factor: 3..8 members +0.03, 2 members −0.05.
        // oneRepoFourNs confidence: 0.9 − 0.05 (raw ns 4 > 3) + 0.05 (single class)
        $this->assertEqualsWithDelta(0.90,
            self::rankConfidence([self::oneRepoFourNs()], 'global')['ONE'], 1e-9);
        // oneRepoFourNs safety: 0.9 − 0.10 + 0.03 (4 members)
        $this->assertEqualsWithDelta(0.83,
            (new SafetyScorer(Config::SCORE_MODE_GLOBAL))->score(self::oneRepoFourNs()), 1e-9);
        // single ns, 2 members: 0.9 + 0.05 − 0.05 (pair nudge)
        $this->assertEqualsWithDelta(0.90,
            (new SafetyScorer(Config::SCORE_MODE_GLOBAL))->score(self::singleNs()), 1e-9);
        // mirrored 2×3 ns, 6 members: 0.9 − 0.10 + 0.03 — the −0.10 is
        // the double-dip itself, firing on the mirroring, not on merit.
        $this->assertEqualsWithDelta(0.83,
            (new SafetyScorer(Config::SCORE_MODE_GLOBAL))->score(self::mirroredThreeNsTwoRepos()), 1e-9,
            '6 raw namespaces > 3 → legacy −0.10 fires on the mirroring itself (the double-dip)');
    }

    public function testPerRepoUngroupsTheMirroredDoubleDip(): void
    {
        // The phlix shape: same 3 subsystems in each of 2 repos.
        // Global reads raw ns = 6 → penalties fire on the mirroring.
        // Per-repo reads spread = 3 → the cluster escapes both penalties.
        $c = self::mirroredThreeNsTwoRepos();

        $safetyGlobal = (new SafetyScorer('global'))->score($c);
        $safetyPerRepo = (new SafetyScorer('per-repo'))->score($c);
        // 6 ns > 3 → global pays −0.10 for the mirroring itself
        // (0.9 − 0.10 + 0.03 six-member nudge = 0.83); per-repo spread 3
        // pays nothing (0.9 + 0.03 = 0.93).
        $this->assertEqualsWithDelta(0.83, $safetyGlobal, 1e-9);
        $this->assertEqualsWithDelta(0.93, $safetyPerRepo, 1e-9);
        $this->assertGreaterThan($safetyGlobal, $safetyPerRepo);

        $confGlobal  = self::rankConfidence([$c], 'global')[$c->id];
        $confPerRepo = self::rankConfidence([$c], 'per-repo')[$c->id];
        $this->assertEqualsWithDelta(0.90, $confGlobal, 1e-9);  // 0.9 −0.05 +0.05(class)
        $this->assertEqualsWithDelta(0.95, $confPerRepo, 1e-9); // 0.9 +0.05(class)
    }

    public function testPerRepoLeavesOneRepoClustersPinnedToGlobalNumbers(): void
    {
        foreach ([self::oneRepoFourNs(), self::singleNs()] as $c) {
            $this->assertSame(
                (new SafetyScorer('global'))->score($c),
                (new SafetyScorer('per-repo'))->score($c),
                "single-repo {$c->id}: per-repo degrades EXACTLY (same float)",
            );
            $this->assertSame(
                self::rankConfidence([$c], 'global')[$c->id],
                self::rankConfidence([$c], 'per-repo')[$c->id],
            );
        }
    }

    public function testTypedRewardsCrossRepoClusters(): void
    {
        $c = self::mirroredThreeNsTwoRepos();

        $this->assertEqualsWithDelta(1.00, (new SafetyScorer('typed'))->score($c), 1e-9,
            '0.9 + 0.10 typed cross-repo bonus (clamped at 1.0 by the legacy ceiling too)');
        $conf = self::rankConfidence([$c], 'typed')[$c->id];
        $this->assertGreaterThan(
            self::rankConfidence([$c], 'global')[$c->id],
            $conf,
        );
        // 0.9 + 0.05 typed + 0.05 single-class = 1.00.
        $this->assertEqualsWithDelta(1.00, $conf, 1e-9);
    }

    public function testTypedSameRepoNumbersAreGlobalNumbers(): void
    {
        foreach ([self::oneRepoFourNs(), self::singleNs()] as $c) {
            $this->assertSame(
                (new SafetyScorer('global'))->score($c),
                (new SafetyScorer('typed'))->score($c),
                "not cross-repo: {$c->id} must be identical under typed",
            );
        }
    }

    public function testInvalidModesFailLoud(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SafetyScorer('sideways');
    }

    public function testInvalidRankerModeFailsLoud(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Ranker(0, null, 0.0, null, 'sideways');
    }

    public function testConfigRejectsInvalidStance(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Config(paths: ['/x'], exclude: [], scoreMode: 'whatever');
    }

    public function testConfigRejectsEmptyScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Config(paths: ['/x'], exclude: [], scope: '');
    }

    // ------------------------------------------------------------- fixtures

    /**
     * @param array<string, Block> $members keyed blocks
     */
    private static function rankConfidence(array $clusters, ?string $mode): array
    {
        $ranker = $mode === null ? new Ranker(0) : new Ranker(0, null, 0.0, null, $mode);
        $ranker->rank(array_values($clusters));
        $out = [];
        foreach ($clusters as $c) {
            $out[$c->id] = $c->confidence;
        }
        return $out;
    }

    /** 4 namespaces, one repo — raw count 4, per-repo spread 4. */
    private static function oneRepoFourNs(): Cluster
    {
        return new Cluster('ONE', [
            self::member('/r1/A.php', 'Acme\\Alpha', 'repo1', 'K'),
            self::member('/r1/B.php', 'Acme\\Beta',  'repo1', 'K'),
            self::member('/r1/C.php', 'Acme\\Gamma', 'repo1', 'K'),
            self::member('/r1/D.php', 'Acme\\Delta', 'repo1', 'K'),
        ], 0.9, false);
    }

    /** One namespace only — the +0.05/+0.05 happy path. */
    private static function singleNs(): Cluster
    {
        return new Cluster('SOLO', [
            self::member('/r1/A.php', 'Acme\\Alpha', 'repo1', 'K'),
            self::member('/r1/B.php', 'Acme\\Alpha', 'repo1', 'K'),
        ], 0.9, false);
    }

    /** 3 subsystems mirrored into 2 repos: raw ns 6, per-repo spread 3. */
    private static function mirroredThreeNsTwoRepos(): Cluster
    {
        return new Cluster('MIRROR', [
            self::member('/r1/A.php', 'Shop1\\Admin', 'repo1', 'K'),
            self::member('/r1/B.php', 'Shop1\\Api',   'repo1', 'K'),
            self::member('/r1/C.php', 'Shop1\\Cron',  'repo1', 'K'),
            self::member('/r2/A.php', 'Shop2\\Admin', 'repo2', 'K'),
            self::member('/r2/B.php', 'Shop2\\Api',   'repo2', 'K'),
            self::member('/r2/C.php', 'Shop2\\Cron',  'repo2', 'K'),
        ], 0.9, false);
    }

    private static function member(string $file, string $namespace, string $repo, string $class): Block
    {
        $b = new Block(
            file:      $file,
            range:     new LineRange(1, 10),
            kind:      'method',
            namespace: $namespace,
            class:     $class,
            name:      'm',
            ast:       new Node\Stmt\Nop(),
        );
        $b->repo  = $repo;
        $b->scope = $repo;
        return $b;
    }
}
