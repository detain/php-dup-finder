<?php
declare(strict_types=1);

namespace Phpdup\Tests\Unit\Parallel;

use PHPUnit\Framework\TestCase;
use Phpdup\Cli\Config;
use Phpdup\Clustering\Clusterer;
use Phpdup\Index\BlockIndex;
use Phpdup\Parallel\PairScoreWorker;
use Phpdup\Parallel\WorkerPool;
use Phpdup\Pipeline\PipelineState;
use Phpdup\Pipeline\Stages\ClusterStage;
use Phpdup\Pipeline\Stages\PreprocessStage;
use Phpdup\Pipeline\Stages\ScanningStage;
use Phpdup\Similarity\EditCostModel;
use Phpdup\Similarity\JaccardSimilarity;
use Phpdup\Similarity\TreeEditDistance;
use Symfony\Component\Console\Output\NullOutput;

/**
 * php-dup-finder-shell-004 — fork-zone APTED parity for `--ted-weights`.
 *
 * The serial twin (ClusterStage::buildClusterer → Clusterer) always
 * honoured the configured edit-cost model: `new TreeEditDistance(new
 * EditCostModel($config->tedWeights), …)`. The fork-zone twin hard-coded
 * `new TreeEditDistance(null, …)` inside PairScoreWorker::score() — the
 * worker had no tedWeights parameter at all — so under any non-default
 * model the two engines scored the SAME candidate pairs with DIFFERENT
 * cost tables, and edge scores (hence cluster similarity) forked with
 * worker count.
 *
 * How the divergence is made observable (design notes from measurement):
 *   For insertion-shaped deltas on aggressively-normalized functions,
 *   bag Jaccard sits BELOW the TED verdict — 5-gram key sets are polluted
 *   by an inserted statement roughly twice as hard as tree-edit distance
 *   is — so `min(jac, ted)` normally pins edges to Jaccard and the cost
 *   model cannot move the edge score at all. The corpus is shaped so the
 *   pin is lifted: each appended guard is a cheap 10-node statement inside
 *   a 148-node base, keeping `jac ≥ 0.86` for every pair, and rung pairs
 *   that span THREE guards push the weighted APTED verdict above the
 *   Jaccard value while the DEFAULT model's still sits below it:
 *       ted_default  <  jaccard  <  ted_semantic
 *   The default engine then scores the pair by its (lower) APTED value
 *   while the semantic engine scores it by Jaccard — different floats on
 *   the same edge, no threshold theatre, no set-membership games. (For the
 *   record: acceptance flips are only reachable with a hand-tuned
 *   treeThreshold straddling the two models, i.e. by pinning behaviour
 *   outside the product's defaults; value divergence is the honest signal.)
 *
 * Corpus: two families (distinct names only — same body per rung, so
 * cross-family rung pairs are exact-hash), each holding variants with 0..5
 * appended `foreach` guards on a 10-statement base. Files are scanned in
 * isolation; the aggressive Normalizer renumbers `__V0…` identically across
 * the family, so the ONLY surviving difference between rungs is the guard
 * count — a clean structural delta (no identifier renames, no bag-key
 * drift). 12 blocks → 66 candidate pairs → the fork path's ≥64 arming
 * threshold fires.
 *
 * Proven:
 *   (a) value-discrimination: the same in-process worker scores at least
 *       one edge differently under 'semantic' than under 'default'
 *       (mutation pin — if this ever goes equal, (b)/(c) are vacuous);
 *   (b) forked workers (workers=3, real pcntl fork, semantic config)
 *       emit the same normalized edge list as the in-process worker
 *       under the same config;
 *   (c) the forked list also equals the serial Clusterer twin built from
 *       the same config (dual-engine parity, the law the header of
 *       PairScoreWorker has always claimed);
 *   (d) the threading parameter is a scalar string (Socketpair Law) and
 *       an unknown model name fails fast at construction.
 */
final class PairScoreWorkerTedParityTest extends TestCase
{
    /** Base statements per variant — ~148 AST nodes after normalization. */
    private const BASE_UNITS = 10;

    /** Guard-statement counts per family: 6 rungs × 2 families = 12 blocks → C(12,2) = 66 pairs. */
    private const GUARD_RUNGS = [0, 1, 2, 3, 4, 5];
    private const FAMILIES = ['alpha', 'beta'];

    private string $corpus;

    /** @var BlockIndex|null lazily built once, shared by all arms */
    private ?BlockIndex $index = null;

    /** @var list<array{0: string, 1: string}>|null candidate pairs of that index */
    private ?array $pairs = null;

