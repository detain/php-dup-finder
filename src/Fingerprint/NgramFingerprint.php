<?php
declare(strict_types=1);

namespace Phpdup\Fingerprint;

use PhpParser\Node;
use Phpdup\Util\AstSerializer;
use Phpdup\Util\Delimiters;
use Phpdup\Util\IdentifierIds;

/**
 * Produces a multiset of canonical-token n-grams for a block.
 *
 * The multiset is what JaccardSimilarity compares. We use n=5 by
 * default — small enough to capture short patterns, large enough to
 * suppress trivial overlap. A bag-of-ngrams representation tolerates
 * minor reorderings (which exact-hash bucketing would not catch).
 *
 * Every bagged gram passes through the instance's master gram
 * dictionary (php-dup-finder-engine-011, engine-S18 half B): the
 * dictionary holds one canonical key string per distinct digest, so a
 * gram seen in a thousand blocks allocates one zend_string instead of
 * a thousand. Before this the dominant residency cost of a large
 * preprocess run was exactly that per-incidence string — the finding's
 * "every gram a separate 16-char hex zend_string" measurement — and
 * the bags (and everything keyed off them: the inverted index, the
 * serialized Blocks) now share the canonical object instead. Keys
 * carry identical content, insertion order and numeric-string
 * coercion as before, so bag bytes, candidate streams and persisted
 * blobs are unchanged; the int-keyed compact bag under --low-memory
 * keeps its masked-positive 63-bit keyspace (engine-042) untouched.
 */
final class NgramFingerprint
{
    public function __construct(
        private readonly int $n = 5,
        private readonly bool $lowMemory = false,
        // Item 1.1 (§5a): drop the `__V1`/`__CALL5`-style first-occurrence
        // counters from tokens before bagging. MUST be flipped in lockstep
        // with AptedDistance's label mode (shared IdentifierIds helper);
        // the mode also rides the PreprocessWorker toolFor key, the
        // IndexStore config key and the ClusterCache config key, so a
        // cross-mode cache misfeed is impossible. false = byte-identical
        // legacy behaviour.
        private readonly bool $unnumbered = false,
    ) {
        if ($n < 2) {
            throw new \InvalidArgumentException('n must be >= 2');
        }
    }

    /**
     * Master gram dictionary: digest key → the first-inserted canonical
     * key string. Bag insertion always runs through internGram(), so
     * every occurrence of a repeated gram shares this one zend_string
     * (engine-011 residency; see class docblock).
     *
     * @var array<string,string>
     */
    private array $gramDictionary = [];

    /**
     * Stable-id projection over the dictionary — the gram-side mirror
     * of engine-S18a's internId seam: one gram digest keeps the same
     * small non-negative int for the lifetime of this fingerprinter
     * (assigned lazily in first-interned order), and gramForKey()
     * decodes it back. Ids of grams never asked for are never minted.
     *
     * @var array<string,int>
     */
    private array $gramIds = [];

    /** @var list<string> gram id → canonical key string (decode table) */
    private array $gramOrder = [];

    /**
     * Intern a gram digest to the dictionary's canonical key string.
     * Content-equal keys collide on the same slot; the object stored
     * first is the one every later incidence reuses.
     */
    private function internGram(string $digest): string
    {
        return $this->gramDictionary[$digest] ??= $digest;
    }

    /**
     * Stable dictionary id for a gram key already bagged by this
     * instance, or null if the digest has never been interned here.
     * Same digest → same int for the instance's lifetime.
     */
    public function gramId(string $digest): ?int
    {
        $canonical = $this->gramDictionary[$digest] ?? null;
        if ($canonical === null) {
            return null; // never-minted — mirrors S18a's -1 sentinel idiom
        }
        $id = $this->gramIds[$canonical] ?? -1;
        if ($id === -1) {
            $id = count($this->gramOrder);
            $this->gramIds[$canonical] = $id;
            $this->gramOrder[]         = $canonical;
        }
        return $id;
    }

    /**
     * Canonical digest key behind a gramId(), or null for an id that
     * was never minted (out of range or negative).
     */
    public function gramForKey(int $id): ?string
    {
        return $this->gramOrder[$id] ?? null;
    }

    /** Number of distinct gram keys this fingerprinter has interned. */
    public function dictionarySize(): int
    {
        return count($this->gramDictionary);
    }

    /**
     * @return array<string|int,int> n-gram → count. Keys are xxh64 hex strings
     *                                  in normal mode — shared across bags via
     *                                  the master gram dictionary, but identical
     *                                  in content and order to the pre-interning
     *                                  build; the digest as a positive 63-bit
     *                                  int (sign bit cleared) when lowMemory
     *                                  is enabled via CompactNgramBag::compact().
     */
    public function fingerprint(Node $node): array
    {
        $tokens = AstSerializer::tokens($node);
        // Engine-S02a name stamps (`__CALL_send`) carry identifier
        // identity, not tier information: aggressive mode's similarity
        // contract has always been vocabulary-agnostic, so the fold
        // applies in BOTH numbering modes (the exact tier is the one
        // that went name-honest). Counter un-numbering below stays the
        // mode-gated item-1.1 knob.
        $tokens = array_map([IdentifierIds::class, 'foldStamps'], $tokens);
        if ($this->unnumbered) {
            // Coarsening the identifier alphabet can only merge gram keys
            // (multiset Jaccard is monotone under token identification),
            // which is the whole point of item 1.1. Numbered mode never
            // touches the array → byte-identical grams.
            $tokens = array_map([IdentifierIds::class, 'strip'], $tokens);
        }
        $count = count($tokens);
        if ($count < $this->n) {
            // pad with sentinels so very short blocks still produce ngrams
            $tokens = array_pad($tokens, $this->n, '<EOS>');
            $count = $this->n;
        }
        // Bulk-build ngrams with implode() — about 2× faster than the
        // previous element-wise concatenation loop on large blocks
        // because PHP can vectorise the joiner. The hash itself
        // dominates anyway; we pay one fewer concat per ngram.
        $bag   = [];
        $sep   = Delimiters::NGRAM_SEP;
        $upper = $count - $this->n;
        for ($i = 0; $i <= $upper; $i++) {
            $key = hash(
                'xxh64',
                implode($sep, array_slice($tokens, $i, $this->n)),
            );
            // Intern through the master gram dictionary: the fresh hash()
            // output above dies after the lookup, and the bag slot points
            // at the dictionary's canonical string instead of keeping its
            // own copy (engine-011). Same key content → same bucket, same
            // order, same persisted bytes as the pre-dictionary build.
            $canonical   = $this->internGram($key);
            $bag[$canonical] = ($bag[$canonical] ?? 0) + 1;
        }

        if ($this->lowMemory) {
            // Switch to compact int-keyed bag: the full xxh64 digest stored
            // as a positive 63-bit int (sign bit cleared). Birthday math on
            // 2^63 keys crosses ~1% only around ~4.3e8 distinct grams in one
            // corpus — negligible in practice. The pre-fix 32-bit hi^lo XOR
            // fold crossed ~1% near ~9k distinct grams and merged every
            // equal-XOR pair, silently inflating bag overlap corpus-wide
            // (php-dup-finder-engine-042). The int keys use ~8 bytes vs
            // 16+ bytes for hex strings, significantly reducing RSS on
            // large codebases.
            return CompactNgramBag::compact($bag);
        }

        return $bag;
    }
}
