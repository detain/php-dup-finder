<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Clustering;

use PhpParser\Node;
use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Cli\ConfigLoader;
use Phpdup\Clustering\CandidateBudgetException;
use Phpdup\Clustering\Clusterer;
use Phpdup\Extraction\Block;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Index\BlockIndex;
use Phpdup\Ml\MlPairClient;
use Phpdup\Similarity\ContainmentSimilarity;
use Phpdup\Similarity\JaccardSimilarity;
use Phpdup\Similarity\TreeEditDistance;
use Phpdup\Util\LineRange;
use Symfony\Component\Console\Output\NullOutput;

/**
 * E1 — F3 Σmin multiset upper-bound filter (improve_logic.md item 0.5;
 * improve_logic_info.md §3 'The 80 GB memory story': the multiset bound
 * Σ_v min(a_v, b_v) >= the scorer's exact evidence, measured there at
 * 42-46x pair reduction with 0/640k true edges lost).
 *
 * The filter runs inside Clusterer::generateCandidatePairs() and may only
 * DROP pairs whose upper bound provably cannot reach either acceptance
 * gate (Jaccard >= 0.80, or containment >= 0.85 with size ratio >= 0.6 at
 * the shipped defaults), never pairs the scorer could turn into an edge.
 * Equal structuralHash pairs are vetoed from the kill (the scorer's exact
 * lane short-circuits BEFORE bags); the §5a R-4 guard-injected numbered
 * stream bypasses the filter; the IR/ML tiers are inert (their verdicts
 * are not bag-gated). The emitted stream therefore stays an EXACT ORDERED
 * SUBSEQUENCE of the unfiltered enumeration and the scored edge set — and
 * every cluster built from it — stays byte-identical filter-on vs
 * filter-off. Both engines are covered by construction: serial and fork
 * consume the same parent-side stream, and the fork zone only ever
 * SCORES dispatched pairs (PairScoreWorker enumerates nothing).
 *
 * The config-file escape key (candidate_mass_filter), its stage-threading
 * arm, and the {on/off} x {serial/fork} stage-square test ride the
 * follow-on commit that adds the Config leg (the concurrent schema lane
 * currently owns Config.php/ConfigLoader.php); the engine-side filter is
 * default-ON and fully pinned here via the Clusterer constructor knob.
 *
 * Corpus arithmetic is hand-audited pair by pair: N blocks -> df cap
 * max(2, ceil(N * 0.01)); proposed seeds must have df <= cap; the gate
 * formulas are J >= I/(|A|+|B|-I) >= 0.80 (needJ = 0.8*(|A|+|B|)/1.8) and
 * C >= tC*massMin with ratio >= minOverlap.
 */
final class CandidateMassFilterTest extends TestCase
{
    // ------------------------------------------------------------------
    // harness
    // ------------------------------------------------------------------

    private function makeBlock(string $id, array $ngramBag, string $structuralHash): Block
    {
        static $ast = null;
        $ast ??= new Node\Stmt\Return_(new Node\Scalar\String_('same'));

        $block = new Block(
            file: 'synthetic.php',
            range: new LineRange(1, 1),
            kind: 'function',
            namespace: null,
            class: null,
            name: null,
            ast: $ast,
        );
        $block->id = $id;
        $block->structuralHash = $structuralHash;
        $block->ngramBag = $ngramBag;
        // The serial TED lane compares canonical trees; synthetic fixtures
        // share one literal so every jaccard-passing pair clears the tree
        // gate — the bag arithmetic is what these arms exercise.
        $block->canonical = $ast;
        return $block;
    }

    /** @param array<string,array{bag:array<string,int>,hash:string}> $defs */
    private function buildIndex(array $defs): BlockIndex
    {
        $index = new BlockIndex();
        foreach ($defs as $id => $def) {
            $index->add($this->makeBlock($id, $def['bag'], $def['hash']));
        }
        return $index;
    }

    /** @param array<string,mixed> $extra */
    private function newClusterer(bool $filter, array $extra = []): Clusterer
    {
        // Spread-only call (no named args before it): string-keyed unpack
        // binds the named constructor parameters, and PHP forbids mixing a
        // leading named argument with a trailing spread.
        $args = [
            'similarity' => new JaccardSimilarity(),
            'tree' => new TreeEditDistance(),
            'candidateMassFilter' => $filter,
        ] + $extra;
        return new Clusterer(...$args);
    }

    /** @return list<array{0:string,1:string}> */
    private function collect(Clusterer $clusterer, BlockIndex $index, ?int $budget = null): array
    {
        $pairs = [];
        foreach ($clusterer->generateCandidatePairs($index, null, $budget) as $pair) {
            $pairs[] = $pair;
        }
        return $pairs;
    }

    private function pairKey(string $a, string $b): string
    {
        return strcmp($a, $b) < 0 ? $a . "\0" . $b : $b . "\0" . $a;
    }