    protected function setUp(): void
    {
        $this->corpus = sys_get_temp_dir() . '/phpdup-tedparity-' . bin2hex(random_bytes(6));
        mkdir($this->corpus, 0o775, true);

        foreach (self::FAMILIES as $family) {
            foreach (self::GUARD_RUNGS as $rung) {
                // Same function name in both families — files are scanned in
                // isolation, so declaration names never collide; identical
                // bodies keep cross-family rung pairs on the exact-hash lane.
                file_put_contents(
                    $this->corpus . "/{$family}{$rung}.php",
                    $this->renderVariant((int) $rung),
                );
            }
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->corpus . '/*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->corpus);
    }

    /**
     * One rung: BASE_UNITS identical statements, then $rung appended foreach
     * guards. The guards are the ONLY structural difference between rungs;
     * trailing position keeps every pre-guard __V-ordinal stable.
     */
    private function renderVariant(int $rung): string
    {
        $lines = ['<?php', 'function probe($p) {'];
        for ($i = 0; $i < self::BASE_UNITS; $i++) {
            $lines[] = "    \$v_{$i} = strlen(\$p) + {$i}; \$out[] = \$v_{$i};";
        }
        for ($g = 0; $g < $rung; $g++) {
            $lines[] = "    foreach ((array)\$p as \$k{$g} => \$v{$g}) { \$acc[\$k{$g}] = \$v{$g} + {$g}; }";
        }
        $lines[] = '    return $out ?? [];';
        $lines[] = '}';
        return implode("\n", $lines) . "\n";
    }

    private function baseConfig(int $workers, string $weights): Config
    {
        return new Config(
            paths: [$this->corpus],
            exclude: [],
            allowedKinds: ['function'],
            // df=1.0 admits every gram: the base grams sit at df=12/12 and
            // the legacy maxDf cap would otherwise starve the inverted index
            // — here every rung pair must reach the scorer.
            maxDocumentFrequency: 1.0,
            incremental: false,
            workers: $workers,
            tedWeights: $weights,
        );
    }

    /**
     * Drive the REAL pipeline (scan → preprocess → cluster) so scoring runs
     * the production path. $workers >= 2 + >= 64 pairs arms the fork zone.
     *
     * @return array{edges: list<array{0: string, 1: string, 2: float, 3: string}>, index: BlockIndex, candidatePairs: int}
     */
    private function runStage(int $workers, string $weights): array
    {
        $config = $this->baseConfig($workers, $weights);
        $state = new PipelineState($config);
        (new ScanningStage())->run($state, new NullOutput());
        (new PreprocessStage(useCache: false))->run($state, new NullOutput());
        (new ClusterStage(exactOnly: false, useClusterCache: false))->run($state, new NullOutput());

        $this->assertCount(12, $state->blocks, 'corpus shape drift — the pins below assume 12 blocks');

        return [
            'edges' => $state->edges,
            'index' => $state->index ?? new BlockIndex(),
            'candidatePairs' => $state->candidatePairs,
        ];
    }

    /**
     * The fork-zone scorer invoked in-process over the shared index/pairs
     * pair set — identical code path to the stage's workers=1 arm, no fork.
     *
     * @return list<array{0: string, 1: string, 2: float, 3: string}>
     */
    private function scoreInProcess(string $weights): array
    {
        $config = $this->baseConfig(1, $weights);
        return (new PairScoreWorker(
            index: $this->sharedIndex(),
            similarityThreshold: $config->similarityThreshold,
            treeThreshold: $config->treeThreshold,
            optionalBlocksEnabled: $config->optionalBlocksEnabled,
            containmentThreshold: $config->optionalBlocksContainment,
            optionalBlocksMinOverlap: $config->optionalBlocksMinOverlap,
            unnumbered: $config->fingerprintIdsUnnumbered(),
            maxTedSize: $config->maxTedSize,
            tedWeights: $config->tedWeights,
        ))->score($this->sharedPairs());
    }

    /** @return list<array{0: string, 1: string}> */
    private function sharedPairs(): array
    {
        if ($this->pairs === null) {
            $clusterer = (new \ReflectionMethod(ClusterStage::class, 'buildClusterer'))
                ->invoke(new ClusterStage(exactOnly: false), $this->baseConfig(1, EditCostModel::MODEL_DEFAULT));
            /** @var list<array{0: string, 1: string}> $pairs */
            $pairs = iterator_to_array($clusterer->generateCandidatePairs(
                $this->sharedIndex(),
                new NullOutput(),
                Clusterer::UNLIMITED_CANDIDATE_PAIRS,
            ));
            $this->pairs = $pairs;
        }
        return $this->pairs;
    }

    private function sharedIndex(): BlockIndex
    {
        if ($this->index === null) {
            $state = new PipelineState($this->baseConfig(1, EditCostModel::MODEL_DEFAULT));
            (new ScanningStage())->run($state, new NullOutput());
            (new PreprocessStage(useCache: false))->run($state, new NullOutput());
            $index = new BlockIndex();
            foreach ($state->blocks as $b) {
                $index->add($b);
            }
            $this->index = $index;
        }
        return $this->index;
    }

    /**
     * Order-normalised edge map: canonically-oriented pair key → [score, tier].
     * Union-find/split are order-insensitive over edges, but asserting on raw
     * lists would make the fork arm flaky on chunk interleaving, so every
     * side is normalised identically. Scores are rounded to 6 decimals — the
     * default treeThreshold 0.85 puts the rung-vs-rung+3 edges ~0.003 apart
     * between models, six orders of magnitude above float noise.
     *
     * @param list<array{0: string, 1: string, 2: float, 3: string}> $rows
     * @return array<string, array{0: float, 1: string}>
     */
    private function edgeMap(array $rows): array
    {
        $out = [];
        foreach ($rows as [$a, $b, $sim, $tier]) {
            $key = strcmp($a, $b) < 0 ? $a . '|' . $b : $b . '|' . $a;
            $out[$key] = [round($sim, 6), $tier];
        }
        ksort($out);
        return $out;
    }

    // ------------------------------------------------------------------
    // (a) The corpus bites: model choice moves at least one edge score.
    // ------------------------------------------------------------------

    public function testCorpusIsValueDiscriminativeBetweenCostModels(): void
    {
        $default  = $this->edgeMap($this->scoreInProcess(EditCostModel::MODEL_DEFAULT));
        $semantic = $this->edgeMap($this->scoreInProcess(EditCostModel::MODEL_SEMANTIC));

        $this->assertNotEmpty($default, 'corpus must produce scored edges');
        $this->assertSame(array_keys($default), array_keys($semantic),
            'the two models must agree on WHICH pairs are edges — only scores may differ '
            . '(a key difference means a pair left the lane under one model: pin drift)');

        $differing = 0;
        foreach ($default as $key => $row) {
            if ($row[0] !== $semantic[$key][0]) {
                $differing++;
            }
        }
        $this->assertGreaterThan(0, $differing,
            'mutation pin: on this corpus semantic weighting must move at least one edge score; '
            . 'if the models ever agree everywhere, the parity assertions below are vacuous');

        // Direction of the divergence (measured): insertion-shaped deltas
        // cost APTED slightly LESS than they cost the 5-gram bag, so the
        // default model stays jaccard-pinned while semantic's discount on
        // the cheap inserted literals/assignments lifts its verdict past
        // jaccard… net effect on the min-capped score: semantic edges are
        // never LOWER, and the differing ones are strictly HIGHER.
        $meanDefault  = array_sum(array_column($default, 0)) / count($default);
        $meanSemantic = array_sum(array_column($semantic, 0)) / count($semantic);
        $this->assertGreaterThan($meanDefault, $meanSemantic,
            'mean edge similarity must rise under semantic weighting on this insertion-shaped corpus');
    }

    // ------------------------------------------------------------------
    // (b) Fork boundary carries the configured model.
    // ------------------------------------------------------------------

    public function testForkedWorkersProduceTheSameEdgesAsInProcessScoring(): void
    {
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl not available — fork arm cannot be exercised');
        }

        $parallel = $this->runStage(3, EditCostModel::MODEL_SEMANTIC);
        $this->assertGreaterThanOrEqual(64, $parallel['candidatePairs'],
            'the fork path only arms at >= 64 candidate pairs; this corpus must force it');
        $this->assertNotEmpty($parallel['edges'], 'non-vacuity: the fork arm must actually emit edges');

        $inProcess = $this->edgeMap($this->scoreInProcess(EditCostModel::MODEL_SEMANTIC));
        $this->assertSame(
            $inProcess,
            $this->edgeMap($parallel['edges']),
            'forked PairScoreWorker must emit the same scored edges as the in-process twin under '
            . 'the SAME config — divergence means --ted-weights does not cross the fork boundary',
        );
    }

