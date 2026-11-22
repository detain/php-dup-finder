<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Clustering;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Clusterer;
use Phpdup\Clustering\MatchTier;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\NgramFingerprint;
use Phpdup\Index\BlockIndex;
use Phpdup\Normalization\Normalizer;
use Phpdup\Parsing\AstParser;
use Phpdup\Parallel\PairScoreWorker;
use Phpdup\Similarity\JaccardSimilarity;
use Phpdup\Similarity\TreeEditDistance;
use Phpdup\Util\LineRange;
use Phpdup\Util\CanonicalNodePool;

/**
 * Item 1.2 — the max_ted_size gate.
 *
 * APTED is the quadratic cost site of scoring (measured: a 728-node pair
 * takes ~4s and ~30MB of matrix; the filing's 1000-node pair ran 161s in
 * 1.2GB). The gate routes pairs whose LARGER member exceeds the cap past
 * the TED call: they are accepted on Jaccard alone, stamped
 * 'jaccard-unverified' — spelled identically in BOTH tier vocabularies
 * (MatchTier enum + PairScoreWorker consts; the dual-vocabulary drift
 * trap is pinned by testTierSpellingIsIdenticalInBothVocabularies).
 *
 * Default posture: 800 == the extraction ceiling (max_block_size), so no
 * real block can exceed it and the gate is inert at default — the entire
 * golden corpus must stay byte-identical (enforced by the Golden suite;
 * the inertness triangle is pinned here too).
 *
 * Proven:
 *   (a) over-cap pairs land 'jaccard-unverified' through BOTH engines
 *       (serial Clusterer scorer + PairScoreWorker — the fork-zone
 *       driver), with the edge weight equal to the raw Jaccard score;
 *   (b) the gated call allocates NO APTED matrix (real-memory + wall
 *       delta), while the un-gated control pair demonstrably does —
 *       deleting the cap branch turns (b) red through the TED path;
 *   (c) at-or-under-cap behaviour is unchanged (TED still judges,
 *       tier stays 'jaccard'), including size == cap exactly;
 *   (d) jaccard-failing pairs fall through untouched (no gate-made edge);
 *   (e) the engines share one default (800) and refuse caps < 1;
 *   (f) engine-S10 (engine-010): the serial APTED lane additionally runs
 *       behind an AGGREGATE node-product budget (Clusterer::$maxTedCost)
 *       that halts a storm of large near-identical pairs loudly — with
 *       the charged cost in the message — before paying for the pair
 *       that would cross it, while every corpus below the budget scores
 *       pair-for-pair identically to the unguarded loop.
 */
final class TedSizeGateTest extends TestCase
{
    // ------------------------------------------------------------------
    // Builders
    // ------------------------------------------------------------------

