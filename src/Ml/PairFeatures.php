<?php
declare(strict_types=1);

namespace Phpdup\Ml;

use PhpParser\Node;
use Phpdup\Extraction\Block;
use Phpdup\Ir\IrLifter;
use Phpdup\Ir\IrPrinter;
use Phpdup\Semantic\DataflowSummarizer;
use Phpdup\Semantic\DbOperationTagger;
use Phpdup\Similarity\JaccardSimilarity;

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
 *
 * **Per-block memoization (engine-033)**
 *
 * The expensive ingredients — the DataflowSummarizer and
 * DbOperationTagger walks of `$block->canonical` and the
 * IrLifter/IrPrinter pass over `$block->ast` — are derived **once per
 * Block object** and reused across every pair that shares the block:
 * a block appearing in `d` pairs pays one full derivation, not `d`.
 * The cache is instance-local (a `WeakMap`, so entries vanish together
 * with the Block itself — no `spl_object_id` reuse hazard, and nothing
 * is stored on `Block`, which several sibling steps contend over).
 * Each entry is guarded by the `(canonical, ast)` object identities it
 * was derived from, so a re-normalized or lazily reloaded/unloaded
 * AST refreshes the entry instead of serving a stale vector.
 * Pair-level combinators (the Jaccards) still run per pair, but now
 * consume per-block-prepared multisets via the two-loop
 * {@see JaccardSimilarity} instead of `array_merge + array_unique`.
 * The emitted vector — shape *and* values — is byte-identical to the
 * pre-memoization extractor (pinned in `PairFeaturesTest`).
 */
final class PairFeatures
{
    public const FEATURE_VERSION = 1;

    /**
     * Derived per-block ingredients, keyed by the Block object.
     * `source` remembers the exact `(canonical, ast)` nodes the
     * features were derived from; a mismatch means the block was
     * re-normalized or its AST swapped (lazy reload / unloadAst) and
     * the entry must be recomputed.
     *
     * @var \WeakMap<Block, array{
     *     source: array{0: Node, 1: ?Node},
     *     features: array{
     *         vars: array<string,bool>,
     *         calls: array<string,int>,
     *         returns: array<string,int>,
     *         tags: array<string,int>,
     *         irTokens: array<string,int>|null,
     *     },
     * }>
     */
    private \WeakMap $blockCache;

    /**
     * Seam counter for tests: full per-block derivations run since
     * construction (cache hits, including re-extracts of the same
     * pair, never bump it). Production code paths do not read it.
     */
    private int $perBlockDerivations = 0;

    public function __construct(
        private readonly DataflowSummarizer $dataflow = new DataflowSummarizer(),
        private readonly DbOperationTagger $tagger = new DbOperationTagger(),
        private readonly IrLifter $lifter = new IrLifter(),
        private readonly IrPrinter $printer = new IrPrinter(),
        private readonly JaccardSimilarity $jaccard = new JaccardSimilarity(),
    ) {
        $this->blockCache = new \WeakMap();
    }

    /**
     * Extract features for a `(A, B)` pair as a wire-ready array.
     *
     * @return array<string,mixed>
     */
    public function extract(Block $a, Block $b): array
    {
        $fa = $this->blockFeatures($a);
        $fb = $this->blockFeatures($b);

        $hashMatch = $a->structuralHash !== '' && $a->structuralHash === $b->structuralHash;

        $irJaccard = 0.0;
        if ($fa['irTokens'] !== null && $fb['irTokens'] !== null) {
            $irJaccard = $this->jaccardMultiset($fa['irTokens'], $fb['irTokens']);
        }

        return [
            'feature_version'        => self::FEATURE_VERSION,
            'structural_hash_match'  => $hashMatch,
            'ngram_jaccard'          => $this->jaccardMultiset($a->ngramBag ?? [], $b->ngramBag ?? []),
            'var_jaccard'            => self::jaccardSet($fa['vars'], $fb['vars']),
            'call_jaccard'           => $this->jaccardMultiset($fa['calls'], $fb['calls']),
            'return_jaccard'         => $this->jaccardMultiset($fa['returns'], $fb['returns']),
            'db_tag_jaccard'         => $this->jaccardMultiset($fa['tags'], $fb['tags']),
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
     * Number of full per-block derivations this instance has run
     * (engine-033 memo effectiveness, asserted by the unit suite).
     */
    public function perBlockDerivationCount(): int
    {
        return $this->perBlockDerivations;
    }

    /**
     * Per-block ingredients of the vector: dataflow vars/calls, the
     * return-shape multiset, DB op tags, and the pre-counted IR
     * token multiset (null when the block has no liftable AST).
     *
     * Memoized per Block object; refreshed whenever the underlying
     * `(canonical, ast)` node identities change.
     *
     * @return array{
     *     vars: array<string,bool>,
     *     calls: array<string,int>,
     *     returns: array<string,int>,
     *     tags: array<string,int>,
     *     irTokens: array<string,int>|null,
     * }
     */
    private function blockFeatures(Block $block): array
    {
        $source = [$block->canonical, $block->ast];
        $cached = $this->blockCache[$block] ?? null;
        if ($cached !== null && $cached['source'] === $source) {
            return $cached['features'];
        }

        $this->perBlockDerivations++;

        $summary = $this->dataflow->summarize($block->canonical);
        $features = [
            'vars'     => $summary['vars'],
            'calls'    => $summary['calls'],
            'returns'  => array_count_values($summary['returns']),
            'tags'     => $this->tagger->tag($block->canonical),
            'irTokens' => $this->irTokenBag($block),
        ];

        $this->blockCache[$block] = ['source' => $source, 'features' => $features];
        return $features;
    }

    /**
     * Counted IR token stream for one block, or null when the block
     * has no AST or the lifter rejects it — exactly the condition the
     * pre-memoization extractor used to gate `ir_token_jaccard`.
     *
     * @return array<string,int>|null
     */
    private function irTokenBag(Block $block): ?array
    {
        if ($block->ast === null) {
            return null;
        }
        $ir = $this->lifter->lift($block->ast);
        if ($ir === null) {
            return null;
        }
        return array_count_values($this->printer->tokens($ir));
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
     * Multiset Jaccard over two counted bags.
     *
     * The both-empty case is a vacuous perfect match (1.0) — a wire
     * value the sidecar was trained on, so it is kept here instead of
     * inheriting {@see JaccardSimilarity}'s 0.0 for empty inputs.
     * Every non-vacuous pair delegates to the two-loop implementation
     * (no `array_merge`/`array_unique` temporaries).
     *
     * @param array<string|int,int> $a
     * @param array<string|int,int> $b
     */
    private function jaccardMultiset(array $a, array $b): float
    {
        if ($a === [] && $b === []) {
            return 1.0;
        }
        return $this->jaccard->similarity($a, $b);
    }

    private static function sizeRatio(int $a, int $b): float
    {
        $min = min($a, $b);
        $max = max($a, $b);
        return $max > 0 ? $min / $max : 0.0;
    }
}
