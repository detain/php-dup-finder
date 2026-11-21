<?php
declare(strict_types=1);

namespace Phpdup\Persistence;

use Phpdup\Clustering\Cluster;
use Phpdup\Parsing\CacheBlob;

/**
 * On-disk cache of the final cluster set for a given corpus shape.
 *
 * Cache hit policy is intentionally conservative: a snapshot is reused
 * iff the **complete sorted set of member file paths + structural hashes**
 * is bit-identical to the previous run's. That covers the common
 * incremental case (re-running phpdup on an unchanged corpus) without
 * the bookkeeping cost of partial edge invalidation.
 *
 * When at least one block changed (added, removed, or its
 * structuralHash drifted), the cache is invalidated wholesale and the
 * Cluster + Refactor stages run normally.
 *
 * Layout (shell-S18: header-before-payload), `<cacheDir>/clusters.idx`:
 *
 *     PHPDUP-CCACHE1
 *     parser_version:<PARSER_VERSION>
 *     config_key:<sha1(serialize(relevant_fields))>
 *     corpus_hash:<sha1(sort(file . '|' . structuralHash))>
 *     <blank line>
 *     <byte-length>:<serialize(['clusters' => Cluster[]])>
 *
 * The plain-text meta prefix is validated from a bounded header read
 * before a single payload byte is opened, so a stale build, a config
 * flip, or a drifted corpus misses without ever unserializing the
 * cluster set it is about to discard. The payload is length-framed with
 * the shared {@see CacheBlob} codec — truncated or appended files are
 * framing misses, not corrupted decodes. Pre-S18 blobs (one raw
 * serialize() of the whole array) miss cleanly through the same header
 * check: existing caches self-invalidate once, never crash.
 *
 * Generated `Cluster` objects are stripped of their `generalizedAst`
 * before serialization (PhpParser nodes are large and not strictly
 * required for downstream reporting) — RefactorStage rebuilds them
 * on-demand from the cached members + holes when needed.
 */
final class ClusterCache
{
    /** First line of a framed-header cache file — the format-generation marker. */
    private const MAGIC = 'PHPDUP-CCACHE1';

    /** Bound of the header read; this writer's header is ~130 bytes. */
    private const HEADER_READ_BYTES = 4096;

    // cluster-cache-v3 (item 4.4, hazard-4): v2 corpus hashes baked the
    // scan-index block id into every token, so an unchanged corpus
    // re-scanned in a different order invalidated the cache for nothing.
    // Tokens are now file|structuralHash only — v2 blobs (and the older
    // pre-split v1 sets) must never be served by an id-agnostic run.
    // Wholesale invalidation; the config_key still binds the split knobs.
    // 'v4' (phpver-fix): finding ids are minted from scan-root-relative
    // paths, so cached clusters from v3-era runs carry ids the live
    // recipe would never produce. Stale ids must never be served.
    // shell-S18 deliberately does NOT re-bump this constant: the format
    // magic line already refuses every pre-S18 blob structurally, and a
    // second invalidation on top of the first is pure cache churn.
    private const PARSER_VERSION = 'cluster-cache-v4';

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

        // Bounded header read — the payload is only opened once every
        // metadata gate below has passed (shell-009: the pre-S18 loader
        // unserialized the whole cluster set before checking any key).
        $prefix = @file_get_contents($file, false, null, 0, self::HEADER_READ_BYTES);
        if (!is_string($prefix)) {
            return null;
        }
        $header = self::parseHeader($prefix);
        if ($header === null) {
            return null;   // pre-S18 raw blob, malformed, or foreign file
        }
        [$meta, $payloadStart] = $header;

        if (($meta['parser_version'] ?? '') !== self::PARSER_VERSION) return null;
        if (($meta['config_key'] ?? '') !== $this->configKey) return null;
        if (($meta['corpus_hash'] ?? '') !== $this->hashCorpus($blocks)) return null;

