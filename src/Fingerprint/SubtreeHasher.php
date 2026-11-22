<?php
declare(strict_types=1);

namespace Phpdup\Fingerprint;

use PhpParser\Node;
use Phpdup\Util\AstSerializer;
use Phpdup\Util\Delimiters;
use Phpdup\Util\Hash;

/**
 * Computes a stable structural hash over a canonicalized AST.
 *
 * Two blocks share a structural hash iff their canonical token streams
 * are byte-identical. Combined with aggressive normalization that
 * means: same node shape, same literals (modulo placeholders), same
 * variable scoping pattern, same call/property scheme.
 *
 * **Single canonical walk per block (php-dup-finder-engine-034,
 * engine-S33).** Preprocess consumes the same canonical tree twice in a
 * row — `SubtreeHasher::hash()` joins the stream under TOKEN_JOIN,
 * `NgramFingerprint::fingerprint()` windows it under NGRAM_SEP — and the
 * recursive `AstSerializer::tokens()` walk behind both joins was being
 * paid twice per block. `hash()` now parks the stream it minted in the
 * one-slot handoff below, and `NgramFingerprint::fingerprint()` takes it
 * instead of walking again, so the pair costs one walk. The handoff is
 * value-neutral by construction: what it hands over is the exact byte
 * stream the consumer would have built itself, it is keyed by object
 * identity (never by content), and it drains on the take. Every digest
 * this class emits is unchanged — the differential pins against the
 * pre-handoff replica oracle in
 * {@see \Phpdup\Tests\Unit\Fingerprint\SubtreeHasherTest.php} and the
 * byte-frozen gram-key pins in
 * {@see \Phpdup\Tests\Unit\Fingerprint\NgramFingerprintTest.php} fence
 * that (the cache-identity roster {@see FingerprintIdMode} therefore
 * does NOT move on this leg).
 */
final class SubtreeHasher
{
    /**
     * The one-slot canonical-stream handoff: the node `hash()` last
     * walked, weakly held, and the token stream that walk minted.
     *
     * Bounded by construction — one entry, replaced on every hash() and
     * cleared on every successful take() — so it cannot grow with the
     * corpus the way the gram dictionary's residency demanded care
     * (engine-011). The node is held weakly: a stream parked beside an
     * AST the pipeline has already released is dead weight and a dead
     * key, so {@see takeCanonicalStream()} drops it instead of keeping
     * either alive past its owner.
     *
     * Immutability law this rides: `Block::$canonical` is written only
     * by `Normalizer::normalize()` (src/Normalization/Normalizer.php),
     * which mints a fresh deep-cloned tree per pass — nothing mutates a
     * canonical node after it has been handed to a fingerprinter, and a
     * re-normalized block is a different object, so it misses the
     * handoff and walks again. Identity keying + drain-on-take + that
     * law together make a stale serve impossible on every path in the
     * tree; SubtreeHasherTest pins the drain and the miss.
     *
     * @var array{0: \WeakReference<Node>, 1: list<string>}|null
     */
    private static ?array $canonicalHandoff = null;

    public function hash(Node $node): string
    {
        // Always a fresh walk: the structural hash is the strongest
        // identity in the stores (exact buckets, cache keys, goldens),
        // so it must never ride a stream that could have gone stale —
        // hash() MINTS, the gram side CONSUMES what this minted.
        $tokens = AstSerializer::tokens($node);
        self::$canonicalHandoff = [\WeakReference::create($node), $tokens];

        return Hash::of(implode(Delimiters::TOKEN_JOIN, $tokens));
    }

    /**
     * Take the token stream `hash()` parked for exactly this node,
     * draining the slot. Null on a miss — nothing parked, parked for a
     * different node, or the parked node is already gone — and a miss
     * leaves a still-live park in place, because the consumer it was
     * minted for may be the very next call.
     *
     * @return list<string>|null
     */
    public static function takeCanonicalStream(Node $node): ?array
    {
        $slot = self::$canonicalHandoff;
        if ($slot === null) {
            return null;
        }
        $owner = $slot[0]->get();
        if ($owner === null) {
            self::$canonicalHandoff = null;
            return null;
        }
        if ($owner !== $node) {
            return null;
        }
        self::$canonicalHandoff = null;

        return $slot[1];
    }

    /**
     * Is a live stream standing parked right now? Observability seam for
     * the single-walk law: the preprocess order (normalize → hash →
     * fingerprint) must leave the slot empty after the pair and full
     * between the two calls. Behavior, not text: the tests read the
     * handoff through this instead of asserting on the implementation.
     */
    public static function hasCanonicalStream(): bool
    {
        return self::$canonicalHandoff !== null
            && self::$canonicalHandoff[0]->get() !== null;
    }
}
