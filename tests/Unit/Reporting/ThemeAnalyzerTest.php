<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Reporting;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Census\CallSequenceCensus;
use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Reporting\JsonSchemaSpec;
use Phpdup\Reporting\ThemeAnalyzer;
use Phpdup\Util\LineRange;

/**
 * Item 6.4 (THEME): hermetic done-when evidence for the finding-theme
 * roll-up. The letter's semantic labels ("outbound HTTP") are item 8.2's
 * LLM layer; this suite pins the deterministic heuristic floor 8.2 will
 * relabel: shared-callee-vocabulary and directory seeds, the greedy
 * claim (vocabulary before directory, one theme per cluster, coverage
 * desc / seed asc with the FindingId tie-break), the honest themeless
 * residual, the degraded patternTags-only basis when the call census is
 * unarmed, span scoping over the census name index (with the disclosed
 * overflow-counts-as-inside law), content-derived ids, and the bounded
 * payload with truncation counters.
 */
final class ThemeAnalyzerTest extends TestCase
{
    private const ROOT = '/phpdup-theme-root';

    private function synthBlock(string $file, int $s, int $e, string $id = ''): Block
    {
        $b = new Block($file, new LineRange($s, $e), 'method', null, 'K', 'm', new Node\Stmt\Nop());
        $b->id = $id !== '' ? $id : substr(hash('xxh64', $file . ':' . $s . ':' . $e), 0, 16);
        $b->structuralHash = substr(hash('xxh64', $file . '|' . $s . '|' . $e), 0, 16);
        return $b;
    }

    /** @param list<Block> $members @param list<string> $tags */
    private function cluster(string $id, array $members, array $tags = []): Cluster
    {
        $c = new Cluster($id, $members, 1.0, true);
        $c->patternTags = $tags;
        return $c;
    }

    /**
     * Census wire: one record listing the file's named calls at the
     * given absolute lines (the miner's own contract shape).
     *
     * @param array<string, list<int>> $tokenLines token => lines
     */
    private function census(array $perFile): CallSequenceCensus
    {
        $c = new CallSequenceCensus();
        foreach ($perFile as $file => $tokenLines) {
            $names = [];
            $lines = [];
            $args  = [];
            foreach ($tokenLines as $token => $ls) {
                foreach ($ls as $line) {
                    $names[] = $token;
                    $lines[] = $line;
                    $args[]  = [];
                }
            }
            if ($names === []) {
                continue; // a call-free file records nothing (the miner
                          // mints no empty record either — parallel-field
                          // guard would (rightly) refuse one)
            }
            $c->recordFile($file, [['names' => $names, 'lines' => $lines, 'args' => $args]]);
        }
        return $c;
    }

    // ------------------------------------------------------------- basics

    public function testEmptyClustersIsZeroShapeNotNull(): void
    {
        $payload = (new ThemeAnalyzer())->analyze([], null);
        $this->assertSame(array_keys(JsonSchemaSpec::themeBlockShape()), array_keys($payload));
        $this->assertSame(['call_census' => false], $payload['basis']);
        $this->assertSame(
            ['clusters' => 0, 'themed_clusters' => 0, 'themeless_clusters' => 0, 'themes' => 0],
            $payload['totals'],
        );
        $this->assertSame([], $payload['themes']);
        $this->assertSame(0, $payload['themes_truncated']);
    }

