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
     * @return array<string|int,int> n-gram → count. Keys are xxh64 hex strings
     *                                  in normal mode; the digest as a positive
     *                                  63-bit int (sign bit cleared) when lowMemory
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
            $bag[$key] = ($bag[$key] ?? 0) + 1;
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