    /**
     * Pairs OFF proposed that ON dropped (membership form; order is pinned
     * separately by the subsequence assertion).
     *
     * @param list<array{0:string,1:string}> $off
     * @param list<array{0:string,1:string}> $on
     * @return list<array{0:string,1:string}>
     */
    private function dropList(array $off, array $on): array
    {
        $keep = [];
        foreach ($on as [$a, $b]) {
            $keep[$this->pairKey($a, $b)] = true;
        }
        $drops = [];
        foreach ($off as [$a, $b]) {
            if (!isset($keep[$this->pairKey($a, $b)])) {
                $drops[] = [$a, $b];
            }
        }
        return $drops;
    }

    /** @param list<array{0:string,1:string}> $off @param list<array{0:string,1:string}> $on */
    private function assertStrictSubsequence(array $off, array $on, int $expectedDrops): void
    {
        $this->assertCount(count($off) - $expectedDrops, $on, 'stream size must equal OFF minus exactly the drops');
        $i = 0;
        foreach ($off as $pair) {
            if ($i < count($on) && $on[$i] === $pair) {
                $i++;
            }
        }
        $this->assertSame(count($on), $i, 'ON stream must be an EXACT ORDERED subsequence of OFF');
    }

    /**
     * THE SOUNDNESS CHECK: re-score a dropped pair with the very classes
     * the scorer uses. A bag-live drop is a losslessness violation unless
     * the exact-hash veto covers it.
     */
    private function bagsCouldScoreEdge(Block $a, Block $b): bool
    {
        $jac = (new JaccardSimilarity())->similarity($a->ngramBag ?? [], $b->ngramBag ?? []);
        if ($jac >= 0.80) {
            return true;
        }
        $cs   = (new ContainmentSimilarity())->containmentAndSizeRatio($a->ngramBag ?? [], $b->ngramBag ?? []);
        $cont = $cs['containment'];
        $ratio = $cs['sizeRatio'];
        return $cont >= 0.85 && $ratio >= 0.6;
    }

    /**
     * ON vs OFF over one index: kills exist, the stream stays an ordered
     * subsequence, and no drop could have become an edge. Returns drops.
     *
     * @return list<array{0:string,1:string}>
     */
    private function assertFilterIsSound(BlockIndex $index, string $ctx = ''): array
    {
        $off = $this->collect($this->newClusterer(false), $index);
        $on = $this->collect($this->newClusterer(true), $index);
        $drops = $this->dropList($off, $on);
        $this->assertNotEmpty($drops, "the filter must kill at least one pair ($ctx)");
        $this->assertStrictSubsequence($off, $on, count($drops));
        foreach ($drops as [$aId, $bId]) {
            $a = $index->get($aId);
            $b = $index->get($bId);
            $this->assertNotNull($a);
            $this->assertNotNull($b);
            $this->assertTrue(
                $a->structuralHash === $b->structuralHash || !$this->bagsCouldScoreEdge($a, $b),
                "losslessness violated: filter dropped ($aId,$bId) which full scoring would accept ($ctx)",
            );
        }
        return $drops;
    }

    /** @return list<array{0:string,1:string,2:float,3:string}> */
    private function scoreEdges(BlockIndex $index, bool $filter): array
    {
        $clusterer = $this->newClusterer($filter);
        $edges = (new \ReflectionMethod(Clusterer::class, 'scorePairsSerially'))->invoke($clusterer, $index);
        /** @var list<array{0:string,1:string,2:float,3:string}> $edges */
        return $edges;
    }

    /** @param list<array{0:string,1:string,2:float,3:string}> $edges @return list<string> */
    private function edgeCanonical(array $edges): array
    {
        $rows = [];
        foreach ($edges as [$a, $b, $score, $tier]) {
            $rows[] = $this->pairKey($a, $b) . '|' . round($score, 6) . '|' . $tier;
        }
        sort($rows);
        return $rows;
    }

    /**
     * @param list<array{0:string,1:string,2:float,3:string}> $edges
     * @return list<string>
     */
    private function clusterCanonical(BlockIndex $index, array $edges): array
    {
        $clusters = $this->newClusterer(true)->cluster($index, $edges);
        $rows = [];
        foreach ($clusters as $c) {
            $members = array_map(static fn(Block $b): string => $b->id, $c->members);
            sort($members);
            $rows[] = $c->id . '|' . round($c->similarity, 6) . '|' . implode(',', $members);
        }
        sort($rows);
        return $rows;
    }

    /** @param list<array{0:string,1:string}> $stream */
    private function streamHas(array $stream, string $a, string $b): bool
    {
        return in_array([$a, $b], $stream, true) || in_array([$b, $a], $stream, true);
    }

    // ------------------------------------------------------------------
    // the main synthetic corpus (N=123 -> df cap 2) — every pair class
    // hand-audited against the shipped 0.80 / 0.85 / 0.6 gates
    // ------------------------------------------------------------------

