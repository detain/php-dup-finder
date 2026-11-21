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
 * Any other class lands as `__PHP_Incomplete_Class` and forces a miss.
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

        // Tampered blob → bail with cache-miss semantics.
        // (Recursive member-shape guards land on top of this read path
        // in the shell-S19 chain step; this is the legacy flat screen.)
        $rawBlocks = $payload['blocks'] ?? [];
        if (is_array($rawBlocks)) {
            foreach ($rawBlocks as $block) {
                if ($block instanceof \__PHP_Incomplete_Class) {
                    return null;
                }
            }
        }

        $blocks = $payload['blocks'] ?? null;
        return is_array($blocks) ? array_values($blocks) : null;
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
}
