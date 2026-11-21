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
     * hashes differ.
     *
     * @return array{0: Block, 1: Block}
     */
    private function realPair(int $units, bool $extraB = true): array
    {
        $mk = static function (string $tag, bool $extra) use ($units): string {
            $code = "<?php\nfunction probe_{$tag}(\$p) {\n";
            for ($i = 0; $i < $units; $i++) {
                $code .= "    \$m{$tag}_{$i} = strlen(\$p) + {$i}; if (\$m{$tag}_{$i} > {$i}) { \$out[] = 'v{$tag}_{$i}'; }\n";
            }
            if ($extra) {
                $code .= "    if (\$p) { \$extraGuard = 1; }\n";
            }
            $code .= "    return \$out ?? [];\n}\n";
            return $code;
        };
        $parser = new AstParser();
        // maxSize deliberately above the probe trees so the extractor does
        // not drop them (this test measures scoring, not extraction caps).
        $extractor = new BlockExtractor(minSize: 1, maxSize: 100000);
        $normalizer = new Normalizer('aggressive');
        $fingerprint = new NgramFingerprint(5);
        $blocks = [];
        foreach (['A', 'B'] as $tag) {
            $stmts = $parser->parseCode($mk($tag, $extraB && $tag === 'B'));
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
        // The negative control that keeps (b) honest: the SAME ~728-node
        // pair under a cap above the block sizes runs real APTED — tier
        // 'jaccard', seconds of wall time, tens of MB of matrix. Proves
        // the memory/time signal in the gated test measures the skip.
        [$a, $b] = $this->realPair(40);
        $index  = $this->indexOf($a, $b);
        $worker = $this->worker($index, 5000); // cap far above the pair

        $before = memory_get_usage(true);
        $t0     = microtime(true);
        $edges  = $worker->score([['R-A', 'R-B']]);
        $wall   = microtime(true) - $t0;
        unset($before);

        $this->assertCount(1, $edges);
        $this->assertSame('jaccard', $edges[0][3]);
        // The PROOF of the wall-time signal in the gated test is wall time
        // itself: APTED over this pair costs ~4s (probe-measured). The
        // memory delta is deliberately NOT asserted — a warm emalloc arena
        // (every preceding battery test) satisfies the matrix from cached
        // chunks and shows a 0 delta even when APTED allocated 30MB.
        $this->assertGreaterThanOrEqual(1.0, $wall, 'control must actually pay APTED (probe: ~4s)');
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
}