    /**
     * @return array<string,array{bag:array<string,int>,hash:string}>
     *
     * Class A — 20 family keeper pairs (40 blocks): members A/B share 20
     *   grams at count 2 (df 2, PROPOSED and scored) + anchor 'ck_f' (df 2,
     *   count 1) + 3 unique tails each: Σmin = 40+1 = 41, mass 44 each,
     *   needJ = 0.8*88/1.8 = 39.1 <= ub 41 -> KEPT; true J = 41/47 = 0.872
     *   -> jaccard edge on both sides.
     *
     * Class B — 30 dead twin groups (60 blocks): anchor df 2 passes as a
     *   seed but the mass dump 'q' rides all 60 twins + X/Y/Z (df 63 > cap,
     *   skip-only): Σmin-pass = 1, credit min(1,1) = 1 -> ub = min(8, 2) = 2
     *   vs needJ 7.11 and needC 6.8 -> KILLED. True J = 2/14 = 0.143,
     *   C = 2/8 = 0.25 — a sound kill of a dead pair. (The q mass is
     *   deliberately symmetric-but-insufficient: the credit leg must still
     *   not reach the gates.)
     *
     * Class C — 10 rare-gram stranger pairs (20 blocks): shared 'pair_p'
     *   (df 2, count 1) + exclusive grams: Σmin = 1, masses 2/2,
     *   needJ = 0.8*4/1.8 = 1.78 > 1, needC = 0.85*2 = 1.7 > 1 -> KILLED.
     *
     * Class D — skip-credit mixer trio: 'm_rare' df 2 passes; 'm_f' lands
     *   on X/Y/Z (df 3 > cap 2) as skipped mass. X-Y: Σmin-pass 1, credit
     *   min(100,90) = 90 -> ub = min(91, 91) = 91 >= needJ 0.8*193/1.8 =
     *   85.78 -> KEPT (true J = 91/102 = 0.8922, a jaccard edge only the
     *   credit leg could certify). X-Z / Y-Z are never even proposed (all
     *   their shared grams are df-culled seeds).
     *
     * OFF stream = 20 + 30 + 10 + 1 = 61; ON stream = 20 + 1 = 21.
     */
    private function mainCorpus(): array
    {
        $defs = [];
        for ($f = 0; $f < 20; $f++) {
            $a = ['ck_f' . $f => 1];
            $b = ['ck_f' . $f => 1];
            for ($i = 0; $i < 20; $i++) {
                $a['core_f_' . $i] = 2;
                $b['core_f_' . $i] = 2;
            }
            for ($t = 0; $t < 3; $t++) {
                $a['ta_' . $t] = 1;
                $b['tb_' . $t] = 1;
            }
            $defs["A{$f}"] = ['bag' => $a, 'hash' => "ha_$f"];
            $defs["B{$f}"] = ['bag' => $b, 'hash' => "hb_$f"];
        }
        for ($g = 0; $g < 30; $g++) {
            $da = ['a' . $g => 1, 'q' => 1];
            $db = ['a' . $g => 1, 'q' => 1];
            for ($i = 1; $i <= 6; $i++) {
                $da['da_' . $g . '_' . $i] = 1;
                $db['db_' . $g . '_' . $i] = 1;
            }
            $defs["D{$g}a"] = ['bag' => $da, 'hash' => "hd_{$g}_a"];
            $defs["D{$g}b"] = ['bag' => $db, 'hash' => "hd_{$g}_b"];
        }
        for ($p = 0; $p < 10; $p++) {
            $defs["S{$p}a"] = ['bag' => ['pair_' . $p => 1, 'sa' . $p => 1], 'hash' => "hs_{$p}_a"];
            $defs["S{$p}b"] = ['bag' => ['pair_' . $p => 1, 'sb' . $p => 1], 'hash' => "hs_{$p}_b"];
        }
        $defs['X'] = ['bag' => ['m_rare' => 2, 'm_f' => 100], 'hash' => 'hx'];
        $defs['Y'] = ['bag' => ['m_rare' => 1, 'm_f' => 90], 'hash' => 'hy'];
        $defs['Z'] = ['bag' => ['m_f' => 95, 'zu' => 1], 'hash' => 'hz'];
        return $defs;
    }

    // ------------------------------------------------------------------
    // core losslessness arms
    // ------------------------------------------------------------------

    public function testKillStreamIsOrderedSubsequenceAndEveryDropIsADeadPair(): void
    {
        $index = $this->buildIndex($this->mainCorpus());
        $drops = $this->assertFilterIsSound($index, 'main corpus');
        $this->assertCount(40, $drops, 'the hand-audited corpus must kill exactly the B and C classes');
    }

