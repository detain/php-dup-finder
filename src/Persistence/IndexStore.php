<?php
declare(strict_types=1);

namespace Phpdup\Persistence;

use Phpdup\Extraction\Block;
use Phpdup\Parsing\CacheBlob;

/**
 * Disk-backed snapshot of the per-file block list, used for
 * incremental indexing: on a re-run we re-process only files whose
 * content hash has changed (added, modified, or removed).
 *
 * Layout (shell-S18: header-before-payload)
 * ------
 * One file under `<cacheDir>/<sha1(absolute_file_path)>.idx`:
 *
 *     PHPDUP-IDX1
 *     file_hash:<sha1(file_contents)>
 *     parser_version:<PARSER_VERSION>
 *     config_key:<sha1(serialize(relevant_config_fields))>
 *     <blank line>
 *     <byte-length>:<serialize(['blocks' => Block[]])>
 *
 * The plain-text meta prefix makes a MISS cheap: load() validates
 * parser_version, config_key and file_hash from a bounded header read
 * and returns before a single byte of the payload is read, so a stale
 * build, a config flip, or a changed file never pays the unserialize
 * cost of the block list it is about to discard. The trailing section
 * is length-framed with the shared {@see CacheBlob} codec, so a
 * truncated or appended-to file is a framing miss, not a corrupted
 * decode. Unknown header keys are skipped, letting later steps in the
 * chain add metadata without breaking this reader.
 *
 * Pre-S18 blobs — one raw serialize() of the whole meta+blocks array,
 * with no magic line — miss cleanly through the same header check:
 * existing `.phpdup-cache` trees self-invalidate once (no crash, no
 * stale serve) and the next save() rewrites them in the framed format.
 *
 * Snapshots are independent per source file, so adding one new file or
 * editing one existing file invalidates only that file's snapshot — the
 * rest replay verbatim.
 *
 * Deserialization is restricted to the phpdup value-object classes
 * (Block / LineRange / Cluster / Hole) plus the PhpParser node
 * namespace via {@see SerializedClassAllowList::blockCacheClasses()}.
 * Any other class lands as `__PHP_Incomplete_Class`; the shell-S19
 * shape guard (finding shell-031) then walks the decoded graph
 * recursively — every header gate has passed, so this build's writer
 * produced the file, and a smuggled class or a non-Block element is a
 * provable contract violation: the entry is REFUSED with a
 * `phpdup: refusing corrupt ...` runtime error (a clean exit 1 at the
 * CLI, never a downstream exit-255 TypeError). A graph too large to
 * walk inside the bounded budget is the unprovable case: ordinary miss
 * semantics, nothing served unchecked.
 */
final class IndexStore
{
    /** First line of a framed-header cache file — the format-generation marker. */
    private const MAGIC = 'PHPDUP-IDX1';

    /**
     * Bound of the header read. A header this writer emits is ~110 bytes;
     * anything whose header does not terminate inside this window is
     * treated as malformed (miss) instead of being slurped whole.
     */
    private const HEADER_READ_BYTES = 4096;

    /**
     * Edge budget for the shell-S19 shape walk. Measured against this
     * repo's own warm caches the honest worst case sits two-to-three
     * orders of magnitude below it (the largest per-file snapshot here
     * is ~24k graph nodes ≈ 9 ms; the 8 MB whole-corpus cluster graph
     * ~127k nodes ≈ 65 ms), so legitimate blobs never brush the cap
     * while a reference-cycle poison is still caught in milliseconds.
     * (Value aliased to the canonical home since shell-S11 — the walk
     * moved to SerializedClassAllowList; the budget number is single.)
     */
    private const SHAPE_SCAN_EDGES = SerializedClassAllowList::SHAPE_SCAN_EDGES;

    /**
     * Returned by {@see self::findIncompleteClass()} when the walk ran
     * out of edge budget before it covered the graph — NOT a clean
     * verdict. Both stores translate it into an ordinary cache miss
     * (recompute, never serve unchecked data) instead of refusing a
     * blob that may be perfectly honest and merely enormous.
     *
     * shell-S11: the sentinel — and the walk producing it — are canonically
     * homed in {@see SerializedClassAllowList::SCAN_UNRESOLVED}; this public
     * alias keeps every shell-S19 comparison site and pin on the same
     * string value, spelled from one place.
     */
    public const SCAN_UNRESOLVED = SerializedClassAllowList::SCAN_UNRESOLVED;