    // ------------------------------------------------------------------
    // (c) Dual-engine parity: fork zone == serial Clusterer twin.
    // ------------------------------------------------------------------

    public function testForkedScoringMatchesTheSerialClustererTwin(): void
    {
        if (!WorkerPool::isAvailable()) {
            $this->markTestSkipped('pcntl not available — fork arm cannot be exercised');
        }

        $parallel = $this->runStage(3, EditCostModel::MODEL_SEMANTIC);

        // The serial twin: Clusterer wired exactly as ClusterStage::buildClusterer
        // wires it for a semantic run, scoring the same candidate set.
        $serial = new Clusterer(
            similarity: new JaccardSimilarity(),
            tree: new TreeEditDistance(new EditCostModel(EditCostModel::MODEL_SEMANTIC), false),
            similarityThreshold: $this->baseConfig(1, EditCostModel::MODEL_SEMANTIC)->similarityThreshold,
            treeThreshold: $this->baseConfig(1, EditCostModel::MODEL_SEMANTIC)->treeThreshold,
            maxDocumentFrequency: 1.0,
            scanRoots: [$this->corpus],
        );
        $serialEdges = (new \ReflectionMethod(Clusterer::class, 'scorePairsSerially'))
            ->invoke($serial, $this->sharedIndex());

        $this->assertSame(
            $this->edgeMap($serialEdges),
            $this->edgeMap($parallel['edges']),
            'dual-engine parity (the law PairScoreWorker::score states in its own comments): '
            . 'the fork zone must honour --ted-weights exactly like the serial scorer',
        );
        $this->assertNotEmpty($serialEdges, 'non-vacuity: the serial twin must emit edges too');
    }

