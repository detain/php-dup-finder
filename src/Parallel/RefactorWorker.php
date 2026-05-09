<?php
declare(strict_types=1);

namespace Phpdup\Parallel;

use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\BlockAstLoader;
use Phpdup\Refactor\AntiUnifier;
use Phpdup\Refactor\ParameterSynthesizer;
use Phpdup\Refactor\PatternRecognizer;
use Phpdup\Refactor\SignatureBuilder;

/**
 * Worker routine for parallelized cluster anti-unification + tagging.
 *
 * Mirrors {@see PairScoreWorker}: each fork-child inherits the parent
 * cluster list via copy-on-write memory, runs the four serial Refactor
 * passes (anti-unify → synthesize parameters → build signature → tag
 * patterns) on its assigned chunk, and emits enrichment payloads back
 * to the master via the WorkerPool framing channel. The master applies
 * each payload to its own Cluster instance by id.
 *
 * Each emitted payload carries only the fields the four passes write
 * (generalizedAst, holes, signature, patternTags) plus the cluster id.
 * Worker-side blocks were not mutated, so we don't ship them back.
 *
 * @phpstan-type Enrichment array{
 *   id: string,
 *   generalizedAst: ?\PhpParser\Node,
 *   holes: list<\Phpdup\Refactor\Hole>,
 *   signature: ?string,
 *   patternTags: list<string>,
 * }
 */
final class RefactorWorker
{
    public function __construct(
        private readonly ?BlockAstLoader $astLoader,
        private readonly bool $optionalBlocksEnabled,
        private readonly int $optionalBlocksMaxPerCluster,
        private readonly int $optionalBlocksMinSegmentLength,
    ) {
    }

    /**
     * @param list<Cluster> $clusters
     * @return list<array{id: string, generalizedAst: ?\PhpParser\Node, holes: list<\Phpdup\Refactor\Hole>, signature: ?string, patternTags: list<string>}>
     */
    public function process(array $clusters): array
    {
        $antiUnifier = new AntiUnifier(
            $this->astLoader,
            $this->optionalBlocksEnabled,
            $this->optionalBlocksMaxPerCluster,
            $this->optionalBlocksMinSegmentLength,
        );
        $synth      = new ParameterSynthesizer();
        $sigBuilder = new SignatureBuilder();
        $patterns   = new PatternRecognizer();

        $out = [];
        foreach ($clusters as $cluster) {
            $antiUnifier->unify($cluster);
            $synth->synthesize($cluster);
            $sigBuilder->buildSignature($cluster);
            $patterns->tag($cluster);
            $out[] = [
                'id'             => $cluster->id,
                'generalizedAst' => $cluster->generalizedAst,
                'holes'          => $cluster->holes,
                'signature'      => $cluster->signature,
                'patternTags'    => $cluster->patternTags,
            ];
        }
        return $out;
    }
}
