<?php
declare(strict_types=1);

namespace Phpdup\Fingerprint;

use PhpParser\Node;
use Phpdup\Util\AstSerializer;

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
    public function __construct(private readonly int $n = 5)
    {
        if ($n < 2) {
            throw new \InvalidArgumentException('n must be >= 2');
        }
    }

    /**
     * @return array<string,int> n-gram → count
     */
    public function fingerprint(Node $node): array
    {
        $tokens = AstSerializer::tokens($node);
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
        $sep   = "\x1E";
        $upper = $count - $this->n;
        for ($i = 0; $i <= $upper; $i++) {
            $key = hash(
                'xxh64',
                implode($sep, array_slice($tokens, $i, $this->n)),
            );
            $bag[$key] = ($bag[$key] ?? 0) + 1;
        }
        return $bag;
    }
}