    public function testKilledSetIsExactlyThePredictedPairsOnTheMainCorpus(): void
    {
        $index = $this->buildIndex($this->mainCorpus());
        $off = $this->collect($this->newClusterer(false), $index);
        $on = $this->collect($this->newClusterer(true), $index);

        $this->assertCount(61, $off, 'OFF stream must match the audited pair census');
        $this->assertCount(21, $on, 'ON stream must match the audited survivor census');

        $this->assertTrue($this->streamHas($on, 'X', 'Y'), 'skip-credited mixer pair must survive');
        $this->assertTrue($this->streamHas($on, 'A0', 'B0'), 'family keeper must survive');
        $this->assertFalse($this->streamHas($on, 'D0a', 'D0b'), 'shared-dump twin must be killed');
        $this->assertFalse($this->streamHas($on, 'S0a', 'S0b'), 'rare-gram stranger twin must be killed');
        $this->assertStrictSubsequence($off, $on, count($this->dropList($off, $on)));
    }

    public function testEdgesAndClustersAreByteIdenticalAcrossTheFilter(): void
    {
        $index = $this->buildIndex($this->mainCorpus());
        $eOff = $this->edgeCanonical($this->scoreEdges($index, false));
        $eOn = $this->edgeCanonical($this->scoreEdges($index, true));
        $this->assertNotEmpty($eOff, 'the corpus must mint edges (non-vacuity)');
        $this->assertSame($eOff, $eOn, 'scored edges byte-identical filter-on vs filter-off');
        $this->assertSame(
            $this->clusterCanonical($index, $this->scoreEdges($index, false)),
            $this->clusterCanonical($index, $this->scoreEdges($index, true)),
            'clusters byte-identical filter-on vs filter-off',
        );
        $this->assertContains(
            $this->pairKey('X', 'Y') . '|0.892157|jaccard',
            $eOn,
            'the skip-credit pair must land its edge under the filter',
        );
    }

    public function testSelectivityMeetsTheDocumentedFloorOnAStormCorpus(): void
    {
        // The §3 story is a clone farm: families of same-tail keepers must
        // ALL survive the bound while dead-but-proposed strays (shared
        // anchors carrying near-zero Σmin) die on it. 40 families of 5
        // with straddling tail sizes (every straddle lane stays above at
        // least one gate), 60 dump twins whose only real mass is a
        // df-culled shared gram, and 60 rare-gram stranger pairs.
        // Reduction floor pinned far below the §3 production measurement
        // (42-46x) so the arm stays deterministic: 520 -> 400 here.
        $defs = [];
        for ($f = 0; $f < 40; $f++) {
            for ($m = 0; $m < 5; $m++) {
                $bag = [];
                for ($i = 0; $i < 20; $i++) {
                    $bag["core_{$f}_{$i}"] = 2;
                }
                $tail = ($m < 2) ? 2 : (($m < 4) ? 6 : 10);
                for ($t = 0; $t < $tail; $t++) {
                    $bag["t_{$f}_{$m}_{$t}"] = 1;
                }
                $defs["G{$f}_{$m}"] = ['bag' => $bag, 'hash' => "hg_{$f}_{$m}"];
            }
        }
        for ($g = 0; $g < 60; $g++) {
            $defs["E{$g}a"] = ['bag' => ['eanchor' . $g => 1, 'edump' => 1, 'ea_' . $g . '_1' => 1, 'ea_' . $g . '_2' => 1, 'ea_' . $g . '_3' => 1], 'hash' => "he_{$g}_a"];
            $defs["E{$g}b"] = ['bag' => ['eanchor' . $g => 1, 'edump' => 1, 'eb_' . $g . '_1' => 1, 'eb_' . $g . '_2' => 1], 'hash' => "he_{$g}_b"];
        }
        for ($p = 0; $p < 60; $p++) {
            $defs["P{$p}a"] = ['bag' => ['r' . $p => 1, 'pa' . $p => 1], 'hash' => "hp_{$p}_a"];
            $defs["P{$p}b"] = ['bag' => ['r' . $p => 1, 'pb' . $p => 1], 'hash' => "hp_{$p}_b"];
        }
        $index = $this->buildIndex($defs);
        $drops = $this->assertFilterIsSound($index, 'storm corpus');
        $off = $this->collect($this->newClusterer(false), $index);
        $on = $this->collect($this->newClusterer(true), $index);
        $reduction = count($off) / max(1, count($on));
        $this->assertGreaterThanOrEqual(1.2, $reduction,
            'the filter must cut real candidate volume on the clone farm (§3 measures 42-46x on the production storm)');
        $this->assertGreaterThan(100, count($drops), 'the farm must supply hundreds of dead-but-proposed pairs');
        $this->assertSame(
            $this->edgeCanonical($this->scoreEdges($index, false)),
            $this->edgeCanonical($this->scoreEdges($index, true)),
            'storm edges byte-identical across the filter',
        );
    }

    // ------------------------------------------------------------------
    // targeted bound-leg arms
    // ------------------------------------------------------------------