    /**
     * Synthetic block with an explicit node-count — canonical deliberately
     * left UNINITIALIZED (public Node $canonical with no default): any
     * implementation that reached TED for these blocks would fatal, so the
     * gate's own path is what keeps these tests silent about errors.
     */
    private function fakeBlock(string $id, int $size, array $bag): Block
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
        $block->id              = $id;
        $block->structuralHash  = 'hash_' . $id; // distinct hashes → never in the exact bucket
        $block->ngramBag        = $bag;
        $block->size            = $size;
        return $block;
    }

    /**
     * Real parse→extract→normalize→fingerprint pair of near-identical
     * functions. $extraB adds ONE guard to B — after aggressive
     * normalization pure literal/name differences vanish (A and B hash
     * identical and land the exact-hash bucket), so the structural
     * difference is what keeps the pair in the Jaccard/TED lane. One
     * statement out of ~730 keeps Jaccard ≈ 0.99 while the structural
     * hashes differ. $foreachB instead (or additionally) appends that
     * many top-level foreach insertions to B: a foreach subtree costs
     * APTED slightly MORE than it costs the 5-gram bag Jaccard, so the
     * pair becomes value-discriminative (ted < jac) — the control test
     * needs the min() to be the TED verdict, not the raw Jaccard.
     *
     * @return array{0: Block, 1: Block}
     */
    private function realPair(int $units, bool $extraB = true, int $foreachB = 0, ?CanonicalNodePool $pool = null): array
    {
        $mk = static function (string $tag, bool $extra) use ($units, $extraB, $foreachB): string {
            $code = "<?php\nfunction probe_{$tag}(\$p) {\n";
            for ($i = 0; $i < $units; $i++) {
                $code .= "    \$m{$tag}_{$i} = strlen(\$p) + {$i}; if (\$m{$tag}_{$i} > {$i}) { \$out[] = 'v{$tag}_{$i}'; }\n";
            }
            if ($extra && $extraB) {
                $code .= "    if (\$p) { \$extraGuard = 1; }\n";
            }
            if ($extra) {
                for ($g = 0; $g < $foreachB; $g++) {
                    $code .= "    foreach ((array)\$p as \$k{$g} => \$v{$g}) { \$acc[\$k{$g}] = \$v{$g} + {$g}; }\n";
                }
            }
            $code .= "    return \$out ?? [];\n}\n";
            return $code;
        };
        $parser = new AstParser();
        // maxSize deliberately above the probe trees so the extractor does
        // not drop them (this test measures scoring, not extraction caps).
        $extractor = new BlockExtractor(minSize: 1, maxSize: 100000);
        $normalizer = $pool === null
            ? new Normalizer('aggressive')
            : new Normalizer(mode: 'aggressive', lowMemory: true, nodePool: $pool);
        $fingerprint = new NgramFingerprint(5);
        $blocks = [];
        foreach (['A', 'B'] as $tag) {
            $stmts = $parser->parseCode($mk($tag, ($extraB || $foreachB > 0) && $tag === 'B'));
            $this->assertNotNull($stmts);
            $b = $extractor->extract("probe_{$tag}.php", $stmts)[0];
            $normalizer->normalize($b);
            $b->structuralHash = (new \Phpdup\Fingerprint\SubtreeHasher())->hash($b->canonical);
            $b->id = 'R-' . $tag;
            $b->ngramBag = $fingerprint->fingerprint($b->canonical);
            $blocks[] = $b;
        }
        $this->assertNotSame(
            $blocks[0]->structuralHash, $blocks[1]->structuralHash,
            'probe builder sanity: the pair must NOT be an exact clone',
        );
        return $blocks;
    }

    /** @param list<array{0: string, 1: string}> $pairs */
    private function worker(BlockIndex $index, int $maxTedSize, bool $containment = false): PairScoreWorker
    {
        return new PairScoreWorker(
            index: $index,
            similarityThreshold: 0.8,
            treeThreshold: 0.85,
            optionalBlocksEnabled: $containment,
            maxTedSize: $maxTedSize,
        );
    }

    private function serialClusterer(int $maxTedSize): Clusterer
    {
        return new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.8,
            treeThreshold: 0.85,
            // scorePairsSerially generates its own candidates through the
            // df filter; with only two blocks every gram has df 1.0, so the
            // default cap would starve the index to zero pairs.
            maxDocumentFrequency: 1.0,
            maxTedSize: $maxTedSize,
        );
    }

    private function indexOf(Block ...$blocks): BlockIndex
    {
        $index = new BlockIndex();
        foreach ($blocks as $b) {
            $index->add($b);
        }
        return $index;
    }

    // ------------------------------------------------------------------
    // (a) Over-cap → 'jaccard-unverified', both engines, weight = Jaccard
    // ------------------------------------------------------------------

    public function testWorkerEngineStampsOverCapPairJaccardUnverified(): void
    {
        $bag = ['shared_gram' => 3, 'other_gram' => 2];
        $a = $this->fakeBlock('BIG-A', 200, $bag);
        $b = $this->fakeBlock('BIG-B', 240, $bag); // one over, one under — the gate keys on max()
        $edges = $this->worker($this->indexOf($a, $b), 50)
            ->score([['BIG-A', 'BIG-B']]);

        $this->assertSame([['BIG-A', 'BIG-B', 1.0, 'jaccard-unverified']], $edges);
    }

    public function testSerialEngineStampsOverCapPairJaccardUnverified(): void
    {
        $bag = ['shared_gram' => 3, 'other_gram' => 2];
        $index = $this->indexOf(
            $this->fakeBlock('BIG-A', 200, $bag),
            $this->fakeBlock('BIG-B', 240, $bag),
        );
        $clusters = $this->serialClusterer(50)->cluster($index, null);

        $this->assertCount(1, $clusters);
        $this->assertSame(2, count($clusters[0]->members));
        $this->assertCount(1, $clusters[0]->pairs);
        $this->assertSame('jaccard-unverified', $clusters[0]->pairs[0]['matchTier']);
        $this->assertSame(1.0, $clusters[0]->pairs[0]['matchScore']);
        // Weight is the RAW Jaccard score, not min(jac, ted) — there is no
        // ted under the gate.
        $this->assertContains('jaccard-unverified', array_column($clusters[0]->pairs, 'matchTier'));
    }

    // ------------------------------------------------------------------
    // (a2) Dual-engine parity on the same gated pair
    // ------------------------------------------------------------------

    public function testBothEnginesAgreeOnTheGatedEdge(): void
    {
        $bag = ['shared_gram' => 3, 'other_gram' => 2];
        $a = $this->fakeBlock('BIG-A', 200, $bag);
        $b = $this->fakeBlock('BIG-B', 240, $bag);
        $index = $this->indexOf($a, $b);

        $workerEdges = $this->worker($index, 50)->score([['BIG-A', 'BIG-B']]);
        $clusters    = $this->serialClusterer(50)->cluster($index, null);

        $this->assertCount(1, $workerEdges);
        $this->assertCount(1, $clusters);
        $serialPair = $clusters[0]->pairs[0];

        // Same tier token, same weight, same endpoints (order-insensitive).
        $this->assertSame($workerEdges[0][3], $serialPair['matchTier']);
        $this->assertSame($workerEdges[0][2], $serialPair['matchScore']);
        $this->assertEqualsCanonicalizing(
            [$workerEdges[0][0], $workerEdges[0][1]],
            [$serialPair['blockA'], $serialPair['blockB']],
        );
    }

    // ------------------------------------------------------------------
    // (b) The gate skips the APTED matrix; the control pair proves it
    // ------------------------------------------------------------------

    public function testGatedPairAllocatesNoTreedistMatrix(): void
    {
        // ~728-node blocks (probe-measured): un-gated TED costs ~4s and
        // ~30MB of real matrix; gated scoring must be near free. The gate
        // must ALSO keep the wall clock out of APTED's quadratic zone —
        // deleting the cap branch turns both assertions red.
        [$a, $b] = $this->realPair(40);
        $this->assertGreaterThan(500, $a->size, 'probe trees must be well over the tiny cap');

        $index  = $this->indexOf($a, $b);
        $worker = $this->worker($index, 100);

        // Warm the allocator so the delta measures the call, not the pool.
        $worker->score([['R-A', 'R-B']]);

        $before = memory_get_usage(true);
        $t0     = microtime(true);
        $edges  = $worker->score([['R-A', 'R-B']]);
        $wall   = microtime(true) - $t0;
        $delta  = memory_get_usage(true) - $before;

        $this->assertCount(1, $edges);
        $this->assertSame('jaccard-unverified', $edges[0][3]);
        $jac = (new JaccardSimilarity())->similarity($a->ngramBag ?? [], $b->ngramBag ?? []);
        $this->assertSame($jac, $edges[0][2], 'weight is the raw Jaccard score');
        $this->assertGreaterThan(0.9, $jac, 'the pair must genuinely reach the jaccard tier');
        $this->assertLessThan(1.0, $wall, 'gated scoring must not pay APTED (control: ~4s)');
        $this->assertLessThan(8 * 1024 * 1024, $delta, 'gated scoring must allocate no n*m matrix (control: ~30MB)');
    }

    public function testControlPairWithoutGatePaysTheTedMatrix(): void
    {
        // The negative control that keeps (b) honest: a sibling probe pair
        // (~730 nodes, six foreach insertions on B) under a cap above the
        // block sizes runs real APTED. The old
        // proof-of-payment was wall time >= 1.0s — host-dependent (it
        // flaked at 0.659s on a fast CI runner). The proof is structural
        // now: tier 'jaccard' is minted ONLY by the arm that ran TED to
        // completion (the gate arm spells 'jaccard-unverified'), and the
        // edge weight equals an INDEPENDENT recomputation of
        // min(jac, tedSim) that lands strictly below the raw Jaccard.
        // Reinstating the gate branch on this pair flips the tier and
        // snaps the weight to raw jac (red); skipping the TED call while
        // faking the tier still fails the weight pin (red). The memory
        // delta remains deliberately unasserted — a warm emalloc arena
        // (every preceding battery test) shows 0 even after a 30MB matrix.
        [$a, $b] = $this->realPair(40, extraB: false, foreachB: 6);
        $index  = $this->indexOf($a, $b);
        $worker = $this->worker($index, 5000); // cap far above the pair

        $edges = $worker->score([['R-A', 'R-B']]);

        $jac    = (new JaccardSimilarity())->similarity($a->ngramBag ?? [], $b->ngramBag ?? []);
        $tedSim = (new TreeEditDistance())->similarity($a->canonical, $b->canonical, 0.85);

        $this->assertCount(1, $edges);
        $this->assertGreaterThan(0.8, $jac, 'probe builder sanity: the pair reaches the Jaccard/TED lane');
        $this->assertGreaterThan(0.85, $tedSim, 'probe builder sanity: the pair passes the TED threshold (else the control never reaches the minting arm)');
        $this->assertLessThan($jac, $tedSim, 'value-discriminative pair: min(jac, ted) must be TED, not the raw-Jaccard gate weight');
        $this->assertSame('jaccard', $edges[0][3], "tier 'jaccard' is minted only post-TED");
        $this->assertSame($tedSim, $edges[0][2], 'edge weight is the TED verdict itself, recomputed independently');
    }

    // ------------------------------------------------------------------
    // (c) Under/at cap → unchanged behaviour (TED still judges)
    // ------------------------------------------------------------------

    public function testUnderCapPairStillJudgedByTedWithJaccardTier(): void
    {
        // Real (small) canonical so APTED actually runs; size pinned below
        // the cap. Weight stays min(jac, ted) — the untouched old formula.
        [$a, $b] = $this->realPair(5);
        $a->size = 40;
        $b->size = 40;
        $edges = $this->worker($this->indexOf($a, $b), 50)->score([['R-A', 'R-B']]);

        $this->assertCount(1, $edges);
        $this->assertSame(MatchTier::Jaccard->value, $edges[0][3]);
    }

    public function testSizeExactlyAtCapIsNotGated(): void
    {
        // Strict '>' posture: a block of exactly max_ted_size nodes is
        // still TED-judged. With default cap 800 this is the inertness
        // contract — the extraction ceiling itself sits AT the cap.
        [$a, $b] = $this->realPair(5);
        $a->size = 800;
        $b->size = 800;
        $worker = $this->worker($this->indexOf($a, $b), 800);

        $edges = $worker->score([['R-A', 'R-B']]);
        $this->assertSame('jaccard', $edges[0][3]);
    }

    // ------------------------------------------------------------------
    // (d) Jaccard-failing pairs fall through untouched by the gate
    // ------------------------------------------------------------------

    public function testJaccardFailOnOverCapPairStaysUnedged(): void
    {
        // Both huge, but the bags disagree — the gate must NOT mint a
        // 'jaccard-unverified' edge for a pair the old code would have
        // rejected. Containment off → the pair must produce no edge at all.
        $a = $this->fakeBlock('BIG-A', 200, ['gram_one' => 1, 'gram_two' => 1]);
        $b = $this->fakeBlock('BIG-B', 200, ['gram_three' => 1, 'gram_four' => 1]);

        $edges = $this->worker($this->indexOf($a, $b), 50)->score([['BIG-A', 'BIG-B']]);
        $this->assertSame([], $edges);
    }

    // ------------------------------------------------------------------
    // Tier-vocabulary dual spelling: the grep-verified equality pin
    // ------------------------------------------------------------------

    public function testTierSpellingIsIdenticalInBothVocabularies(): void
    {
        $this->assertSame('jaccard-unverified', MatchTier::JaccardUnverified->value);
        $this->assertSame('jaccard-unverified', PairScoreWorker::TIER_JACCARD_UNVERIFIED);
        $this->assertSame(MatchTier::JaccardUnverified->value, PairScoreWorker::TIER_JACCARD_UNVERIFIED);

        // File-level grep: the exact token lives in BOTH vocabulary sources
        // in the SAME spelling, and no near-miss variant exists anywhere.
        $enumSrc = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Clustering/MatchTier.php');
        $workerSrc = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Parallel/PairScoreWorker.php');
        $this->assertStringContainsString("'jaccard-unverified'", $enumSrc);
        $this->assertStringContainsString("'jaccard-unverified'", $workerSrc);
        foreach (['jaccard_unverified', 'jaccardUnverified', 'jaccard unverified', 'jaccardunverified'] as $nearMiss) {
            $this->assertStringNotContainsString($nearMiss, $enumSrc);
            $this->assertStringNotContainsString($nearMiss, $workerSrc);
        }
    }

    // ------------------------------------------------------------------
    // (e) Defaults align with the extraction ceiling; caps < 1 refused
    // ------------------------------------------------------------------

    public function testDefaultCapIsTheInertnessTriangle(): void
    {
        $configDefaults = new Config(paths: ['/x'], exclude: []);

        $this->assertSame(800, Clusterer::DEFAULT_MAX_TED_SIZE);
        $this->assertSame(Clusterer::DEFAULT_MAX_TED_SIZE, $configDefaults->maxTedSize);
        // Same value the two engines fall back to when constructed bare:
        $clustererParam = (new \ReflectionParameter([Clusterer::class, '__construct'], 'maxTedSize'));
        $workerParam    = (new \ReflectionParameter([PairScoreWorker::class, '__construct'], 'maxTedSize'));
        $this->assertSame(Clusterer::DEFAULT_MAX_TED_SIZE, $clustererParam->getDefaultValue());
        $this->assertSame(Clusterer::DEFAULT_MAX_TED_SIZE, $workerParam->getDefaultValue());
        // Inertness: extraction ceiling == cap and the gate is strict >,
        // so no extractable block can ever be gated at default.
        $this->assertSame($configDefaults->maxBlockSize, $configDefaults->maxTedSize);
    }

    public function testNonPositiveCapIsRefusedAtEveryBoundary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Config(paths: ['/x'], exclude: [], maxTedSize: 0);
    }

    public function testNonPositiveCapRefusedBySerialEngine(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->serialClusterer(0);
    }

    public function testNonPositiveCapRefusedByWorkerEngine(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->worker(new BlockIndex(), -1);
    }

    // ------------------------------------------------------------------
    // engine-S04 rider: --low-memory pooling must be invisible to the
    // gate (canonical content is unchanged) while demonstrably splicing
    // shared sentinel leaves across the two members' canonical trees.
    // ------------------------------------------------------------------

    public function testPoolingPreservesGateVerdictsAndSplicesSharedSentinels(): void
    {
        $plainA  = $this->realPair(6);
        $pool    = new CanonicalNodePool();
        $pooledA = $this->realPair(6, pool: $pool);

        // (1) Stream equality: pooling rewrites object identity, never
        //     content — both members' structural hashes are bit-what-
        //     the unpooled pass minted, so every gate input is unmoved.
        $this->assertSame($plainA[0]->structuralHash, $pooledA[0]->structuralHash);
        $this->assertSame($plainA[1]->structuralHash, $pooledA[1]->structuralHash);
        $this->assertGreaterThan(0, $pool->size(), 'guard against a vacuous pass: interning happened');

        // (2) The finding's shared-object proof through the real path:
        //     aggressive canonicalisation mints attribute-less `__CALL_`
        //     sentinel Names, so the same call name in the two members'
        //     trees collapses to ONE pooled object that each parent's
        //     name slot references.
        $finder = new \PhpParser\NodeFinder();
        $strlenName = static function (Block $b) use ($finder): ?\PhpParser\Node\Name {
            foreach ($finder->findInstanceOf([$b->canonical], \PhpParser\Node\Expr\FuncCall::class) as $call) {
                if ($call->name instanceof \PhpParser\Node\Name && $call->name->toString() === '__CALL_strlen') {
                    return $call->name;
                }
            }
            return null;
        };
        $plainNameA = $strlenName($plainA[0]);
        $plainNameB = $strlenName($plainA[1]);
        $this->assertNotNull($plainNameA, 'fixture sanity: probe carries a strlen FuncCall');
        $this->assertNotNull($plainNameB);
        $this->assertNotSame($plainNameA, $plainNameB, 'without the pool the same sentinel stays two objects');

        $pooledNameA = $strlenName($pooledA[0]);
        $pooledNameB = $strlenName($pooledA[1]);
        $this->assertNotNull($pooledNameA);
        $this->assertNotNull($pooledNameB);
        $this->assertSame(spl_object_id($pooledNameA), spl_object_id($pooledNameB),
            'the pooled sentinel must be one shared instance across members');

        // (3) Gate verdict identical through both runs (tiny cap forces
        //     the jaccard-unverified branch over the real trees).
        $plainVerdict  = $this->serialClusterer(50)->cluster($this->indexOf($plainA[0], $plainA[1]), null);
        $pooledVerdict = $this->serialClusterer(50)->cluster($this->indexOf($pooledA[0], $pooledA[1]), null);
        $this->assertCount(1, $pooledVerdict);
        $this->assertSame(
            $plainVerdict[0]->pairs[0]['matchTier'],
            $pooledVerdict[0]->pairs[0]['matchTier'],
        );
        $this->assertSame('jaccard-unverified', $pooledVerdict[0]->pairs[0]['matchTier']);
        $this->assertSame(
            $plainVerdict[0]->pairs[0]['matchScore'],
            $pooledVerdict[0]->pairs[0]['matchScore'],
        );
    }

    // ------------------------------------------------------------------
    // engine-S15 / php-dup-finder-engine-056 — tier honesty.
    //
    // (1) The vocabulary is exactly the set the engines mint: the never-
    //     mintable `Ted` case is deleted (product story: APTED only ever
    //     REFINES bag-found pairs, and first-wins keeps their tier; a
    //     tree-origin match is architecturally unreachable).
    // (2) The Jaccard→TED CONFIRM lane still spells its edge 'jaccard'
    //     serially — the deletion is not a re-labelled 'ted' emission.
    // (3) The containment lens must NOT re-acclaim a pair the tree
    //     already refuted: equal bags make containment mathematically
    //     1.0 precisely when Jaccard was 1.0, so leaving the arm open
    //     on the TED-refuted lane would reverse APTED's verdict with a
    //     weaker restatement of the evidence APTED just overruled — and
    //     would flip the serial↔fork byte-parity pins (measured).
    // ------------------------------------------------------------------

    public function testTierVocabularyMintsNoPhantomTier(): void
    {
        $values = array_map(static fn (MatchTier $t): string => $t->value, MatchTier::cases());

        $this->assertSame(
            ['exact-hash', 'jaccard', 'jaccard-unverified', 'containment', 'ir', 'ml'],
            $values,
            "the enum must be exactly the mintable tier set — 'ted' was a phantom (engine-056)",
        );
        $this->assertNotContains('ted', $values);
        foreach (MatchTier::cases() as $case) {
            $this->assertStringNotContainsString('ted', strtolower($case->name));
        }
    }

    public function testSerialConfirmLaneKeepsTheJaccardTier(): void
    {
        // Serial twin of testUnderCapPairStillJudgedByTedWithJaccardTier:
        // a Jaccard-pass, TED-pass pair is confirmed and stays 'jaccard'
        // (first-wins), never a resurrected tree tier. Cap far above the
        // tiny probe trees so TED genuinely runs.
        [$a, $b] = $this->realPair(5);
        $a->size = 40;
        $b->size = 40;
        $clusters = $this->serialClusterer(5000)->cluster($this->indexOf($a, $b), null);

        $this->assertCount(1, $clusters);
        $this->assertSame('jaccard', $clusters[0]->pairs[0]['matchTier']);
        $this->assertNotSame('ted', $clusters[0]->pairs[0]['matchTier']);
    }

    /**
     * Divergent-tree, identical-bag pair: the Jaccard gate claims it at
     * 1.0, APTED refutes it (< treeThreshold). Builder sanity is pinned
     * at the call site so a future normaliser change cannot silently
     * move the pair out of the TED-refuted lane.
     *
     * @return array{0: Block, 1: Block}
     */
    private function divergentTreeSameBagPair(): array
    {
        $codeA = "<?php\nfunction probe_A(\$p) {\n"
            . "    \$a = strlen(\$p);\n    \$b = \$a + 1;\n    \$c = \$b + 2;\n"
            . "    \$d = \$c * 3;\n    return [\$a, \$b, \$c, \$d];\n}\n";
        $codeB = "<?php\nfunction probe_B(\$p) {\n"
            . "    if (\$p) { \$a = strlen(\$p); \$b = \$a + 1; } else { \$a = 0; \$b = 0; }\n"
            . "    for (\$i = 0; \$i < 2; \$i++) { \$c = \$b + \$i; }\n"
            . "    \$d = \$c * 3;\n    return [\$a, \$b, \$c, \$d];\n}\n";

        $parser    = new AstParser();
        $extractor = new BlockExtractor(minSize: 1, maxSize: 100000);
        $bag       = ['g_strlen' => 2, 'g_add' => 3, 'g_mul' => 1, 'g_ret' => 2];

        $blocks = [];
        foreach ([['A', $codeA], ['B', $codeB]] as [$tag, $code]) {
            $stmts = $parser->parseCode($code);
            $this->assertNotNull($stmts);
            $b = $extractor->extract("probe_{$tag}.php", $stmts)[0];
            (new Normalizer('aggressive'))->normalize($b);
            $b->structuralHash = (new \Phpdup\Fingerprint\SubtreeHasher())->hash($b->canonical);
            $b->id             = 'D-' . $tag;
            $b->ngramBag       = $bag; // forced equal → Jaccard exactly 1.0
            $blocks[]          = $b;
        }
        $this->assertNotSame($blocks[0]->structuralHash, $blocks[1]->structuralHash,
            'builder sanity: divergent trees must not collapse to one structural hash');
        return $blocks;
    }

    public function testTedRefutedPairIsNotReAcclaimedByTheContainmentLens(): void
    {
        [$a, $b] = $this->divergentTreeSameBagPair();

        $jac    = (new JaccardSimilarity())->similarity($a->ngramBag, $b->ngramBag);
        $tedSim = (new TreeEditDistance())->similarity($a->canonical, $b->canonical, 0.85);
        $this->assertGreaterThanOrEqual(0.8, $jac, 'builder sanity: the pair reaches the Jaccard lane');
        $this->assertLessThan(0.85, $tedSim, 'builder sanity: APTED refutes the pair (TED-refuted lane)');

        // Containment is ON by default here — the lens WOULD accept the pair
        // (equal bags ⇒ containment 1.0) if the refuted pair were allowed to
        // fall through to it. The $tedRefuted guard must keep the verdict
        // dead: no IR, no ML, no edge.
        $clusters = $this->serialClusterer(800000)->cluster($this->indexOf($a, $b), null);
        $this->assertSame([], $clusters,
            'a tree-refuted pair must not be re-acclaimed by the containment lens (engine-056 scope ruling)');
    }

    // ------------------------------------------------------------------
    // engine-S10 / php-dup-finder-engine-010 — the AGGREGATE APTED budget.
    //
    // maxTedSize caps one pair; it is silent on the family: 397 blocks
    // near the 800-node cap, pairwise near-identical, ran phase-2 scoring
    // past 400 s without finishing (rc=124) because the cost is the SUM
    // of per-verdict grids. scorePairsSerially now charges every pair it
    // actually sends to APTED size(a)×size(b) node-pairs up-front and
    // halts loudly when the next charge would cross Clusterer::$maxTedCost
    // — refusing the breaching verdict BEFORE paying for it. Below the
    // budget the edge list is pair-for-pair identical to the unguarded
    // loop (every corpus that completes today is untouched); the clock
    // never enters the accumulator, so the result stays a pure function
    // of the corpus.
    // ------------------------------------------------------------------

    /**
     * Serial engine with an aggregate-cost budget (df 1.0 so a forced-
     * bag family's shared grams still seed candidates, mirroring
     * serialClusterer()).
     */
    private function costClusterer(int $maxTedCost, int $maxTedSize = 5000): Clusterer
    {
        return new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(),
            similarityThreshold: 0.8,
            treeThreshold: 0.85,
            maxDocumentFrequency: 1.0,
            maxTedSize: $maxTedSize,
            maxTedCost: $maxTedCost,
        );
    }

    /**
     * $count near-identical REAL functions with manually FORCED-equal
     * bags: Jaccard is exactly 1.0 for every pair, while the structural
     * hashes genuinely differ — variant v carries v extra guard
     * statements (aggressive mode folds NAMES and LITERALS, so the
     * difference must be structural, a drift trap this builder pins).
     * The canonicals are real trees, so APTED runs and the aggregate
     * charge is paid for a fact, not stubbed.
     *
     * @return list<Block>
     */
    private function stormFamily(int $count): array
    {
        $parser     = new AstParser();
        $extractor  = new BlockExtractor(minSize: 1, maxSize: 100000);
        $normalizer = new Normalizer('aggressive');
        $hasher     = new \Phpdup\Fingerprint\SubtreeHasher();
        $bag        = ['fam_alpha' => 2, 'fam_beta' => 3, 'fam_gamma' => 1];
        $blocks     = [];
        $seen       = [];
        for ($v = 0; $v < $count; $v++) {
            $code = "<?php\nfunction storm_{$v}(\$p) {\n";
            for ($i = 0; $i < 5; $i++) {
                $code .= "    \$q{$v}_{$i} = strlen(\$p) + {$i}; if (\$q{$v}_{$i} > {$i}) { \$out[] = 'z{$v}_{$i}'; }\n";
            }
            for ($g = 0; $g < $v; $g++) {
                $code .= "    if (\$p) { \$extra{$v}_{$g} = 1; }\n";
            }
            $code .= "    return \$out ?? [];\n}\n";
            $stmts = $parser->parseCode($code);
            $this->assertNotNull($stmts);
            $b = $extractor->extract("storm_{$v}.php", $stmts)[0];
            $normalizer->normalize($b);
            $b->structuralHash = $hasher->hash($b->canonical);
            $b->id             = 'SF' . $v;
            $b->ngramBag       = $bag;
            $this->assertArrayNotHasKey($b->structuralHash, $seen,
                "storm builder sanity: variant {$v} must not collapse onto an earlier structural hash");
            $seen[$b->structuralHash] = true;
            $blocks[] = $b;
        }
        return $blocks;
    }

    /**
     * Replay of the production charge rule over a family whose every
     * candidate pair IS TED-eligible (equal forced bags ⇒ Jaccard 1.0,
     * distinct hashes, sizes under the cap): the ordered cumulative
     * node-product after each pair, exactly as scorePairsSerially
     * accumulates it.
     *
     * @return list<int>
     */
    private function chargedCumulative(BlockIndex $index): array
    {
        $probe  = $this->costClusterer(Clusterer::UNLIMITED_TED_COST);
        $cum    = [];
        $sum    = 0;
        foreach ($probe->generateCandidatePairs($index) as [$aId, $bId]) {
            $a = $index->get($aId);
            $b = $index->get($bId);
            $this->assertNotNull($a);
            $this->assertNotNull($b);
            $this->assertNotSame($a->structuralHash, $b->structuralHash,
                'charge-replay sanity: no exact-collapse in the family');
            $sum += $a->size * $b->size;
            $cum[] = $sum;
        }
        return $cum;
    }

    /**
     * Scalar-only content fingerprint of a cluster list (ids, member ids,
     * pair rows) — no Block/AST serialization anywhere.
     *
     * @param list<\Phpdup\Clustering\Cluster> $clusters
     */
    private function clustersFingerprint(array $clusters): string
    {
        $rows = [];
        foreach ($clusters as $c) {
            $rows[] = [
                $c->id,
                array_map(static fn ($b): string => $b->id, $c->members),
                $c->pairs,
            ];
        }
        return hash('sha256', (string) json_encode($rows));
    }

    public function testAggregateBudgetHaltsAtTheExactChargedPrefix(): void
    {
        // Four near-identical members = 6 judged pairs. A budget equal to
        // the full charged total completes silently; one unit short halts
        // ON the final pair, and the message names where it halted (the
        // GenerationBudgetTest prefix law, in cost units).
        $index = $this->indexOf(...$this->stormFamily(4));
        $cum   = $this->chargedCumulative($index);
        $this->assertCount(6, $cum, 'a 4-member forced family must charge exactly C(4,2) verdicts');

        $ok = $this->costClusterer($cum[5])->cluster($index, null);
        $this->assertNotEmpty($ok, 'the exact total budget completes');

        $clusters = $this->costClusterer(Clusterer::UNLIMITED_TED_COST)->cluster($index, null);
        $this->assertSame(
            $this->clustersFingerprint($clusters),
            $this->clustersFingerprint($ok),
            'budget-equal-to-corpus completes pair-for-pair identical to unguarded',
        );

        try {
            $this->costClusterer($cum[5] - 1)->cluster($index, null);
            $this->fail('one unit short of the charged total must halt');
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('aggregate safety budget', $message);
            $this->assertStringContainsString('node-pairs', $message, 'the unit is the node-product');
            $this->assertStringContainsString(number_format($cum[4]), $message,
                'the message must name the cost charged when it halted');
            $this->assertStringContainsString(number_format($cum[5] - $cum[4]), $message,
                'the message must name the breaching pair cost');
            $this->assertStringContainsString('5 verdicts', $message,
                'the message must count the verdicts already judged');
            $this->assertStringContainsString('maxTedCost', $message, 'the way forward must be named');
        }

        // Mid-stream halt: budget equal to the first TWO charges refuses
        // the third without paying for it.
        try {
            $this->costClusterer($cum[1])->cluster($index, null);
            $this->fail('budget of two charges must halt on the third');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString(number_format($cum[1]), $e->getMessage());
        }
    }

    public function testBreachingPairIsRefusedBeforePayingForIt(): void
    {
        // The finding's texture at real cap-proximate sizes: ONE ~730-node
        // near-identical pair costs ~2.3 s post-S09. Under a starved
        // aggregate budget the scoring must halt on the first big pair
        // CHEAPLY — the charge is taken before the verdict is paid, so
        // the refusal is the bound. Wall < 1 s vs the ~2.3 s payment is
        // the (b)-suite proof-of-not-paying, and the message names the
        // pair's actual node product (acceptance: cost in the message).
        [$a, $b] = $this->realPair(40);
        $this->assertGreaterThan(500, $a->size);
        $index  = $this->indexOf($a, $b);
        $budget = $a->size * $b->size - 1;

        $t0    = microtime(true);
        $threw = null;
        try {
            $this->costClusterer($budget)->cluster($index, null);
        } catch (\RuntimeException $e) {
            $threw = $e;
        }
        $wall = microtime(true) - $t0;

        $this->assertNotNull($threw, 'a pair whose product alone crosses the budget must halt');
        $this->assertLessThan(1.0, $wall, 'the halting verdict must NOT be paid for (control: ~2.3s)');
        $this->assertStringContainsString(number_format($budget), $threw->getMessage(),
            'the breached budget (in node-pairs) must be in the message');
        $this->assertStringContainsString(number_format($a->size * $b->size), $threw->getMessage(),
            'the breaching pair cost must be in the message');
        $this->assertStringContainsString("({$a->size} nodes)", $threw->getMessage(),
            'the message must name the breaching pair by id and size');

        // Control: the same budget + 1 pays the pair (below-budget stays
        // pair-for-pair identical to unguarded — same single edge).
        $armed = $this->costClusterer($budget + 1)->cluster($index, null);
        $off   = $this->costClusterer(Clusterer::UNLIMITED_TED_COST)->cluster($index, null);
        $this->assertSame($this->clustersFingerprint($off), $this->clustersFingerprint($armed));
        $this->assertNotEmpty($off, 'builder sanity: the pair does reach the APTED lane');
    }

    public function testUntedLanesChargeNothingAgainstAStarvedBudget(): void
    {
        // The budget is charged only where APTED is actually paid. Under a
        // budget of ONE node-pair, a corpus whose every pair leaves the
        // APTED lane before the call — over-cap (jaccard-unverified),
        // exact-hash, and jaccard/containment-dead strangers — completes
        // untouched. The fakes' canonicals stay UNINITIALIZED (the file-
        // level trick at the top of this class): had any lane reached TED
        // the run would fatal, so silence here IS the no-charge proof.
        $bag     = ['shared_gram' => 3, 'other_gram' => 2];
        $bigA    = $this->fakeBlock('BIG-A', 200, $bag);
        $bigB    = $this->fakeBlock('BIG-B', 240, $bag);
        $twinA   = $this->fakeBlock('EX-A', 300, ['sole_gram' => 1]);
        $twinB   = $this->fakeBlock('EX-B', 300, ['sole_gram' => 1]);
        $twinB->structuralHash = $twinA->structuralHash; // exact-hash pair
        $strX    = $this->fakeBlock('STR-X', 300, ['gram_one' => 1]);
        $strY    = $this->fakeBlock('STR-Y', 300, ['gram_two' => 1]);

        $clusters = $this->costClusterer(1, 50)
            ->cluster($this->indexOf($bigA, $bigB, $twinA, $twinB, $strX, $strY), null);

        $this->assertCount(2, $clusters, 'one exact cluster + one jaccard-unverified cluster, no exception');
        $tiers = [];
        foreach ($clusters as $c) {
            foreach ($c->pairs as $p) {
                $tiers[] = $p['matchTier'];
            }
        }
        $this->assertContains('jaccard-unverified', $tiers,
            'the over-cap pair rides on — without ever charging the budget');
        $this->assertNotContains('jaccard', $tiers,
            'no pair reached (and charged) an APTED verdict under the starved budget');
    }

    /** @dataProvider invalidBudgets */
    public function testNonPositiveAggregateBudgetIsRejectedAtConstruction(int $budget): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('>= 1');
        $this->costClusterer($budget);
    }

    /** @return array<string, array{0:int}> */
    public static function invalidBudgets(): array
    {
        return ['zero' => [0], 'negative' => [-5]];
    }

    public function testDefaultAggregateCostIsPinnedWhereMeasuredCorporaStayFarBelow(): void
    {
        // Guard-rail on the constant itself, MAX_CANDIDATE_PAIRS house
        // way: the largest real-corpus charge measured through this rule
        // on the lane box (phpdup's own src/: 4,090 blocks, 407,377
        // candidates — reviews/exec/engine-S10.impl.md transcript) is
        // 715,006 node-pairs, ~140× under the default; the finding's
        // storm mass (≈78k judged pairs of ~800-node members ≈ 5.0e10)
        // sits ~500× above it, so a storm halts at under 0.2% of its
        // enumeration instead of running past 400 s forever.
        $this->assertSame(100_000_000, Clusterer::DEFAULT_MAX_TED_COST);
        $this->assertSame(PHP_INT_MAX, Clusterer::UNLIMITED_TED_COST);

        $param = (new \ReflectionParameter([Clusterer::class, '__construct'], 'maxTedCost'));
        $this->assertSame(Clusterer::DEFAULT_MAX_TED_COST, $param->getDefaultValue(),
            'the engine default IS the constant (one source of truth)');

        $this->assertGreaterThan(100 * 715_006, Clusterer::DEFAULT_MAX_TED_COST,
            'measured real corpora must sit far under the budget');
        $this->assertLessThan(intdiv(50_000_000_000, 100), Clusterer::DEFAULT_MAX_TED_COST,
            'the budget must trip long before a cap-size storm finishes even a tenth of its verdicts');
    }
}