    public function testSharedCalleeTokenFormsVocabularyTheme(): void
    {
        $a = $this->cluster('ca', [$this->synthBlock(self::ROOT . '/src/a.php', 5, 15)]);
        $b = $this->cluster('cb', [$this->synthBlock(self::ROOT . '/src/b.php', 7, 20)]);
        $census = $this->census([
            self::ROOT . '/src/a.php' => ['send' => [10], 'load' => [8]],
            self::ROOT . '/src/b.php' => ['send' => [12], 'save' => [18]],
        ]);
        $payload = (new ThemeAnalyzer(relRoots: [self::ROOT]))->analyze([$a, $b], $census);

        $this->assertSame(['call_census' => true], $payload['basis']);
        $this->assertSame(2, $payload['totals']['themed_clusters']);
        $this->assertSame(0, $payload['totals']['themeless_clusters']);
        $this->assertCount(1, $payload['themes']);
        $theme = $payload['themes'][0];
        $this->assertSame(ThemeAnalyzer::KIND_VOCABULARY, $theme['kind']);
        // 'load' and 'save' appear in ONE cluster each -> not seeds;
        // 'send' is the only coverage-2 token -> the theme.
        $this->assertSame('send', $theme['seed']);
        $this->assertSame('send › src', $theme['label']);
        $this->assertSame(['ca', 'cb'], $theme['cluster_ids']);
        $this->assertSame(['src'], $theme['dirs']);
        $this->assertSame('src', $theme['dominant_dir']);
        // lines = union span per cluster: (15-5+1) + (20-7+1) = 11 + 14.
        $this->assertSame(25, $theme['lines']);
        $this->assertSame(hash('xxh64', 'vocabulary|send'), $theme['id']);
        $this->assertSame(['clusters' => 2, 'themed_clusters' => 2, 'themeless_clusters' => 0, 'themes' => 1], $payload['totals']);
    }

    public function testPatternTagsAloneFormThemesWhenCensusUnarmed(): void
    {
        $a = $this->cluster('ca', [$this->synthBlock(self::ROOT . '/src/a.php', 1, 5)], ['sql-builder']);
        $b = $this->cluster('cb', [$this->synthBlock(self::ROOT . '/lib/b.php', 1, 5)], ['sql-builder', 'db-op']);
        $payload = (new ThemeAnalyzer(relRoots: [self::ROOT]))->analyze([$a, $b], null);

        $this->assertFalse($payload['basis']['call_census'], 'the degraded basis is disclosed, never silent');
        $this->assertCount(1, $payload['themes']);
        $this->assertSame('sql-builder', $payload['themes'][0]['seed']);
        $this->assertSame(ThemeAnalyzer::KIND_VOCABULARY, $payload['themes'][0]['kind']);
        // 'db-op' is singleton coverage -> no second theme, and it cannot
        // enter the seed theme's shared-vocabulary sample either (the
        // sample keeps only tokens >= 2 members carry, minus the seed).
        $this->assertSame(1, $payload['totals']['themes']);
        $this->assertSame([], $payload['themes'][0]['vocabulary']);
    }

    public function testDirectoryThemeWhenVocabularySilent(): void
    {
        $a = $this->cluster('ca', [$this->synthBlock(self::ROOT . '/mods/log.php', 1, 9)]);
        $b = $this->cluster('cb', [$this->synthBlock(self::ROOT . '/mods/user.php', 1, 9)]);
        $payload = (new ThemeAnalyzer(relRoots: [self::ROOT]))->analyze([$a, $b], null);

        $this->assertCount(1, $payload['themes']);
        $theme = $payload['themes'][0];
        $this->assertSame(ThemeAnalyzer::KIND_DIRECTORY, $theme['kind']);
        $this->assertSame('mods', $theme['seed']);
        // A directory seed's label is the directory itself (no joiner).
        $this->assertSame('mods', $theme['label']);
        $this->assertSame(hash('xxh64', 'directory|mods'), $theme['id']);
        $this->assertSame(['ca', 'cb'], $theme['cluster_ids']);
    }

    public function testSingletonSeedMintsNoThemeAndCountsTheResidual(): void
    {
        $a = $this->cluster('ca', [$this->synthBlock(self::ROOT . '/x/one.php', 1, 9)]);
        $b = $this->cluster('cb', [$this->synthBlock(self::ROOT . '/y/two.php', 1, 9)]);
        $payload = (new ThemeAnalyzer(relRoots: [self::ROOT]))->analyze([$a, $b], null);

        $this->assertSame([], $payload['themes'], 'no token or dir is shared -> no theme, ever');
        $this->assertSame(0, $payload['totals']['themed_clusters']);
        $this->assertSame(2, $payload['totals']['themeless_clusters'], 'the residual is counted, never silenced');
    }

    // ------------------------------------------------------------ claim law