    public function testSkipMassCreditKeepsWhatSharedPassingGramsAloneCannot(): void
    {
        // Rescue pair A/B: mass evidence is dominated by df-FAILED grams
        // ('hot' across the U-family); only the exact skip-min credit can
        // lift the bound over needJ. A shared-passing-grams-only bound
        // (min 1) would be a FALSE kill — this arm pins the credit leg.
        $defs = [
            'A' => ['bag' => ['p' => 1, 'hot' => 100], 'hash' => 'h_a'],
            'B' => ['bag' => ['p' => 2, 'hot' => 90], 'hash' => 'h_b'],
        ];
        for ($i = 0; $i < 100; $i++) {
            $defs["U$i"] = ['bag' => ['hot' => 1 + ($i % 3), 'u' . $i => 1], 'hash' => 'h_u' . $i];
        }
        $index = $this->buildIndex($defs);
        $off = $this->collect($this->newClusterer(false), $index);
        $on = $this->collect($this->newClusterer(true), $index);
        $this->assertTrue($this->streamHas($off, 'A', 'B'), 'A-B must be proposed (shared rare gram p)');
        $this->assertTrue($this->streamHas($on, 'A', 'B'), 'and kept ONLY thanks to the skip-mass credit');
        $this->assertSame($off, $on, 'the rescue corpus has no sound kills — stream must be untouched');
    }

    public function testExactHashVetoKeepsInconsistentFixtureAndScoringStaysIdentical(): void
    {
        // V/W share structuralHash 'hash_veto'. Their bags are dead by the
        // bound (passing evidence 1, credit 45 against needJ 44.9 — just
        // under, and J is checked on passing grams only: ub = min(48, 46) =
        // 46 vs needJ 44.9 keeps it on the JARM; the point is the KILL
        // lane never runs because the veto stands). W1/W2 have distinct
        // hashes and dead bags -> killed (the veto is not a blanket keep).
        $defs = [];
        for ($i = 0; $i < 26; $i++) {
            $defs["H$i"] = ['bag' => ['vs' => 2, "u_$i" => 1], 'hash' => 'hash_other_' . $i];
        }
        $defs['V'] = ['bag' => ['vv' => 1, 'vs' => 50, 'vx' => 1], 'hash' => 'hash_veto'];
        $defs['W'] = ['bag' => ['vv' => 1, 'vs' => 45, 'wy' => 1], 'hash' => 'hash_veto'];
        $defs['W1'] = ['bag' => ['w1_r' => 1, 'w1_a' => 1, 'w1_b' => 1], 'hash' => 'hash_w1'];
        $defs['W2'] = ['bag' => ['w1_r' => 1, 'w2_a' => 1], 'hash' => 'hash_w2'];
        $index = $this->buildIndex($defs);

        // The veto is what the drops audit below proves: V/W may never drop,
        // while a bag-dead pair with a DISTINCT hash does.
        $drops = $this->assertFilterIsSound($index, 'hash-veto corpus');
        foreach ($drops as [$a, $b]) {
            $this->assertFalse(
                ($a === 'V' && $b === 'W') || ($a === 'W' && $b === 'V'),
                'exact-hash veto violated: the filter dropped a hash-equal pair',
            );
        }
        $this->assertTrue($this->streamHas($this->collect($this->newClusterer(false), $index), 'W1', 'W2'));
        $this->assertFalse($this->streamHas($this->collect($this->newClusterer(true), $index), 'W1', 'W2'),
            'dead distinct-hash pair W1/W2 must be killed by the bound');

        $edges = $this->edgeCanonical($this->scoreEdges($index, true));
        $vw = $this->pairKey('V', 'W');
        $vwhits = array_values(array_filter($edges, static fn(string $r): bool => str_starts_with($r, $vw . '|')));
        $this->assertCount(1, $vwhits, 'the hash-equal pair must mint its exact edge under the filter');
    }

    public function testChainLinksAreKilledAndStreamStaysBoundedInTime(): void
    {
        // 400-block chain: bridges at df 2 (<= cap 4 at N=400) keep links
        // PROPOSED, but each link's Σmin (3) sits far under needJ (~40)
        // with no skip mass -> every link is a sound kill. The generous
        // wall assertion only forbids pathological slowdown.
        $defs = [];
        $n = 400;
        for ($i = 0; $i < $n; $i++) {
            $bag = ['tail' . $i => 40];
            for ($k = max(0, $i - 1); $k <= min($i, $n - 2); $k++) {
                $bag['bridge_' . $k] = 3;
            }
            $defs["C$i"] = ['bag' => $bag, 'hash' => "hc_$i"];
        }
        $index = $this->buildIndex($defs);
        $t0 = microtime(true);
        $off = $this->collect($this->newClusterer(false), $index);
        $offMs = (microtime(true) - $t0) * 1000;
        $t1 = microtime(true);
        $on = $this->collect($this->newClusterer(true), $index);
        $onMs = (microtime(true) - $t1) * 1000;
        $drops = $this->dropList($off, $on);
        $this->assertCount($n - 1, $off, 'one proposed link per adjacent pair');
        $this->assertNotEmpty($drops, 'dead-but-proposed links must die (tails are pure exclusive mass)');
        $this->assertStrictSubsequence($off, $on, count($drops));
        $this->assertLessThanOrEqual(max(1000.0, 2 * $offMs), $onMs,
            'the filter may cut work, never multiply it beyond a generous bound');
    }

