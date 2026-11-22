<?php
declare(strict_types=1);

namespace Phpdup\Fingerprint;

/**
 * The cache-identity roster: one canonical token naming the current
 * fingerprint-stream generation, mirrored byte-exactly in the repository
 * root file {@see FingerprintIdMode::ROOT_TOKEN_FILE}.
 *
 * This is the bookkeeping home for engine-037 half B (engine-S34b): the
 * conditional length-framing of free-text values (engine-S34a a674d90,
 * '\x1D\x1E\x1F' + ';' era) widened to admit the NUL joiner (fix1
 * e18d52e, '\x00' era) changed the structural hashes of every
 * control-byte-bearing literal — so the cache identity of this stream
 * generation must move, and every persisted blob keyed by the previous
 * generation must stop being served. The stores that key on this era
 * (IndexStore / ClusterCache) carry their own prefixed version strings
 * and are bumped onto this roster by their lock-holding steps; this
 * class is the single line-of-succession they stamp toward.
 *
 * Why the bump is load-bearing even when the goldens show zero diff:
 * clean corpora are byte-frozen by construction (framing only rewrites
 * values that carry a danger byte), so a golden regen can legitimately
 * come back empty while adversarial-content entries changed identity
 * underneath. Measured on the fix1 tip (r2-corrected census, b5085ac ↔
 * e18d52e): 106 vendor entries / 81,433 lines move, src moves 8 block
 * digests outside AstSerializer itself (plus 35 inside it), fixtures
 * move 0/71, nikic moves 4 Lexer rows. No golden diff will ever reveal
 * those; the roster does.
 *
 * Line of succession (era words as spelled by the store comments):
 *   floatbytes  — floats left the ini-dependent decimal rendering for
 *                 IEEE-754 bytes (structuralHashes over floats moved).
 *   namesentinel — aggressive name pass went from first-occurrence
 *                 ordinals to name stamps; every canonical hash shifted
 *                 (IndexStore 'parser-v6-namesentinel').
 *   framing      — free-text values carrying the stream's own joiner
 *                 bytes ({@see AstSerializer::FRAME_MARK}, TOKEN_JOIN,
 *                 NGRAM_SEP, ';' in L: segments) became length-framed;
 *                 clean values byte-frozen (engine-S34a a674d90).
 *   framing-nul  — \x00 (the stmt-alignment HASH_JOIN byte) joined the
 *                 danger set; pre-fix1 blobs serve stale hashes for
 *                 every NUL-bearing literal (engine-S34a fix1 e18d52e).
 *
 * Not to be confused with the `fingerprint_id_mode` knob in
 * src/Cli/Config.php (values 'numbered' | 'un-numbered'): that selects
 * how identifier stamps are spelled inside one fingerprint, per run;
 * this roster names which fingerprint generation a persisted cache
 * belongs to, across runs. Phase-5 r1 corrected an earlier phantom
 * home (`config/CacheKey/`); the roster lives beside the fingerprint
 * classes it governs.
 *
 * @see \Phpdup\Util\AstSerializer::frame()
 * @see \Phpdup\Persistence\IndexStore
 * @see \Phpdup\Persistence\ClusterCache
 */
final class FingerprintIdMode
{
    /**
     * The canonical cache-identity token for the framing-nul generation.
     * Roster law: this literal and the root token file move together,
     * byte-exactly (pinned in
     * tests/Unit/Cli/FingerprintIdModeConfigTest.php); stores fold it
     * into their own prefixed version strings, never the other way.
     */
    public const CACHE_IDENTITY = 'cachekey-v1-framing-nul';

    /**
     * Repository-root file mirroring {@see CACHE_IDENTITY} as one line
     * plus a trailing newline. Its presence (or content) is not read by
     * any runtime path — it is the on-disk roster other tooling, CI
     * guards and future cache generations can point at without depending
     * on PHP loading this class.
     */
    public const ROOT_TOKEN_FILE = '.phpdup-cache-key.version';

    /**
     * Canonical bytes the root token file must contain: the identity
     * followed by a single "\n". Pure — callers compare, never write.
     */
    public static function rootTokenBytes(): string
    {
        return self::CACHE_IDENTITY . "\n";
    }
}