    // 'floatbytes': AstSerializer renders float literals as IEEE-754
    // bytes instead of the ini-dependent decimal cast, moving every
    // structuralHash that covers a float literal. Snapshots written by
    // the previous serializer must not serve stale hashes.
    // 'namesentinel' (engine-S02a): Normalizer's aggressive name pass
    // moved from first-occurrence ordinals (__CALL0) to name stamps
    // (__CALL_send) and the Param double-rename re-dealt variable
    // slots — every canonical structuralHash shifts. Snapshots written
    // by the ordinal writer must not serve stale exact-bucket hashes
    // next to live ones.
    // shell-S18 deliberately does NOT re-bump this constant: the format
    // magic line already refuses every pre-S18 blob structurally, and a
    // second invalidation on top of the first is pure cache churn.
    private const PARSER_VERSION = 'parser-v6-namesentinel';

    /**
     * Source-hash memo: path => sha1 computed during this store's
     * lifetime. load() records the hash it compared; save() reuses it,
     * so a lookup-then-process-then-persist cycle hashes a source file
     * at most once (shell-009: save() used to sha1_file the source a
     * second time right after load() had done it). A stale memo entry
     * is harmless: the header it writes simply mismatches the next
     * run's fresh hash and misses.
     *
     * @var array<string,string>
     */
    private array $hashMemo = [];