    public function testZeroThresholdMakesTheFilterInert(): void
    {
        // needJ = 0 <= ub for every pair: the stream must equal OFF exactly.
        $index = $this->buildIndex($this->mainCorpus());
        $this->assertSame(
            $this->collect($this->newClusterer(false, ['similarityThreshold' => 0.0]), $index),
            $this->collect($this->newClusterer(true, ['similarityThreshold' => 0.0]), $index),
        );
    }

    // ------------------------------------------------------------------
    // inertness matrix
    // ------------------------------------------------------------------

    public function testFilterIsInertUnderIrScoringAndMlPairTier(): void
    {
        $index = $this->buildIndex($this->mainCorpus());
        $this->assertSame(
            $this->collect($this->newClusterer(false, ['irScoring' => true]), $index),
            $this->collect($this->newClusterer(true, ['irScoring' => true]), $index),
            'IR tier verdicts are not bag-gated — the filter must not prune its candidates',
        );
        $ml = ['mlPairClient' => new MlPairClient('http://127.0.0.1:9')];
        $this->assertSame(
            $this->collect($this->newClusterer(false, $ml), $index),
            $this->collect($this->newClusterer(true, $ml), $index),
            'ML-pair tier can accept below the bag gates — same inertness law',
        );
    }

    public function testUnnumberedGuardInjectedPairsBypassTheFilter(): void
    {
        // Two R-4 lanes, one corpus. R1/R2 share only a df-FAILED gram in
        // their live bags (p_hot, df 4 > cap 2) — base never proposes them
        // and the bound would kill them if it saw them (ub 3 vs needJ 4).
        // Their numbered canonicals DO share grams, so the guard injects
        // the pair, and injected pairs enter untested: they appear in the
        // ON stream (the bypass, byte-equal to the guard-only OFF lane).
        // P/Q is the opposite lane: a BASE suggestion that the bound kills
        // (credit min(30,5) reaches C's mass but the 7/32 ratio gate
        // refuses the straddle) and the injection dedup uses the RAW base
        // list, so it is NOT resurrected — outcome-equal, since the scorer
        // judges live bags in both modes and would reject it anyway.
        $parser = (new \PhpParser\ParserFactory())->createForNewestSupportedVersion();
        $canonical = static fn(string $code): Node => $parser->parse($code)[0];

        $defs = [
            'P' => ['bag' => ['p_rare' => 1, 'p_u_p' => 1, 'p_hot' => 30], 'hash' => 'hp'],
            'Q' => ['bag' => ['p_rare' => 1, 'p_u_q' => 1, 'p_hot' => 5], 'hash' => 'hq'],
            'R1' => ['bag' => ['p_hot' => 3, 'r1' => 1], 'hash' => 'hr1'],
            'R2' => ['bag' => ['p_hot' => 4, 'r2' => 1], 'hash' => 'hr2'],
        ];
        $index = new BlockIndex();
        foreach ($defs as $id => $def) {
            $block = $this->makeBlock($id, $def['bag'], $def['hash']);
            $block->canonical = match ($id) {
                'P' => $canonical('<?php function p() { $a = 1; $b = 2; return 3; }'),
                'Q' => $canonical('<?php function q() { $c = 1; $d = 2; return 3; }'),
                default => $canonical('<?php function ' . $id . '() { $e = 9; }'),
            };
            $index->add($block);
        }

        $guard = ['unnumberedCandidateGuard' => true];
        $offStream = $this->collect($this->newClusterer(false, $guard), $index);
        $onStream = $this->collect($this->newClusterer(true, $guard), $index);

        // Injection bypass: the guard-only pair lives in BOTH streams even
        // though the live-bag bound would kill it.
        $this->assertTrue($this->streamHas($offStream, 'R1', 'R2'), 'guard must inject R1/R2 (filter off)');
        $this->assertTrue($this->streamHas($onStream, 'R1', 'R2'), 'and the bound must not touch the injected slot');
        $r1 = $index->get('R1');
        $r2 = $index->get('R2');
        $this->assertNotNull($r1);
        $this->assertNotNull($r2);
        $this->assertFalse($this->bagsCouldScoreEdge($r1, $r2), 'the bypassed pair is scorer-dead (why the bypass is harmless)');

        // Base-kill law: P/Q dies in ON on both lanes (guard on or off).
        $this->assertTrue($this->streamHas($offStream, 'P', 'Q'), 'P/Q is base-proposed under the guard');
        $this->assertFalse($this->streamHas($onStream, 'P', 'Q'), 'and the kill is not resurrected by injection');

        $plainOff = $this->collect($this->newClusterer(false), $index);
        $plainOn = $this->collect($this->newClusterer(true), $index);
        $this->assertTrue($this->streamHas($plainOff, 'P', 'Q'), 'P/Q must be a base candidate without the guard');
        $this->assertFalse($this->streamHas($plainOn, 'P', 'Q'), 'and the bound must kill it when nothing restores it');
        $this->assertStrictSubsequence($offStream, $onStream, count($this->dropList($offStream, $onStream)));
    }

