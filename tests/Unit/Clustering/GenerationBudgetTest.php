<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Clustering;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\CandidateBudgetException;
use Phpdup\Clustering\Clusterer;
use Phpdup\Extraction\Block;
use Phpdup\Index\BlockIndex;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\ProgressListener;
use Phpdup\Pipeline\Stage;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Similarity\JaccardSimilarity;
use Phpdup\Similarity\TreeEditDistance;
use Phpdup\Util\LineRange;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Generation-loop budget guard (improve_logic.md item 0.5, F3 leg).
 *
 * A clone family chains through rare-gram postings (the 167-member
 * sliding-window chain texture of filing 06 Demo A scaled to a storm), so
 * candidate-pair output grows quadratically in family size and — measured
 * at 2,400-3,400 pairs/block on the /home-my corpora — blew up to 80 GB
 * before scoring ever started. {@see Clusterer::generateCandidatePairs()}
 * now halts loudly at MAX_CANDIDATE_PAIRS instead of OOM-ing.
 *
 * Proven here:
 *   (a) a pathological chain/storm is capped — the guard fires at exactly
 *       the budget and the pre-halt stream is the exact prefix of the
 *       unguarded enumeration (never reordered, skipped, or duplicated);
 *   (b) normal corpora complete untouched — the armed-guard stream is
 *       byte-identical to the UNLIMITED (guard-off) stream;
 *   (c) engine-010 (engine-S10) cross-guard: capping pair COUNT bounds
 *       how many pairs enumerate, never what they COST — a 28-pair storm
 *       of expensive members sails under 60M and runs away in the TED
 *       tier. The aggregate node-product budget in scorePairsSerially
 *       (armed in TedSizeGateTest) closes that hole; here we pin that it
 *       (c1) halts storms the count guard cannot see and is a distinct
 *       exception from CandidateBudgetException, and (c2) never invents
 *       work for the count guard below its budget.
 *
 *   (d) once-037 (once-S33): the guard's halt must be reachable WITHOUT
 *       first materializing a budget-sized pair list. ClusterStage now
 *       streams the generator in bounded resident windows (its
 *       CANDIDATE_WINDOW), scoring each window as it fills; these arms pin
 *       the stage-level contract — halt position (before any pair is
 *       scored when the budget fits one fill), resident-progress
 *       announcement (the listener learns the count-so-far, not the full
 *       list), pair-for-pair identity between windowed and full-resident
 *       scoring, and the breach-above-window interleaving tax.
 */
