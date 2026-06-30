<?php
declare(strict_types=1);

namespace Phpdup\Ml;

use Phpdup\Extraction\Block;
use Phpdup\Ir\IrLifter;
use Phpdup\Ir\IrPrinter;
use Phpdup\Semantic\DataflowSummarizer;
use Phpdup\Semantic\DbOperationTagger;

/**
 * Feature vector extractor for a pair of {@see Block} instances.
 *
 * Implements the input shape consumed by an external pair-scoring
 * ML model (option 6 of `docs/plans/orm-db-semantic-dedup.md`).
 *
 * The extractor is intentionally *read-only* — it derives every
 * feature from existing phpdup machinery (subtree hash, n-gram
 * fingerprint, dataflow summary, DB op tags, IR tokens) so the
 * Python sidecar can be retrained without touching phpdup itself.
 *
 * **Feature shape**
 *
 * For each pair `(A, B)` the extractor emits:
 *
 *   - `structural_hash_match`: bool — true when both subtree hashes
 *     are identical (a tier-0 type-1 clone signal).
 *   - `ngram_jaccard`: float in [0,1] — multiset Jaccard over the
 *     two n-gram bags (the existing tier-1 score).
 *   - `var_jaccard`, `call_jaccard`, `return_jaccard`: floats in
 *     [0,1] — pieces of the type-4 dataflow summary, separated so
 *     the model can weight them independently.
 *   - `db_tag_jaccard`: float in [0,1] — option-3 DB op-tag Jaccard.
 *   - `ir_token_jaccard`: float in [0,1] — option-5 IR token-stream
 *     Jaccard. Falls back to `0.0` when either lift fails.
 *   - `block_size_ratio`: float in [0,1] — `min(size) / max(size)`.
 *   - `kind_match`: bool — same `Block::$kind`.
 *
 * The numeric features are bounded; the booleans become `0`/`1` in
 * JSON. Adding/removing features is a wire-protocol change; bump
 * `featureVersion()` when the shape changes.
 */
final class PairFeatures
{
    public const FEATURE_VERSION = 1;

    public function __construct(
        private readonly DataflowSummarizer $dataflow = new DataflowSummarizer(),
        private readonly DbOperationTagger $tagger = new DbOperationTagger(),
        private readonly IrLifter $lifter = new IrLifter(),
        private readonly IrPrinter $printer = new IrPrinter(),
    ) {
    }

    /**
     * Extract features for a `(A, B)` pair as a wire-ready array.
     *
     * @return array<string,mixed>
     */
    public function extract(Block $a, Block $b): array
    {
        $hashMatch = $a->structuralHash !== '' && $a->structuralHash === $b->structuralHash;
        $ngramJaccard = self::jaccardMultiset($a->ngramBag ?? [], $b->ngramBag ?? []);

        $sa = $this->dataflow->summarize($a->canonical);
        $sb = $this->dataflow->summarize($b->canonical);

        $ta = $this->tagger->tag($a->canonical);
        $tb = $this->tagger->tag($b->canonical);

        $irA = $a->ast !== null ? $this->lifter->lift($a->ast) : null;
        $irB = $b->ast !== null ? $this->lifter->lift($b->ast) : null;

        $irJaccard = 0.0;
        if ($irA !== null && $irB !== null) {
            $irJaccard = self::jaccardListAsMultiset(
                $this->printer->tokens($irA),
                $this->printer->tokens($irB),
            );
        }

        return [
            'feature_version'        => self::FEATURE_VERSION,
            'structural_hash_match'  => $hashMatch,
            'ngram_jaccard'          => $ngramJaccard,
            'var_jaccard'            => self::jaccardSet($sa['vars'], $sb['vars']),
            'call_jaccard'           => self::jaccardMultiset($sa['calls'], $sb['calls']),
            'return_jaccard'         => self::jaccardMultiset(
                array_count_values($sa['returns']),
                array_count_values($sb['returns']),
            ),
            'db_tag_jaccard'         => self::jaccardMultiset($ta, $tb),
            'ir_token_jaccard'       => $irJaccard,
            'block_size_ratio'       => self::sizeRatio($a->size, $b->size),
            'kind_match'             => $a->kind === $b->kind,
            'block_a_kind'           => $a->kind,
            'block_b_kind'           => $b->kind,
        ];
    }

    /**
     * Stable feature-version identifier embedded in every payload so
     * the sidecar can warn when the model was trained against a
     * different feature shape.
     */
    public static function featureVersion(): int
    {
        return self::FEATURE_VERSION;
    }

    /**
     * @param array<string,bool> $a
     * @param array<string,bool> $b
     */
    private static function jaccardSet(array $a, array $b): float
    {
        if ($a === [] && $b === []) {
            return 1.0;
        }
        $i = 0;
        foreach ($a as $k => $_) {
            if (isset($b[$k])) {
                $i++;
            }
        }
        $u = count($a) + count($b) - $i;
        return $u > 0 ? $i / $u : 0.0;
    }

    /**
     * @param array<string|int,int> $a
     * @param array<string|int,int> $b
     */
    private static function jaccardMultiset(array $a, array $b): float
    {
        if ($a === [] && $b === []) {
            return 1.0;
        }
        $i = 0;
        $u = 0;
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
            $ca = $a[$k] ?? 0;
            $cb = $b[$k] ?? 0;
            $i += min($ca, $cb);
            $u += max($ca, $cb);
        }
        return $u > 0 ? $i / $u : 0.0;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function jaccardListAsMultiset(array $a, array $b): float
    {
        return self::jaccardMultiset(array_count_values($a), array_count_values($b));
    }

    private static function sizeRatio(int $a, int $b): float
    {
        $min = min($a, $b);
        $max = max($a, $b);
        return $max > 0 ? $min / $max : 0.0;
    }
}