    // ------------------------------------------------------------------
    // budget semantics under the filter
    // ------------------------------------------------------------------

    public function testBudgetCountsSurvivorsAndKeepsThePrefixContract(): void
    {
        // A keep-everything chain (threshold 0 makes every bound pass): the
        // F3 stream must equal OFF and the budget's exact-prefix contract
        // from GenerationBudgetTest holds unchanged.
        $defs = [];
        $n = 30;
        for ($i = 0; $i < $n; $i++) {
            $bag = [];
            for ($k = max(0, $i - 1); $k <= min($i, $n - 2); $k++) {
                $bag['bridge_' . $k] = 1;
            }
            $bag['tail_' . $i] = 1;
            $defs["N$i"] = ['bag' => $bag, 'hash' => "hn_$i"];
        }
        $index = $this->buildIndex($defs);
        $zero = ['similarityThreshold' => 0.0];
        $offAll = $this->collect($this->newClusterer(false, $zero), $index);
        $onAll = $this->collect($this->newClusterer(true, $zero), $index);
        $this->assertSame($offAll, $onAll, 'a keep-everything filter must leave the budget lane untouched');

        [$prefix, $e] = $this->collectUntilHalt($this->newClusterer(true, $zero), $index, 15);
        $this->assertCount(15, $prefix, 'the halt preserves the exact prefix contract (budget 15)');
        $this->assertSame(array_slice($offAll, 0, 15), $prefix);
        $this->assertStringContainsString('budget of 15', $e->getMessage());

        // A killing corpus: the OFF stream breaches a budget the ON stream
        // fits entirely under (survivor counting relieves the guard).
        $storm = $this->buildIndex($this->mainCorpus());
        $offCount = count($this->collect($this->newClusterer(false), $storm));
        $onSurvivors = $this->collect($this->newClusterer(true), $storm);
        $this->assertLessThan($offCount, count($onSurvivors), 'the filter must relieve budget pressure');
        $this->assertCount(count($onSurvivors), $this->collect($this->newClusterer(true), $storm, count($onSurvivors)));
        $this->expectException(CandidateBudgetException::class);
        $this->collect($this->newClusterer(false), $storm, $offCount - 1);
    }

    /** @return array{0:list<array{0:string,1:string}>,1:CandidateBudgetException} */
    private function collectUntilHalt(Clusterer $clusterer, BlockIndex $index, int $budget): array
    {
        $pairs = [];
        try {
            foreach ($clusterer->generateCandidatePairs($index, null, $budget) as $pair) {
                $pairs[] = $pair;
            }
        } catch (CandidateBudgetException $e) {
            return [$pairs, $e];
        }
        $this->fail(sprintf('expected CandidateBudgetException at budget %d', $budget));
    }