    public function __construct(
        private readonly string $cacheDir,
        private readonly string $configKey,
    ) {
        if ($this->cacheDir !== '' && !is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0o700, true);
        }
    }

    public function isEnabled(): bool
    {
        return $this->cacheDir !== '' && is_dir($this->cacheDir);
    }

    /**
     * @param string|null $fileHash sha1 of the source's current contents
     *                              when the caller already holds one —
     *                              load() then skips its own sha1_file.
     * @return list<Block>|null  null on cache miss
     */
    public function load(string $filePath, ?string $fileHash = null): ?array
    {
        if (!$this->isEnabled() || !is_file($filePath)) return null;

        $cacheFile = $this->cacheFor($filePath);
        if (!is_file($cacheFile)) return null;

        // Bounded header read — the payload is only ever opened after
        // every gate below has passed.
        $prefix = @file_get_contents($cacheFile, false, null, 0, self::HEADER_READ_BYTES);
        if (!is_string($prefix)) return null;

        $header = self::parseHeader($prefix);
        if ($header === null) return null;   // pre-S18 raw blob, malformed, or foreign file
        [$meta, $payloadStart] = $header;

        // Whole-run staleness first: these two misses neither hash the
        // source nor touch the payload.
        if (($meta['parser_version'] ?? '') !== self::PARSER_VERSION) return null;
        if (($meta['config_key'] ?? '') !== $this->configKey) return null;

        $expected = $meta['file_hash'] ?? '';
        if (!self::isHex40($expected)) return null;   // header cannot name a real snapshot

        if ($fileHash !== null && self::isHex40($fileHash)) {
            $live = $fileHash;
        } else {
            $live = sha1_file($filePath);
            if (!is_string($live)) return null;
        }
        $this->hashMemo[$filePath] = $live;
        if ($live !== $expected) return null;

        // All metadata gates passed; read and decode the payload.
        $framed = @file_get_contents($cacheFile, false, null, $payloadStart);
        if (!is_string($framed)) return null;
        $blob = CacheBlob::unframe($framed);
        if ($blob === null) return null;               // truncated or appended write → miss

        $payload = @unserialize($blob, [
            'allowed_classes' => SerializedClassAllowList::blockCacheClasses(),
        ]);
        if (!is_array($payload)) return null;

        // shell-S19 (finding shell-031): payload shape guards. Every
        // header gate has passed, so a payload violating the writer's
        // contract here is provably not this build's output — either
        // tampered or the product of a broken build. Serving it
        // corrupts the run; silently missing it launders a planted
        // poison past the very check that should have caught it. The
        // honest answer is loud: a \RuntimeException whose message
        // starts with `phpdup:` reaches the CLI as an exit-1 refusal
        // (pre-S19 the flat screen let both repro shapes slip through
        // and the pipeline died mid-serve with an exit-255 TypeError).
        $blocks = $payload['blocks'] ?? null;
        if ($blocks === null) {
            throw self::corrupt('the payload carries no "blocks" section', $cacheFile);
        }
        if (!is_array($blocks)) {
            throw self::corrupt(sprintf('the "blocks" section is %s, not a list', get_debug_type($blocks)), $cacheFile);
        }

        $poison = self::findIncompleteClass($payload);
        if ($poison === self::SCAN_UNRESOLVED) {
            // Not provably poison — fall back to miss semantics. An
            // honest cache just pays a recompute; a poison of this
            // shape (over-budget graph) cannot be served either way.
            return null;
        }
        if ($poison !== null) {
            throw self::corrupt(
                sprintf('a "%s" object hides inside the decoded graph — no writer of this format ever serializes it', $poison),
                $cacheFile,
            );
        }

        foreach ($blocks as $position => $block) {
            if (!$block instanceof Block) {
                throw self::corrupt(
                    sprintf('block #%d is %s, not a Block snapshot', $position, get_debug_type($block)),
                    $cacheFile,
                );
            }
        }

        return array_values($blocks);
    }

    /**
     * Persist a snapshot.
     *
     * @param list<Block> $blocks
     * @param string|null $fileHash sha1 of the source's current contents
     *                              when the caller already holds one (a
     *                              load() on this same store instance also
     *                              memoizes its hash for reuse here). Only
     *                              a save() with neither a caller hash nor
     *                              a memo hash performs a sha1_file — one
     *                              hash per file per run at the latest
     *                              (shell-009).
     */
    public function save(string $filePath, array $blocks, ?string $fileHash = null): void
    {
        if (!$this->isEnabled() || !is_file($filePath)) return;

        if ($fileHash !== null && self::isHex40($fileHash)) {
            $sha = $fileHash;
        } else {
            $sha = $this->hashMemo[$filePath] ?? sha1_file($filePath);
            if (!is_string($sha)) return;
        }
        $this->hashMemo[$filePath] = $sha;

        $cacheFile = $this->cacheFor($filePath);
        try {
            $blob = serialize(['blocks' => $blocks]);
        } catch (\Throwable) {
            return;   // unserializable shape → cache nothing, re-process next run
        }

        $bytes = self::buildHeader([
            'file_hash'      => $sha,
            'parser_version' => self::PARSER_VERSION,
            'config_key'     => $this->configKey,
        ]) . CacheBlob::frame($blob);

        if (@file_put_contents($cacheFile, $bytes, LOCK_EX) === false) return;
        @chmod($cacheFile, 0o600);
    }

    private function cacheFor(string $filePath): string
    {
        return $this->cacheDir . '/' . sha1($filePath) . '.idx';
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

    private static function isHex40(string $candidate): bool
    {
        return preg_match('/\A[0-9a-f]{40}\z/', $candidate) === 1;
    }

    /**
     * Bounded, cycle-safe walk of a decoded cache graph looking for the
     * one shape an honest payload cannot contain: a
     * `__PHP_Incomplete_Class` smuggled in at ANY depth by a class
     * outside the allow-list (finding shell-031 repro B hid a stdClass
     * inside a Cluster's `holes` slot — deeper than the flat pre-S19
     * screens could see).
     *
     * The walk itself is homed in {@see
     * SerializedClassAllowList::findIncompleteClass()} since shell-S11
     * (the plan names the allow-list class as the single shared
     * blocked-class screen; shell-S19 grew it here first, and S11's
     * hoist moved the body). This entry is kept so both framed
     * persistence classes — ClusterCache included — and every shell-S19
     * pin call the ONE implementation through one stable name with one
     * stable budget default.
     *
     * @param int $edgeBudget traversal cap; the public signature lets
     *                        the shape tests inject a small budget and
     *                        exercise the unresolved arm in
     *                        milliseconds. Production readers always
     *                        use the default.
     * @return null|string  null when the whole graph walked clean; the
     *                      smuggled class name on the first poison hit;
     *                      the SCAN_UNRESOLVED sentinel on budget-out.
     */
    public static function findIncompleteClass(mixed $root, int $edgeBudget = self::SHAPE_SCAN_EDGES): ?string
    {
        return SerializedClassAllowList::findIncompleteClass($root, $edgeBudget);
    }

    /**
     * The refusal template: name what is wrong, name the file, offer
     * the remedy. A \RuntimeException (code 0) so the CLI surfaces it
     * as a runtime error with exit 1 — the finding's whole complaint
     * was the opaque exit-255 TypeError this replaces.
     */
    private static function corrupt(string $detail, string $file): \RuntimeException
    {
        return new \RuntimeException(sprintf(
            "phpdup: refusing corrupt block-index cache entry \"%s\": %s. "
            . "The snapshot contradicts what this build's cache writer produces — "
            . 'delete the cache directory or rerun with --no-cache to rebuild it.',
            $file,
            $detail,
        ));
    }
}
