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
 *
 * The payload shape is guarded recursively on read (shell-S19, finding
 * shell-031): a disallowed class smuggled in at any depth, or a
 * non-Cluster element in a payload that passed every header gate, is a
 * provable contract violation and the entry is REFUSED with a
 * `phpdup: refusing corrupt ...` runtime error instead of being served
 * corrupt (pre-S19 the flat screen saw only payload values and one
 * array level; a stdClass hidden in a Cluster's `holes` rode straight
 * through to the pipeline). The bounded walk is the ONE implementation
 * shared with the block store — {@see IndexStore::findIncompleteClass()}
 * (hoist to SerializedClassAllowList once shell-S11 lands). Saves take
 * LOCK_EX: concurrent writers can no longer interleave a torn blob.
 * The one content-free payload — a bare `serialize([])` — is not a
 * refusal but an ordinary miss (once-S64, php-dup-finder-once-073):
 * zero bytes can hide zero poison, the slot simply recomputes and
 * self-heals, and an empty set may never become the permanent HIT the
 * finding's DATA-005 era produced.
 *
 * **The directory itself is part of the trust surface (once-S64
 * normalizing this class onto the shell-S39 IndexStore posture):** a
 * group- or world-writable cache dir lets any co-writer plant
 * `clusters.idx` and the per-file `.idx` files these stores would
 * otherwise believe (the header gates prove format, not authorship).
 * The constructor makes a FRESH dir absolute 0700 (mkdir's mode is a
 * umask-masked request; the chmod is not), and for a PRE-EXISTING dir
 * it strips foreign write (0775 → 0755 — exactly the mode this ctor's
 * pre-S18 ancestors created) or, when that is impossible, switches the
 * store off. A private dir is a condition of caching, never of
 * running: every disabled case degrades to plain cache misses, and a
 * disabled store never writes through the path it refused.
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

    /**
     * Verdict of the once-S64 privacy enforcement run by the
     * constructor (the shell-S39 posture {@see IndexStore} has carried
     * since that lane — mirrored here verbatim so both framed
     * persistence classes answer forgeability the same way): false
     * when the cache dir exists (or was created) but cannot be made
     * non-forgeable — a symlinked path, an unfixable mode.
     * {@see self::isEnabled()} carries it, so a poisoned-by-construction
     * tree is never read from or written to.
     */
    private bool $privateDirOk = true;

    public function __construct(
        private readonly string $cacheDir,
        private readonly string $configKey,
    ) {
        if ($this->cacheDir !== '') {
            $this->enforcePrivateCacheDir();
        }
    }

    public function isEnabled(): bool
    {
        return $this->cacheDir !== '' && is_dir($this->cacheDir) && $this->privateDirOk;
    }

    /**
     * shell-S39 posture, spelled once per framed store (see
     * {@see IndexStore::enforcePrivateCacheDir()} — the two bodies are
     * the same doctrine at the same surface; hoisting them behind a
     * shared trait is out of this step's locks):
     *
     *  - symlink         → refuse (never chmod or cache through a link
     *                      we did not create — ServeCommand:is_link
     *                      precedent);
     *  - missing         → mkdir(0700) + chmod(0700) so the mode is
     *                      absolute even under a hostile umask, then
     *                      verify the fresh dir really is 0700;
     *  - pre-existing    → adopt as-is when already private to us;
     *                      otherwise strip group/other WRITE (reads
     *                      are not the forgery vector — write is),
     *                      re-verify, and switch the store off on any
     *                      residual doubt.
     *
     * "Switch off" is never an error: the pipeline runs exactly as it
     * does with `--no-cache`, it simply never replays — or writes —
     * bytes a stranger could have planted.
     */
    private function enforcePrivateCacheDir(): void
    {
        $dir = $this->cacheDir;

        if (is_link($dir)) {
            $this->privateDirOk = false;
            return;
        }

        if (!is_dir($dir)) {
            $created = @mkdir($dir, 0o700, true);
            if (!$created || !is_dir($dir)) {
                return; // no dir at all — isEnabled()'s is_dir() gate owns that miss
            }
            // mkdir's mode argument is a REQUEST the process umask
            // masks; chmod makes the 0700 intent absolute — then
            // verify, and treat any doubt as "not ours to trust".
            @chmod($dir, 0o700);
            clearstatcache(true, $dir);
            $mode = @fileperms($dir);
            $this->privateDirOk = is_int($mode) && ($mode & 0o777) === 0o700;
            return;
        }

        clearstatcache(true, $dir);
        $mode = @fileperms($dir);
        if (!is_int($mode) || ($mode & 0o022) === 0) {
            // Already not forgeable; unobservable stats keep the
            // pre-existing adopt semantics (isEnabled()'s is_dir gate
            // owns the vanished-path case).
            return;
        }
        @chmod($dir, $mode & ~0o022 & 0o7777);
        clearstatcache(true, $dir);
        $after = @fileperms($dir);
        $this->privateDirOk = is_int($after) && ($after & 0o022) === 0;
    }

    /**
     * @param list<\Phpdup\Extraction\Block> $blocks
     * @return list<Cluster>|null  null on cache miss
     */
    public function load(array $blocks): ?array
    {
        if (!$this->isEnabled()) {
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
        // once-S64 (php-dup-finder-once-073): bare serialize([]) —
        // content-free, same anti-DATA-005 grammar as the block
        // store's arm (see IndexStore::load() for the full rationale):
        // miss and self-heal, never a hit, and too little content to
        // justify a permanent refusal. A payload that carries bytes
        // yet breaks the envelope contract still refuses below.
        if ($payload === []) {
            return null;
        }
        // shell-S19 (finding shell-031): payload shape guards — the same
        // contract IndexStore::load() enforces (see the refusal rationale
        // there). The pre-S19 screen checked payload values and ONE array
        // level, so a poison nested inside a legal Cluster was still
        // served. Unprovable-by-budget graphs fall back to the plain
        // miss; proven violations refuse loudly.
        $clusters = $payload['clusters'] ?? null;
        if ($clusters === null) {
            throw self::corrupt('the payload carries no "clusters" section', $file);
        }
        if (!is_array($clusters)) {
            throw self::corrupt(sprintf('the "clusters" section is %s, not a list', get_debug_type($clusters)), $file);
        }

        $poison = IndexStore::findIncompleteClass($payload);
        if ($poison === IndexStore::SCAN_UNRESOLVED) {
            return null;   // not provable — miss, never serve unchecked
        }
        if ($poison !== null) {
            throw self::corrupt(
                sprintf('a "%s" object hides inside the decoded graph — no writer of this format ever serializes it', $poison),
                $file,
            );
        }

        foreach ($clusters as $position => $cluster) {
            if (!$cluster instanceof Cluster) {
                throw self::corrupt(
                    sprintf('cluster #%d is %s, not a Cluster snapshot', $position, get_debug_type($cluster)),
                    $file,
                );
            }
        }

        return array_values($clusters);
    }

    /**
     * @param list<\Phpdup\Extraction\Block> $blocks
     * @param list<Cluster> $clusters
     */
    public function save(array $blocks, array $clusters): void
    {
        // once-S64: the gate is isEnabled() — the former blind
        // `!is_dir → mkdir` arm here was the write-through hole a
        // symlinked cacheDir hides behind (is_dir() follows links),
        // and dir creation now belongs to the constructor's
        // enforcement exactly as in IndexStore.
        if (!$this->isEnabled()) return;
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

        // shell-S19 (finding shell-031): LOCK_EX — concurrent saves must
        // never interleave. Torn bytes between the length prefix and the
        // payload defeat even the framing codec's miss posture (a partly
        // overwritten longer snapshot can frame-decode into a shorter
        // corrupt one); the exclusive lock closes the window at the
        // writer instead of hoping the reader notices.
        $target = $this->cacheDir . '/clusters.idx';
        if (@file_put_contents($target, $bytes, LOCK_EX) === false) return;
        @chmod($target, 0o600);
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

    /**
     * The refusal template — same posture as the block store's: name
     * what is wrong, name the file, offer the remedy. A
     * \RuntimeException (code 0) so the CLI surfaces the refusal as a
     * runtime error with exit 1, not an exit-255 TypeError from deep
     * inside whichever consumer touched the corrupt slot first.
     */
    private static function corrupt(string $detail, string $file): \RuntimeException
    {
        return new \RuntimeException(sprintf(
            "phpdup: refusing corrupt cluster cache entry \"%s\": %s. "
            . "The snapshot contradicts what this build's cache writer produces — "
            . 'delete the cache directory to rebuild it.',
            $file,
            $detail,
        ));
    }
}