    // ------------------------------------------------------------------
    // (d) Wire-shape + fail-fast pins on the new parameter.
    // ------------------------------------------------------------------

    public function testThreadingParameterIsAScalarStringForTheWire(): void
    {
        // Socketpair Law: the model reaches each fork child as a plain
        // string — never an EditCostModel object. Reflection is the durable
        // form of that pin (an object param would be legal PHP but illegal
        // on this channel).
        $param = null;
        foreach ((new \ReflectionMethod(PairScoreWorker::class, '__construct'))->getParameters() as $p) {
            if ($p->getName() === 'tedWeights') {
                $param = $p;
                break;
            }
        }
        $this->assertNotNull($param, 'PairScoreWorker::__construct must expose the tedWeights parameter');
        $this->assertSame('string', (string) $param->getType());
        $this->assertTrue($param->isDefaultValueAvailable());
        $this->assertSame(EditCostModel::MODEL_DEFAULT, $param->getDefaultValue(),
            'the default must be the legacy model — every pre-existing call site (and the golden '
            . 'suite) relies on omitting it being byte-identical to the old hardcoded-null behaviour');
    }

    public function testWorkerRefusesAnUnknownCostModelAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PairScoreWorker(
            index: new BlockIndex(),
            similarityThreshold: 0.8,
            treeThreshold: 0.85,
            tedWeights: 'sentimental',
        );
    }

    public function testOmittedParameterEqualsExplicitDefault(): void
    {
        // The backward-compat triangle: a worker built with NO model argument
        // (every legacy call site) must score exactly like one built with an
        // explicit 'default' — this is the constructor-level restatement of
        // the golden-suite byte-identity requirement.
        $config = $this->baseConfig(1, EditCostModel::MODEL_DEFAULT);
        $legacy = new PairScoreWorker(
            index: $this->sharedIndex(),
            similarityThreshold: $config->similarityThreshold,
            treeThreshold: $config->treeThreshold,
            optionalBlocksEnabled: $config->optionalBlocksEnabled,
            containmentThreshold: $config->optionalBlocksContainment,
            optionalBlocksMinOverlap: $config->optionalBlocksMinOverlap,
            unnumbered: $config->fingerprintIdsUnnumbered(),
            maxTedSize: $config->maxTedSize,
        );
        $explicit = $this->edgeMap($this->scoreInProcess(EditCostModel::MODEL_DEFAULT));
        $this->assertSame($explicit, $this->edgeMap($legacy->score($this->sharedPairs())));
    }

    public function testClusterCacheKeySeparatesCostModels(): void
    {
        // The edge SCORES now depend on the model in both engines, so a
        // cluster cache written under one model must never be served to a
        // run under the other (same law as maxTedSize/fingerprintIdMode —
        // see cacheConfigKey's field-by-field comments). Binding it is part
        // of honouring --ted-weights end to end.
        $stage = new ClusterStage(exactOnly: false);
        $key = new \ReflectionMethod(ClusterStage::class, 'cacheConfigKey');
        $kDefault  = $key->invoke($stage, $this->baseConfig(1, EditCostModel::MODEL_DEFAULT));
        $kSemantic = $key->invoke($stage, $this->baseConfig(1, EditCostModel::MODEL_SEMANTIC));
        $this->assertNotSame($kDefault, $kSemantic,
            'cost-model flip must invalidate the cluster cache');
        $this->assertSame($kDefault, $key->invoke($stage, $this->baseConfig(1, EditCostModel::MODEL_DEFAULT)),
            'same-model key must be stable');
    }
}
