<?php
declare(strict_types=1);

namespace Phpdup\Ir;

use Phpdup\Util\Delimiters;
use Phpdup\Util\Hash;

/**
 * IR-to-IR similarity scorer — the "option 5" of the ORM/DB-aware
 * semantic-dedup plan (the plan file itself was deleted repo-wide;
 * citation cleanup belongs to the once-099 lane, so this docblock
 * no longer cites the dead path).
 *
 * Two scoring modes:
 *
 *   1. **Hash equality** — when both IR trees produce byte-identical
 *      token streams the score is 1.0. This is the strongest
 *      signal and the cheap fast-path *of this scorer*.
 *   2. **Token-Jaccard** — multiset Jaccard over the printed token
 *      lists. Captures partial overlap (e.g. one block has an
 *      extra read that the other doesn't) without paying for a
 *      full tree-edit-distance computation.
 *
 * NOT WIRED (engine-028 catalog row 12, disposition KEEP, executed
 * by engine-S31b): the live IR tier behind `--scorer=ir` does NOT
 * use this class — Clusterer's tier falls back to multiset Jaccard
 * over the pre-computed `Block::$irBag` token bags via
 * JaccardSimilarity directly. This scorer remains the object-model
 * variant (it walks IrNode trees through IrPrinter); whether the
 * live tier should be re-aimed at it — or it deleted in favour of
 * the bag path — is a product call held by the ledger.
 */
final class IrSimilarity
{
    public function __construct(
        private readonly IrPrinter $printer = new IrPrinter(),
    ) {
    }

    /**
     * @return float in [0,1]
     */
    public function similarity(IrNode $a, IrNode $b): float
    {
        $ta = $this->printer->tokens($a);
        $tb = $this->printer->tokens($b);
        if ($ta === [] && $tb === []) {
            return 1.0;
        }
        if ($ta === $tb) {
            return 1.0;
        }
        return $this->jaccardMultiset($ta, $tb);
    }

    /**
     * Stable structural id of an IR tree — equal hashes imply
     * identical printed-token streams (the inverse holds modulo
     * xxh128 collisions, which is acceptable for clustering).
     *
     * engine-046: ids join the `Util\Hash` (xxh128) + TOKEN_JOIN
     * digest family that SubtreeHasher uses for structural ids.
     * The legacy `sha1 + NGRAM_SEP` form sat in a different digest
     * family than every other id, and rode on the n-gram bag
     * policy's separator — an NGRAM_SEP change intended for grams
     * would have silently re-minted IR ids. The transition sample
     * is pinned in tests/Unit/Ir/IrSimilarityTest.php.
     */
    public function hash(IrNode $ir): string
    {
        return Hash::of(implode(Delimiters::TOKEN_JOIN, $this->printer->tokens($ir)));
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private function jaccardMultiset(array $a, array $b): float
    {
        $countsA = array_count_values($a);
        $countsB = array_count_values($b);
        $i = 0;
        $u = 0;
        foreach (array_unique(array_merge(array_keys($countsA), array_keys($countsB))) as $k) {
            $ca = $countsA[$k] ?? 0;
            $cb = $countsB[$k] ?? 0;
            $i += min($ca, $cb);
            $u += max($ca, $cb);
        }
        return $u > 0 ? $i / $u : 0.0;
    }
}