    public function testVocabularySeedsClaimBeforeDirectorySeeds(): void
    {
        // c1,c2 share token 'send' AND dir 'http'; c3,c4 share only dir
        // 'http'... to keep the directory claim visible, c3,c4 live under
        // the SAME dir as the vocabulary pair: after 'send' claims c1,c2
        // the 'http' seed still has >= 2 free members and themes them.
        $c1 = $this->cluster('c1', [$this->synthBlock(self::ROOT . '/http/a.php', 1, 10)]);
        $c2 = $this->cluster('c2', [$this->synthBlock(self::ROOT . '/http/b.php', 1, 10)]);
        $c3 = $this->cluster('c3', [$this->synthBlock(self::ROOT . '/http/c.php', 1, 10)]);
        $c4 = $this->cluster('c4', [$this->synthBlock(self::ROOT . '/http/d.php', 1, 10)]);
        $census = $this->census([
            self::ROOT . '/http/a.php' => ['send' => [5]],
            self::ROOT . '/http/b.php' => ['send' => [5]],
        ]);
        $payload = (new ThemeAnalyzer(relRoots: [self::ROOT]))->analyze([$c1, $c2, $c3, $c4], $census);

        $this->assertSame(2, $payload['totals']['themes']);
        [$first, $second] = $payload['themes'];
        $this->assertSame(ThemeAnalyzer::KIND_VOCABULARY, $first['kind']);
        $this->assertSame('send', $first['seed']);
        $this->assertSame(['c1', 'c2'], $first['cluster_ids']);
        $this->assertSame(ThemeAnalyzer::KIND_DIRECTORY, $second['kind']);
        $this->assertSame('http', $second['seed']);
        $this->assertSame(['c3', 'c4'], $second['cluster_ids']);
        $this->assertSame(4, $payload['totals']['themed_clusters']);
        $this->assertSame(0, $payload['totals']['themeless_clusters']);
    }

    public function testClusterJoinsAtMostOneTheme(): void
    {
        // Three clusters share 'x'; a directory covering a subset can
        // NEVER re-theme an already claimed cluster (claim is exclusive).
        $a = $this->cluster('ca', [$this->synthBlock(self::ROOT . '/p/a.php', 1, 10)]);
        $b = $this->cluster('cb', [$this->synthBlock(self::ROOT . '/p/b.php', 1, 10)]);
        $c = $this->cluster('cc', [$this->synthBlock(self::ROOT . '/q/c.php', 1, 10)]);
        $census = $this->census([
            self::ROOT . '/p/a.php' => ['x' => [5]],
            self::ROOT . '/p/b.php' => ['x' => [5]],
            self::ROOT . '/q/c.php' => ['x' => [5]],
        ]);
        $payload = (new ThemeAnalyzer(relRoots: [self::ROOT]))->analyze([$a, $b, $c], $census);

        $this->assertCount(1, $payload['themes'], "'p' covers two claimed members -> no directory theme");
        $this->assertSame('x', $payload['themes'][0]['seed']);
        $this->assertSame(3, $payload['themes'][0]['clusters']);
        $this->assertSame(['ca', 'cb', 'cc'], $payload['themes'][0]['cluster_ids']);
    }

    public function testDominantDirTieBreakAndVocabularySampleLaw(): void
    {
        // Both members touch two dirs equally (one block each); tie
        // breaks label asc ('api' < 'impl'). Shared non-seed vocabulary:
        // 'trace' appears in both members (after 'send' alphabetically,
        // so the seed tie-break picks 'send'); 'solo' in one only.
        $a = $this->cluster('ca', [
            $this->synthBlock(self::ROOT . '/api/a1.php', 1, 10),
            $this->synthBlock(self::ROOT . '/impl/a2.php', 1, 10),
        ]);
        $b = $this->cluster('cb', [
            $this->synthBlock(self::ROOT . '/api/b1.php', 1, 10),
            $this->synthBlock(self::ROOT . '/impl/b2.php', 1, 10),
        ]);
        $census = $this->census([
            self::ROOT . '/api/a1.php'   => ['send' => [5], 'trace' => [7], 'solo' => [9]],
            self::ROOT . '/api/b1.php'   => ['send' => [5], 'trace' => [7]],
        ]);
        $payload = (new ThemeAnalyzer(relRoots: [self::ROOT]))->analyze([$a, $b], $census);
        $theme = $payload['themes'][0];

        $this->assertSame('send', $theme['seed']);
        $this->assertSame('api', $theme['dominant_dir'], 'equal member weights tie-break label asc');
        $this->assertSame(['api', 'impl'], $theme['dirs']);
        $this->assertSame('send › api', $theme['label']);
        $this->assertSame(['trace'], $theme['vocabulary'], 'the seed excludes itself; singletons never join');
    }