        $framed = @file_get_contents($file, false, null, $payloadStart);
        if (!is_string($framed)) {
            return null;
        }
        $blob = CacheBlob::unframe($framed);
        if ($blob === null) {
            return null;   // truncated or appended write → miss
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
        // (Recursive member-shape guards land on top of this read path
        // in the shell-S19 chain step; this is the legacy flat screen.)
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
            @mkdir($this->cacheDir, 0o700, true);
        }
        // Strip the heavy AST nodes before serialization — they're
        // rebuilt on demand by RefactorStage when actually needed.
        $stripped = [];
        foreach ($clusters as $c) {
            $copy = clone $c;
            $copy->generalizedAst = null;
            $stripped[] = $copy;
        }
        try {
            $blob = serialize(['clusters' => $stripped]);
        } catch (\Throwable) {
            return;   // unserializable shape → cache nothing, re-cluster next run
        }

        $bytes = self::buildHeader([
            'parser_version' => self::PARSER_VERSION,
            'config_key'     => $this->configKey,
            'corpus_hash'    => $this->hashCorpus($blocks),
        ]) . CacheBlob::frame($blob);

        // No LOCK_EX here yet by design — the concurrent-save guarantee
        // (and its unit) lands on this write in the shell-S19 chain step.
        if (@file_put_contents($this->cacheDir . '/clusters.idx', $bytes) === false) return;
        @chmod($this->cacheDir . '/clusters.idx', 0o600);
    }

    /**
     * Parse the plain-text meta prefix of a framed cache file.
     *
     * @return array{0: array<string,string>, 1: int}|null  [meta, payload
     *         start offset], or null whenever the file is not a readable
     *         framed blob of this generation — wrong/absent magic (every
     *         pre-S18 raw-serialize blob lands here), a header that does
     *         not terminate, or a malformed line. A bad cache file is a
     *         miss, never an error.
     */
    private static function parseHeader(string $prefix): ?array
    {
        if (!str_starts_with($prefix, self::MAGIC . "\n")) return null;
        $bodyStart = strlen(self::MAGIC) + 1;
        $end = strpos($prefix, "\n\n", $bodyStart);
        if ($end === false) return null;

        $meta = [];
        foreach (explode("\n", substr($prefix, $bodyStart, $end - $bodyStart)) as $line) {
            $colon = strpos($line, ':');
            if ($colon === false || $colon === 0) return null;
            // Unknown keys are skipped so chain steps (shell-S30) can add
            // metadata without breaking this reader; duplicate keys: last wins.
            $meta[substr($line, 0, $colon)] = substr($line, $colon + 1);
        }
        return [$meta, $end + 2];
    }

    /** @param array<string,string> $fields */
    private static function buildHeader(array $fields): string
    {
        $lines = [self::MAGIC];
        foreach ($fields as $key => $value) {
            $lines[] = $key . ':' . $value;
        }
        return implode("\n", $lines) . "\n\n";
    }

    /**
     * Corpus fingerprint over the structural member set alone
     * (item 4.4 / hazard-4): no scan-index block id and nothing
     * positional participates, so an unchanged corpus re-scanned in any
     * order replays the same hash.
     *
     * Known residual: the tokens embed raw, cwd-dependent file paths, so
     * the cache is path-bound — analyzing the same tree through a
     * different root (symlinked parent, run from a sibling directory)
     * rewrites every token and lands on a honest cache MISS that
     * recomputes everything; it can never produce a stale HIT. Going
     * root-independent would mean scan-root-relative member tokens, a
     * v5 invalidation deliberately left unimplemented (see HANDOFF).
     *
     * @param list<\Phpdup\Extraction\Block> $blocks
     */
    private function hashCorpus(array $blocks): string
    {
        $tokens = [];
        foreach ($blocks as $b) {
            // Structural member set only (item 4.4 / hazard-4): the block
            // id embeds the scan index, so baking it into the corpus hash
            // made cache hits arrival-order dependent. File path still
            // keys renames/moves; structuralHash keys content.
            $tokens[] = $b->file . '|' . $b->structuralHash;
        }
        sort($tokens);
        return sha1(implode("\n", $tokens));
    }
}
