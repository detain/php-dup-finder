<?php
declare(strict_types=1);

namespace Phpdup\Ir;

/**
 * IR-to-IR similarity scorer (option 5 of
 * `docs/plans/orm-db-semantic-dedup.md`).
 *
 * Two scoring modes:
 *
 *   1. **Hash equality** — when both IR trees produce byte-identical
 *      token streams the score is 1.0. This is the strongest
 *      signal and the cheap fast-path.
 *   2. **Token-Jaccard** — multiset Jaccard over the printed token
 *      lists. Captures partial overlap (e.g. one block has an
 *      extra read that the other doesn't) without paying for a
 *      full tree-edit-distance computation.
 *
 * The plan calls for the eventual scorer to live alongside the
 * existing AST-level scoring tiers. This implementation provides
 * the building blocks; the wiring into the clusterer (as a fifth
 * tier behind `--scorer=ir`) is delivered separately.
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
     * Stable structural hash of an IR tree — equal hashes imply
     * identical printed-token streams (the inverse holds modulo
     * sha1 collisions, which is acceptable for clustering).
     */
    public function hash(IrNode $ir): string
    {
        return sha1(implode("\x1E", $this->printer->tokens($ir)));
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
