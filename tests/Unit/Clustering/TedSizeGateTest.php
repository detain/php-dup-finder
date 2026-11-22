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
 *   (e) the engines share one default (800) and refuse caps < 1.
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
}