    // ---------------------------------------------------- census accessor law

    public function testCalleeTokensInSpansAndOverflowLaw(): void
    {
        $retained = $this->census([
            '/f.php' => ['alpha' => [3, 20]],
        ]);
        $this->assertSame(['alpha'], $retained->calleeTokensIn('/f.php', 20, 30));
        $this->assertSame(['alpha'], $retained->calleeTokensIn('/f.php', 3, 3));
        $this->assertSame([], $retained->calleeTokensIn('/f.php', 4, 19), 'outside every retained line and no overflow -> no token');
        $this->assertSame([], $retained->calleeTokensIn('/other.php', 1, 100));

        // 17 occurrences: lines 1..16 retained, line 99 overflows the cap.
        $overflowed = $this->census([
            '/f.php' => ['zeta' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 99]],
        ]);
        $this->assertSame(
            ['zeta'],
            $overflowed->calleeTokensIn('/f.php', 90, 95),
            'overflowed occurrences count as INSIDE (the disclosed worst direction for theming)'
        );
    }

    public function testAnalyzerHonoursSpanExclusionEndToEnd(): void
    {
        // 'send' is called in both files but OUTSIDE every member window;
        // the theme must fall through to the directory axis, not launder
        // off-span calls into vocabulary.
        $a = $this->cluster('ca', [$this->synthBlock(self::ROOT . '/src/a.php', 5, 9)]);
        $b = $this->cluster('cb', [$this->synthBlock(self::ROOT . '/src/b.php', 5, 9)]);
        $census = $this->census([
            self::ROOT . '/src/a.php' => ['send' => [50]],
            self::ROOT . '/src/b.php' => ['send' => [60]],
        ]);
        $payload = (new ThemeAnalyzer(relRoots: [self::ROOT]))->analyze([$a, $b], $census);

        $this->assertCount(1, $payload['themes']);
        $this->assertSame(ThemeAnalyzer::KIND_DIRECTORY, $payload['themes'][0]['kind'], 'no vocabulary survived the span filter');
        $this->assertSame('src', $payload['themes'][0]['seed']);
    }

    // --------------------------------------------------------- determinism

    public function testArrivalOrderCannotChangeThePayload(): void
    {
        $clusters = [
            $this->cluster('c1', [$this->synthBlock(self::ROOT . '/u/x.php', 1, 10)], ['beta']),
            $this->cluster('c2', [$this->synthBlock(self::ROOT . '/v/y.php', 1, 10)], ['alpha']),
            $this->cluster('c3', [$this->synthBlock(self::ROOT . '/u/z.php', 1, 10)], ['beta']),
            $this->cluster('c4', [$this->synthBlock(self::ROOT . '/v/w.php', 1, 10)], ['alpha']),
        ];
        $shuffled = [$clusters[2], $clusters[0], $clusters[3], $clusters[1]];
        $one = (new ThemeAnalyzer(relRoots: [self::ROOT]))->analyze($clusters, null);
        $two = (new ThemeAnalyzer(relRoots: [self::ROOT]))->analyze($shuffled, null);
        // Cluster INDICES differ across arrival orders (rows are sorted by
        // id anyway); the payload — ids, seeds, claim membership — must
        // be byte-equal.
        $this->assertSame($one, $two);
        $this->assertSame(['alpha', 'beta'], array_column($one['themes'], 'seed'), 'coverage tie (2/2) orders seeds asc');
    }

    public function testThemeIdSurvivesUnrelatedGrowth(): void
    {
        $a = $this->cluster('ca', [$this->synthBlock(self::ROOT . '/src/a.php', 1, 10)]);
        $b = $this->cluster('cb', [$this->synthBlock(self::ROOT . '/src/b.php', 1, 10)]);
        $before = (new ThemeAnalyzer(relRoots: [self::ROOT]))->analyze([$a, $b], null);
        $this->assertSame('src', $before['themes'][0]['seed']);

        $c = $this->cluster('cc', [$this->synthBlock(self::ROOT . '/other/c.php', 1, 10)]);
        $d = $this->cluster('cd', [$this->synthBlock(self::ROOT . '/other/d.php', 1, 10)]);
        $after = (new ThemeAnalyzer(relRoots: [self::ROOT]))->analyze([$a, $b, $c, $d], null);

        $this->assertSame(2, $after['totals']['themes']);
        $bySeed = array_column($after['themes'], null, 'seed');
        $this->assertSame(
            $before['themes'][0]['id'],
            $bySeed['src']['id'],
            'content-derived ids do not churn when an unrelated theme joins'
        );
    }

    // --------------------------------------------------------------- caps

    public function testPayloadCapsCountTruncationAndKeepFullTotals(): void
    {
        // 12 independent vocabulary pairs -> 12 themes, TOP_THEMES rows.
        $clusters = [];
        $perFile = [];
        for ($i = 0; $i < 12; $i++) {
            $tok  = sprintf('tok%02d', $i);
            $fa   = self::ROOT . '/p/a' . $i . '.php';
            $fb   = self::ROOT . '/p/b' . $i . '.php';
            $clusters[] = $this->cluster(sprintf('ca%02d', $i), [$this->synthBlock($fa, 1, 10)]);
            $clusters[] = $this->cluster(sprintf('cb%02d', $i), [$this->synthBlock($fb, 1, 10)]);
            $perFile[$fa] = [$tok => [5]];
            $perFile[$fb] = [$tok => [5]];
        }
        $payload = (new ThemeAnalyzer(relRoots: [self::ROOT]))->analyze($clusters, $this->census($perFile));

        $this->assertSame(12, $payload['totals']['themes']);
        $this->assertCount(ThemeAnalyzer::TOP_THEMES, $payload['themes']);
        $this->assertSame(12 - ThemeAnalyzer::TOP_THEMES, $payload['themes_truncated']);
        $this->assertSame(24, $payload['totals']['themed_clusters']);
        // Seed asc inside equal coverage: tok00..tok09 are the survivors.
        $this->assertSame('tok00', $payload['themes'][0]['seed']);
        $this->assertSame('tok09', $payload['themes'][9]['seed']);

        // A 12-member theme caps its cluster_id sample at TOP_CLUSTERS.
        $many = [];
        $big  = [];
        for ($i = 0; $i < 12; $i++) {
            $file = self::ROOT . '/big/f' . $i . '.php';
            $many[] = $this->cluster(sprintf('m%02d', $i), [$this->synthBlock($file, 1, 10)]);
            $big[$file] = ['shared' => [5]];
        }
        $wide = (new ThemeAnalyzer(relRoots: [self::ROOT]))->analyze($many, $this->census($big));
        $theme = $wide['themes'][0];
        $this->assertSame(12, $theme['clusters'], 'the measured count stays full');
        $this->assertCount(ThemeAnalyzer::TOP_CLUSTERS, $theme['cluster_ids']);
        $this->assertSame(12 - ThemeAnalyzer::TOP_CLUSTERS, $theme['cluster_ids_truncated']);
        $this->assertSame(['big'], $theme['dirs']);
        $this->assertSame(0, $theme['dirs_truncated']);
    }

    // -------------------------------------------------------------- dials

    public function testMinClustersDialRejectsBelowTwo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ThemeAnalyzer(relRoots: [], minClustersPerTheme: 1);
    }

    public function testMinClustersDialRaisesTheBar(): void
    {
        $a = $this->cluster('ca', [$this->synthBlock(self::ROOT . '/src/a.php', 1, 10)], ['tag']);
        $b = $this->cluster('cb', [$this->synthBlock(self::ROOT . '/src/b.php', 1, 10)], ['tag']);
        $payload = (new ThemeAnalyzer(relRoots: [self::ROOT], minClustersPerTheme: 3))->analyze([$a, $b], null);
        $this->assertSame(0, $payload['totals']['themes'], 'a coverage-2 seed cannot theme under a bar of 3');
        $this->assertSame(2, $payload['totals']['themeless_clusters']);
    }
}