final class GenerationBudgetTest extends TestCase
{
    private function clusterer(): Clusterer
    {
        // Candidate generation consults only maxDocumentFrequency; the
        // scoring thresholds are inert here (same as ClustererGeneratorTest).
        return new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.0,
            treeThreshold: 0.0,
            maxDocumentFrequency: 1.0,
        );
    }

    private function makeBlock(string $id, array $ngrams): Block
    {
        $block = new Block(
            file: 'synthetic.php',
            range: new LineRange(1, 1),
            kind: 'function',
            namespace: null,
            class: null,
            name: null,
            ast: new \PhpParser\Node\Stmt\Return_(new \PhpParser\Node\Scalar\String_($id)),
        );
        $block->id = $id;
        $block->structuralHash = 'hash_' . $id;
        $block->ngramBag = $ngrams;

        return $block;
    }

    /**
     * Sliding-window chain: gram g_j links blocks j…j+w-1, so member i is a
     * candidate of every member within w-1 steps — the 167-chain texture.
     * Unique unordered pairs: (w-1)·N − w·(w-1)/2.
     */
    private function chainIndex(int $n, int $w): BlockIndex
    {
        $index = new BlockIndex();
        for ($i = 0; $i < $n; $i++) {
            $bag = ['unique_' . $i => 1];
            for ($j = max(0, $i - $w + 1); $j <= min($i, $n - $w); $j++) {
                $bag['bridge_' . $j] = 1;
            }
            $index->add($this->makeBlock(sprintf('C%04d', $i), $bag));
        }
        return $index;
    }

    /** k mutually-similar blocks sharing one rare gram; pairs = k(k-1)/2. */
    private function cliqueIndex(int $k): BlockIndex
    {
        $index = new BlockIndex();
        for ($i = 0; $i < $k; $i++) {
            $index->add($this->makeBlock(sprintf('K%04d', $i), ['family_gram' => 1, 'unique_' . $i => 1]));
        }
        return $index;
    }

    /** n unrelated blocks; seeds zero candidates. */
    private function strangerIndex(int $n): BlockIndex
    {
        $index = new BlockIndex();
        for ($i = 0; $i < $n; $i++) {
            $index->add($this->makeBlock('S' . $i, ['only_' . $i => 1]));
        }
        return $index;
    }

    /** @return list<array{0:string,1:string}> */
    private function collect(Clusterer $clusterer, BlockIndex $index, ?int $budget): array
    {
        $pairs = [];
        foreach ($clusterer->generateCandidatePairs($index, null, $budget) as $pair) {
            $pairs[] = $pair;
        }
        return $pairs;
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

    private function fingerprint(array $pairs): string
    {
        return hash('sha256', (string)json_encode($pairs));
    }

    /** Analytic pair count of chainIndex(): gaps 1..w-1 over n blocks. */
    private function chainPairCount(int $n, int $w): int
    {
        return ($w - 1) * $n - $w * ($w - 1) / 2;
    }

    public function testChainPairMathematicsMatchesAnalyticCount(): void
    {
        // Foundation for every budget assertion below: the unguarded stream
        // is deterministic and equals the closed-form family pair mass.
        $pairs = $this->collect($this->clusterer(), $this->chainIndex(25, 3), Clusterer::UNLIMITED_CANDIDATE_PAIRS);

        $this->assertCount($this->chainPairCount(25, 3), $pairs);
        $this->assertSame($pairs, $this->collect($this->clusterer(), $this->chainIndex(25, 3), Clusterer::UNLIMITED_CANDIDATE_PAIRS),
            'enumeration must be deterministic across runs');
    }

    public function testArmedGuardHaltsPathologicalStormExactlyAtBudget(): void
    {
        // 30-member clique = 435 storm pairs; a budget of 100 must deliver
        // exactly 100 and then fail loud — not keep enumerating 435.
        $index = $this->cliqueIndex(30);
        $total = count($this->collect($this->clusterer(), $index, Clusterer::UNLIMITED_CANDIDATE_PAIRS));
        $this->assertSame(435, $total, 'the unguarded storm mass is what the guard exists to bound');

        [$stream, $exception] = $this->collectUntilHalt($this->clusterer(), $index, 100);

        $this->assertCount(100, $stream, 'the guard must yield exactly `budget` pairs before halting');

        $message = $exception->getMessage();
        $this->assertStringContainsString('100', $message, 'the message must name the breached budget');
        $this->assertStringContainsString('--max-df', $message, 'the message must cite the real knob that prunes the pair set');
        $this->assertStringContainsString('of 30 blocks', $message, 'the message must be honest about the corpus scale');
    }

    public function testHaltedStreamIsExactPrefixOfUnguardedEnumeration(): void
    {
        // The guard must not reorder, skip, or duplicate anything below the
        // cap: byte-identical prefix ⇒ any completed run equals master.
        $index = $this->chainIndex(60, 4);
        $unguarded = $this->collect($this->clusterer(), $index, Clusterer::UNLIMITED_CANDIDATE_PAIRS);
        $budget = intdiv(count($unguarded), 2);

        [$stream, $exception] = $this->collectUntilHalt($this->clusterer(), $index, $budget);

        $this->assertSame(array_slice($unguarded, 0, $budget), $stream,
            'pre-halt stream must equal the first `budget` pairs of the unguarded enumeration');
        $this->assertSame($this->fingerprint(array_slice($unguarded, 0, $budget)), $this->fingerprint($stream));
        $this->assertInstanceOf(CandidateBudgetException::class, $exception);
    }

    public function testNormalCorporaAreByteIdenticalGuardArmedVersusGuardOff(): void
    {
        // (b) of the lane mandate: on every corpus that completes today
        // (far below the 60M ceiling) the armed guard is a no-op.
        $corpora = [
            'small chain'   => $this->chainIndex(25, 3),
            'wide chain'    => $this->chainIndex(120, 6),
            'clique family' => $this->cliqueIndex(9),
            'strangers'     => $this->strangerIndex(12),
        ];

        foreach ($corpora as $name => $index) {
            $armed = $this->collect($this->clusterer(), $index, null);
            $off   = $this->collect($this->clusterer(), $index, Clusterer::UNLIMITED_CANDIDATE_PAIRS);

            $this->assertSame($off, $armed, "guard-armed output must be pair-for-pair identical to guard-off: $name");
            $this->assertSame($this->fingerprint($off), $this->fingerprint($armed), "byte identity: $name");
        }
    }

    public function testBudgetAtExactTotalCompletesOneShortHalts(): void
    {
        $index = $this->chainIndex(20, 3);
        $total = $this->chainPairCount(20, 3);

        $exact = $this->collect($this->clusterer(), $index, $total);
        $this->assertCount($total, $exact, 'a budget equal to the corpus must complete silently');

        [$shortStream, $exception] = $this->collectUntilHalt($this->clusterer(), $index, $total - 1);
        $this->assertCount($total - 1, $shortStream);
        $this->assertInstanceOf(CandidateBudgetException::class, $exception);
    }

    public function testPathologicalChainCannotExplodePairOutput(): void
    {
        // The lane's headline property: a 400-member, window-8 chain storm
        // (2,772 pairs) completes trivially under the armed default, and
        // under any tighter budget it halts AT the budget — bounded, always.
        $index = $this->chainIndex(400, 8);
        $total = $this->chainPairCount(400, 8);
        $this->assertSame(2772, $total);

        $armed = $this->collect($this->clusterer(), $index, null);
        $this->assertCount($total, $armed, 'the production ceiling never fires on a 2.7k-pair storm');

        [$stream, $exception] = $this->collectUntilHalt($this->clusterer(), $index, 1386);
        $this->assertCount(1386, $stream);
        $this->assertInstanceOf(CandidateBudgetException::class, $exception);
    }

    /** @dataProvider invalidBudgets */
    public function testNonPositiveBudgetIsRejected(int $budget): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('>= 1');
        $this->collect($this->clusterer(), $this->cliqueIndex(2), $budget);
    }

    /** @return array<string, array{0:int}> */
    public static function invalidBudgets(): array
    {
        return ['zero' => [0], 'negative' => [-5]];
    }

    public function testProductionCeilingIsPinnedWhereMeasuredCorporaStayFarBelow(): void
    {
        // Guard-rail on the constant itself: the largest corpus ever
        // completed (phlix, 18.8M pairs) must stay well under the budget,
        // and the observed death zone (~320M materialized at 80 GB) well above it.
        $this->assertSame(60_000_000, Clusterer::MAX_CANDIDATE_PAIRS);
        $this->assertGreaterThan(3 * 18_800_000, Clusterer::MAX_CANDIDATE_PAIRS,
            'completed corpora must sit under a third of the budget');
        $this->assertLessThan(intdiv(320_000_000, 5), Clusterer::MAX_CANDIDATE_PAIRS,
            'the budget must trip long before the historical 80-GB death zone');
    }

    // ------------------------------------------------------------------
    // engine-010 (engine-S10): the count guard is BLIND to cost.
    //
    // The finding's storm enumerated only tens of thousands of pairs —
    // nowhere near MAX_CANDIDATE_PAIRS — but every pair was two ~700-node
    // trees, each verdict costing seconds; the run never finished. Here
    // the synthetic makeBlock() family is given explicit large sizes and
    // cheap identical canonicals, so the aggregate charge (size x size
    // per judged pair) is fully analytic while the wall time stays test-
    // safe. The real-node economics live in TedSizeGateTest.
    // ------------------------------------------------------------------

    /** @return list<Block> scoreable near-cap-size clique members */
    private function expensiveClique(int $k, int $size): array
    {
        $blocks = [];
        for ($i = 0; $i < $k; $i++) {
            $block                   = $this->makeBlock(sprintf('E%04d', $i), ['family_gram' => 1, 'unique_' . $i => 1]);
            $block->size             = $size;
            $block->canonical        = new \PhpParser\Node\Stmt\Return_(new \PhpParser\Node\Scalar\String_('shared'));
            $blocks[]                = $block;
        }
        return $blocks;
    }

    private function indexOf(Block ...$blocks): BlockIndex
    {
        $index = new BlockIndex();
        foreach ($blocks as $b) {
            $index->add($b);
        }
        return $index;
    }

    private function costClusterer(int $maxTedCost): Clusterer
    {
        return new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.0,
            treeThreshold: 0.0,
            maxDocumentFrequency: 1.0,
            maxTedCost: $maxTedCost,
        );
    }

    public function testCostStormSlidesUnderThePairCountGuard(): void
    {
        // (c1) The aggregate halt is a DIFFERENT exception object than the
        // pair-count halt: a CLI that catches CandidateBudgetException for
        // its exit-1 rendering must never confuse the two refusals.
        $blocks = $this->expensiveClique(8, 700);   // 28 pairs x 490,000 units
        $index  = $this->indexOf(...$blocks);

        $this->assertCount(28, $this->collect($this->clusterer(), $index, null),
            'builder sanity: the storm is 28 pairs — trivially under the 60M count budget');

        // Control: pair-count armed (production default) + cost unlimited
        // — the count guard has nothing to say about this corpus.
        $off = $this->costClusterer(Clusterer::UNLIMITED_TED_COST)->cluster($index, null);
        $this->assertNotEmpty($off);

        try {
            $this->costClusterer(2 * 700 * 700)->cluster($index, null);
            $this->fail('a budget of two charges must halt on the third expensive pair');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(CandidateBudgetException::class, $e,
                'the aggregate refusal is its own loud halt, not a repurposed pair-count refusal');
            $this->assertStringContainsString('aggregate safety budget', $e->getMessage());
            $this->assertStringContainsString(number_format(2 * 700 * 700), $e->getMessage(),
                'the breached cost budget (in node-pairs) must be named');
        }
    }

    public function testAggregateBudgetNeverInventsWorkForTheCountGuard(): void
    {
        // (c2) Below its budget the cost guard is silent: the same 28-pair
        // corpus scores pair-for-pair identically when armed with ample
        // cost budget as with it disabled — the count guard neither fires
        // earlier nor differently because the cost twin exists.
        $blocks = $this->expensiveClique(8, 700);
        $index  = $this->indexOf(...$blocks);

        $armed = $this->costClusterer(28 * 700 * 700)->cluster($index, null); // exact total
        $off   = $this->costClusterer(Clusterer::UNLIMITED_TED_COST)->cluster($index, null);

        $project = static fn (array $cs): string => (string) json_encode(array_map(
            static fn ($c): array => [$c->id, array_map(static fn ($b): string => $b->id, $c->members), $c->pairs],
            $cs,
        ));
        $this->assertSame($project($off), $project($armed),
            'a cost budget equal to the corpus charge completes byte-identically');
    }

    // ------------------------------------------------------------------
    // once-037 (once-S33): the stage must reach the guard WITHOUT first
    // paying a budget-sized resident list.
    //
    // The generator-level arms above prove the enumeration halts at
    // budget+1; the finding's actual pain was in ClusterStage, which
    // drained it through iterator_to_array before scoring anything — so a
    // production-default storm needed ~14 GB of pair tuples resident
    // before the loud halt was allowed to speak, and died OOM (exit 255)
    // instead. The stage now streams bounded windows. These arms drive the
    // REAL stage (PipelineState + ClusterStage::run over hand-built
    // blocks — the PairScoreWorkerTedParityTest harness shape) and pin the
    // windowed contract: halt position, resident-window announcement,
    // pair-for-pair identity, and the documented breach-above-window tax.
    // cloneFamilyBlocks() shares one structuralHash so the scorer takes the
    // trivial exact-hash path — deterministic edges, zero APTED, test-safe.
    // ------------------------------------------------------------------

    /** @return list<Block> k mutually exact clones; pairs = k(k-1)/2 */
    private function cloneFamilyBlocks(int $k): array
    {
        $blocks = [];
        for ($i = 0; $i < $k; $i++) {
            $block                = $this->makeBlock(sprintf('F%04d', $i), ['family_gram' => 1, 'g2' => 1, 'g3' => 1]);
            $block->structuralHash = 'hash_shared'; // exact-hash tier: no refinement pass needed
            $blocks[]             = $block;
        }
        return $blocks;
    }

    private function stageConfig(int $candidateBudget): Config
    {
        return new Config(
            paths:                [sys_get_temp_dir()],
            exclude:              [],
            maxDocumentFrequency: 1.0, // df=1.0 admits every gram — the guard arms in the synthetic family
            incremental:          false,
            workers:              1,   // serial zone: deterministic, no fork noise
            lazyAst:              false,
            candidateBudget:      $candidateBudget,
        );
    }

    private function makeStage(Config $config, ?ProgressListener $listener, int $window): ClusterStage
    {
        return new ClusterStage(
            exactOnly: false,
            maxMemoryMb: 0,
            listener: $listener,
            useClusterCache: false,
            candidateWindow: $window,
        );
    }

    private function stageState(Config $config, array $blocks): PipelineState
    {
        $state = new PipelineState($config);
        $state->blocks = $blocks;
        return $state;
    }

    /** Listener recording every onPairScored(scored, total) announcement. */
    private function recordingListener(): ProgressListener
    {
        return new class implements ProgressListener {
            /** @var list<array{0:int,1:int}> */
            public array $pairCalls = [];

            #[\Override]
            public function onStageStart(Stage $stage): void {}
            #[\Override]
            public function onStageEnd(Stage $stage): void {}
            #[\Override]
            public function onFileScanned(int $scanned, int $total): void {}
            #[\Override]
            public function onFilePreprocessed(int $processed, int $reused, int $errors): void {}
            #[\Override]
            public function onPairScored(int $scored, int $total): void
            {
                $this->pairCalls[] = [$scored, $total];
            }
            #[\Override]
            public function onClusterRefactored(int $refactored, int $total): void {}
        };
    }

    public function testStageHaltsMidFillBeforeScoringAnyPair(): void
    {
        // The once-037 headline at stage level: a budget that fits inside
        // one resident window must die at seeding — zero pairs scored, zero
        // edges, and the listener never even hears about the scoring pass.
        // Pre-once-S33 this shape was unverifiable from the stage: the whole
        // 435-pair list (or a budget-sized slice of the real storms) was
        // materialized before any of these counters could be observed.
        $config   = $this->stageConfig(100);
        $state    = $this->stageState($config, $this->cloneFamilyBlocks(30)); // 435 storm pairs
        $listener = $this->recordingListener();

        try {
            $this->makeStage($config, $listener, 512)->run($state, new NullOutput());
            $this->fail('the stage must rethrow the generation halt, not swallow or delay it');
        } catch (CandidateBudgetException $e) {
            $this->assertStringContainsString('100', $e->getMessage(), 'the halted budget must be named');
        }

        $this->assertSame(100, $state->candidatePairs, 'the halt fires at exactly pair number budget+1 of the stream');
        $this->assertSame(0, $state->scoredPairs, 'no pair may be scored before the pre-window halt');
        $this->assertSame([], $state->edges);
        $this->assertSame([], $listener->pairCalls,
            'the (0, total) scoring announcement is the materialization witness: on the old batch path it could only ever carry the FULL list — here the stream died before it');
    }

    public function testWindowedScoringIsPairForPairIdenticalToFullResidentRun(): void
    {
        // The streaming law: windows are consecutive prefixes of ONE
        // deterministic enumeration, so any corpus that completes under the
        // budget scores the same pairs, in the same order, as the old
        // full-list path — only the residency is windowed. 100 exact clones
        // = 4,950 pairs: 25 windows vs one resident batch.
        $run = function (int $window): PipelineState {
            $config = $this->stageConfig(Clusterer::MAX_CANDIDATE_PAIRS);
            $state  = $this->stageState($config, $this->cloneFamilyBlocks(100));
            $this->makeStage($config, null, $window)->run($state, new NullOutput());
            return $state;
        };

        $streamed = $run(200);
        $resident = $run(PHP_INT_MAX);

        $this->assertSame(4950, $streamed->candidatePairs, 'closed-form mass k(k-1)/2 for k=100');
        $this->assertSame($resident->candidatePairs, $streamed->candidatePairs);
        $this->assertSame($resident->scoredPairs, $streamed->scoredPairs);
        $this->assertSame($resident->edges, $streamed->edges,
            'window boundaries must not alter which pairs are scored or in which order they land');

        $project = static fn (array $cs): string => (string) json_encode(array_map(
            static fn ($c): array => [$c->id, array_map(static fn ($b): string => $b->id, $c->members), $c->pairs],
            $cs,
        ));
        $this->assertSame($project($resident->clusters), $project($streamed->clusters),
            'clustering over windowed edges is the same function of the edge stream (engine-S11 permutations law one store over)');
    }

    public function testStageAnnouncesTheResidentWindowNotTheMaterializedTotal(): void
    {
        // Peak-pairs instrumentation at the listener seam: the FIRST
        // (0, total) call is the moment the old stage had already drained
        // the whole generator into RAM. The windowed stage must announce
        // with only one window generated — the total-so-far grows afterward.
        $config   = $this->stageConfig(Clusterer::MAX_CANDIDATE_PAIRS);
        $state    = $this->stageState($config, $this->cloneFamilyBlocks(100)); // 4,950 pairs
        $listener = $this->recordingListener();

        $this->makeStage($config, $listener, 200)->run($state, new NullOutput());

        $this->assertNotEmpty($listener->pairCalls);
        $this->assertSame(200, $listener->pairCalls[0][1],
            'scoring begins while the resident candidate list is ONE window — not 4,950 pairs deep');
        $this->assertSame(4950, $listener->pairCalls[count($listener->pairCalls) - 1][1],
            'the final announcement is the honest total');
        $this->assertSame(4950, $state->candidatePairs);
        $this->assertGreaterThan(200, $listener->pairCalls[count($listener->pairCalls) - 1][1]);
    }

    public function testBreachAboveTheWindowPaysTheInterleavingTaxAndStaysBounded(): void
    {
        // Second bullet of the breach taxonomy: budget beyond one window
        // (here fillCeil 64 with an injected window of 50) — the first
        // window scores, the halt lands the moment generation reaches
        // pair 101. Memory never outruns the window even though the budget
        // did have to be flown; the counters record exactly that shape.
        $config   = $this->stageConfig(100);
        $state    = $this->stageState($config, $this->cloneFamilyBlocks(100)); // 4,950 pairs available
        $listener = $this->recordingListener();

        try {
            $this->makeStage($config, $listener, 50)->run($state, new NullOutput());
            $this->fail('budget 100 must halt a 4,950-pair storm');
        } catch (CandidateBudgetException $e) {
            $this->assertStringContainsString('100', $e->getMessage());
        }

        $this->assertSame(100, $state->candidatePairs);
        $this->assertSame(64, $state->scoredPairs,
            'exactly the first resident window (fillCeil = max(window, PARALLEL_MIN_PAIRS)) was scored before the halt — the documented interleaving tax');
        $this->assertCount(64, $state->edges, 'edges stay window-sized; the resident list never grew past max(window, 64)');
        $this->assertSame(64, $listener->pairCalls[0][1], 'the announcement carried the window fill, not the budget');
    }

    public function testCandidateWindowBoundSitsFarBelowBudgetSizedMaterialization(): void
    {
        // Guard-rail on the constant itself (same house style as the
        // MAX_CANDIDATE_PAIRS pin above): the resident window keeps stage
        // memory near tens of megabytes while the production budget would
        // have demanded ~14 GB of pair tuples under the old shape — the
        // number that made the guard decorative is now bounded by a
        // DIFFERENT number. And the default window sits at or above the
        // parallel threshold, so production corpora that complete make the
        // fork/serial decision over one fill, byte-for-byte the old
        // arithmetic.
        $this->assertSame(262_144, ClusterStage::CANDIDATE_WINDOW);
        $this->assertLessThan(80_000_000, ClusterStage::CANDIDATE_WINDOW * 230,
            '~230-byte tuples: the resident candidate list stays under 80 MB at the default window');
        $this->assertLessThan(
            intdiv(Clusterer::MAX_CANDIDATE_PAIRS * 230, 100),
            ClusterStage::CANDIDATE_WINDOW * 230,
            'the window must bound residency at least 100x below what flying the full production budget as one list would have cost'
        );
        $minPairs = (int) (new \ReflectionClass(ClusterStage::class))->getConstant('PARALLEL_MIN_PAIRS');
        $this->assertSame(64, $minPairs);
        $this->assertGreaterThanOrEqual($minPairs, ClusterStage::CANDIDATE_WINDOW,
            'default window >= parallel floor: one-fill decisions at production shape');
    }
}
