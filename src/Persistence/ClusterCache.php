<?php
declare(strict_types=1);

namespace Phpdup\Persistence;

use Phpdup\Clustering\Cluster;

/**
 * On-disk cache of the final cluster set for a given corpus shape.
 *
 * Cache hit policy is intentionally conservative: a snapshot is reused
 * iff the **complete sorted list of block ids + their structural hashes**
 * is bit-identical to the previous run's. That covers the common
 * incremental case (re-running phpdup on an unchanged corpus) without
 * the bookkeeping cost of partial edge invalidation.
 *
 * When at least one block changed (added, removed, or its
 * structuralHash drifted), the cache is invalidated wholesale and the
 * Cluster + Refactor stages run normally.
 *
 * Layout: <cacheDir>/clusters.idx — single file containing:
 *
 *   [
 *     'parser_version' => string,
 *     'config_key'     => sha1(serialize(relevant_fields)),
 *     'corpus_hash'    => sha1(sort(block_id . '|' . structuralHash)),
 *     'clusters'       => Cluster[],   // serialized; AST nodes detached
 *   ]
 *
 * Generated `Cluster` objects are stripped of their `generalizedAst`
 * before serialization (PhpParser nodes are large and not strictly
 * required for downstream reporting) — RefactorStage rebuilds them
 * on-demand from the cached members + holes when needed.
 */
final class ClusterCache
{
    private const PARSER_VERSION = 'cluster-cache-v1';

    public function __construct(
        private readonly string $cacheDir,
        private readonly string $configKey,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->cacheDir !== '';
    }

    /**
     * @param list<\Phpdup\Extraction\Block> $blocks
     * @return list<Cluster>|null  null on cache miss
     */
    public function load(array $blocks): ?array
    {
        if (!$this->isEnabled() || !is_dir($this->cacheDir)) {
            return null;
        }
        $file = $this->cacheDir . '/clusters.idx';
        if (!is_file($file)) {
            return null;
        }
        $blob = @file_get_contents($file);
        if ($blob === false || $blob === '') {
            return null;
        }
        $payload = @unserialize($blob, [
            'allowed_classes' => SerializedClassAllowList::blockCacheClasses(),
        ]);
        if (!is_array($payload)) {
            return null;
        }
        // Reject any payload where deserialization produced an
        // `__PHP_Incomplete_Class` — a tampered blob, or one written by
        // an incompatible build, has no business in the pipeline.
        foreach ($payload as $value) {
            if ($value instanceof \__PHP_Incomplete_Class) {
                return null;
            }
            if (is_array($value)) {
                foreach ($value as $inner) {
                    if ($inner instanceof \__PHP_Incomplete_Class) {
                        return null;
                    }
                }
            }
        }
        if (($payload['parser_version'] ?? null) !== self::PARSER_VERSION) return null;
        if (($payload['config_key'] ?? null)     !== $this->configKey)     return null;
        if (($payload['corpus_hash'] ?? null)    !== $this->hashCorpus($blocks)) return null;

        $clusters = $payload['clusters'] ?? null;
        return is_array($clusters) ? array_values($clusters) : null;
    }

    /**
     * @param list<\Phpdup\Extraction\Block> $blocks
     * @param list<Cluster> $clusters
     */
    public function save(array $blocks, array $clusters): void
    {
        if (!$this->isEnabled()) return;
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0o775, true);
        }
        // Strip the heavy AST nodes before serialization — they're
        // rebuilt on demand by RefactorStage when actually needed.
        $stripped = [];
        foreach ($clusters as $c) {
            $copy = clone $c;
            $copy->generalizedAst = null;
            $stripped[] = $copy;
        }
        $payload = [
            'parser_version' => self::PARSER_VERSION,
            'config_key'     => $this->configKey,
            'corpus_hash'    => $this->hashCorpus($blocks),
            'clusters'       => $stripped,
        ];
        @file_put_contents($this->cacheDir . '/clusters.idx', serialize($payload));
    }

    /** @param list<\Phpdup\Extraction\Block> $blocks */
    private function hashCorpus(array $blocks): string
    {
        $tokens = [];
        foreach ($blocks as $b) {
            // Include file path so renamed/moved files invalidate the cache.
            // Block ids and structural hashes can be identical across renames
            // (same content, single-file run, or unchanged sorted order).
            $tokens[] = $b->id . '|' . $b->file . '|' . $b->structuralHash;
        }
        sort($tokens);
        return sha1(implode("\n", $tokens));
    }
}