    public function testEscapeKeyDefaultsAndRoundTripsWithoutACliFlag(): void
    {
        $this->assertTrue((new Config(paths: ['/x'], exclude: []))->candidateMassFilter,
            'default-ON: the §3 proof measured 42-46x with 0/640k edge loss');

        $dir = sys_get_temp_dir() . '/phpdup-cmf-' . bin2hex(random_bytes(5));
        mkdir($dir, 0o775, true);
        $cfgFile = $dir . '/phpdup.json';
        file_put_contents($cfgFile, json_encode(['candidate_mass_filter' => false]));
        $config = (new ConfigLoader())->load([$dir], $cfgFile, ['paths' => [$dir]]);
        $this->assertFalse($config->candidateMassFilter, 'root config file must flip the escape hatch off');

        // Root-only: a planted per-directory copy is refused loudly.
        $sub = $dir . '/app';
        mkdir($sub, 0o775, true);
        file_put_contents($sub . '/.phpdup.json', json_encode(['candidate_mass_filter' => false]));
        try {
            (new ConfigLoader())->load([$dir . '/app'], null, ['paths' => [$dir . '/app']]);
            $this->fail('per-directory candidate_mass_filter must be refused');
        } catch (\RuntimeException $ex) {
            $this->assertStringContainsString('candidate_mass_filter is not a per-directory key', $ex->getMessage());
        }
        @unlink($sub . '/.phpdup.json');
        @rmdir($sub);
        unlink($cfgFile);
        @rmdir($dir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('candidate_mass_filter must be a boolean');
        (new ConfigLoader())->validate(['candidate_mass_filter' => 1]);
    }

    public function testFilterKnobDoesNotInvalidateTheClusterCache(): void
    {
        // Counter-legend pin: unlike max_ted_size, this knob is byte-identity
        // lawful on both sides, so a snapshot warmed on one side is STILL
        // VALID on the other — binding it would only waste cache space.
        $stage = new ClusterStage(exactOnly: false, useClusterCache: false);
        $key = new \ReflectionMethod(ClusterStage::class, 'cacheConfigKey');
        $on = new Config(paths: ['/x'], exclude: [], candidateMassFilter: true);
        $off = new Config(paths: ['/x'], exclude: [], candidateMassFilter: false);
        $this->assertSame($key->invoke($stage, $on), $key->invoke($stage, $off),
            'the escape hatch must NOT fork the cluster cache namespace (both sides are proven equivalent)');
    }

    public function testStageThreadsTheKnobIntoBothEngines(): void
    {
        // The parent-side stream is the ONLY enumeration point in the
        // product (serial and fork consume it), so the twin law holds by
        // placement — this drives scan -> preprocess -> cluster on real
        // PHP and checks every corner of the {on/off} x {serial/fork}
        // square with real preprocessed bags.
        $dir = sys_get_temp_dir() . '/phpdup-cmf-stage-' . bin2hex(random_bytes(5));
        mkdir($dir, 0o775, true);
        $seed = "  \$a = 1;\n  \$b = 2;\n  \$c = 3;\n";
        for ($i = 0; $i < 6; $i++) {
            file_put_contents($dir . "/fam_{$i}.php", "<?php function fam_{$i}() {\n{$seed}  return \$c;\n}\n");
        }
        mt_srand(7);
        for ($i = 0; $i < 30; $i++) {
            $body = '';
            for ($k = 0; $k < 6 + mt_rand(0, 6); $k++) {
                $body .= "  \$w{$i}_{$k} = {$k};\n";
            }
            file_put_contents($dir . "/var_{$i}.php", "<?php function var_{$i}() {\n{$seed}{$body}  return 1;\n}\n");
        }

        $run = static function (int $workers, bool $filter) use ($dir): PipelineState {
            $config = new Config(
                paths: [$dir],
                exclude: [],
                maxDocumentFrequency: 1.0,
                workers: $workers,
                candidateMassFilter: $filter,
                incremental: false,
            );
            $state = new PipelineState($config);
            $out = new NullOutput();
            (new ScanningStage())->run($state, $out);
            (new PreprocessStage(useCache: false))->run($state, $out);
            (new ClusterStage(exactOnly: false, useClusterCache: false))->run($state, $out);
            return $state;
        };

        $serialOff = $run(1, false);
        $serialOn = $run(1, true);
        $this->assertNotEmpty($serialOff->edges, 'the stage corpus must mint edges (non-vacuity)');
        $this->assertLessThan($serialOff->candidatePairs, $serialOn->candidatePairs,
            'the stage-level corpus must exercise kills through the real pipeline');
        $this->assertSame(
            $this->edgeCanonical($serialOff->edges),
            $this->edgeCanonical($serialOn->edges),
            'serial engine: edges byte-identical filter-on vs filter-off',
        );

        if (!\function_exists('pcntl_fork')) {
            $this->addToAssertionCount(1);
            return;
        }
        $forkOff = $run(3, false);
        $forkOn = $run(3, true);
        $this->assertGreaterThanOrEqual(64, $forkOff->candidatePairs, 'the fork path only arms at >= 64 pairs');
        $this->assertSame(
            $this->edgeCanonical($serialOff->edges),
            $this->edgeCanonical($forkOff->edges),
            'twin law: fork and serial engines agree filter-off',
        );
        $this->assertSame(
            $this->edgeCanonical($serialOn->edges),
            $this->edgeCanonical($forkOn->edges),
            'twin law: fork and serial engines agree filter-on',
        );
    }

    public function testDefaultOnFilterNeverChangesRealBenchCorpusEdges(): void
    {
        // The shipped bench corpus through the real pipeline both ways: the
        // golden-suite stance expressed as a unit assertion (E2 holds the
        // Golden token; edges here must not move).
        $corpus = dirname(__DIR__, 3) . '/bench/corpora';
        if (!is_dir($corpus)) {
            $this->markTestSkipped('bench corpora absent');
        }
        $out = new NullOutput();
        $collect = static function (bool $filter) use ($corpus, $out): PipelineState {
            $config = new Config(paths: [$corpus], exclude: [], candidateMassFilter: $filter, incremental: false);
            $state = new PipelineState($config);
            (new ScanningStage())->run($state, $out);
            (new PreprocessStage(useCache: false))->run($state, $out);
            (new ClusterStage(exactOnly: false, useClusterCache: false))->run($state, $out);
            return $state;
        };
        $off = $collect(false);
        $on = $collect(true);
        $this->assertSame(
            $this->edgeCanonical($off->edges),
            $this->edgeCanonical($on->edges),
            'bench/corpora edges must be byte-identical across the filter',
        );
    }

// ---------------------------------------------------------------------------
}
