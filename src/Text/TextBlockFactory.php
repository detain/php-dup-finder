<?php
declare(strict_types=1);

namespace Phpdup\Text;

use PhpParser\Node;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;
use Phpdup\Fingerprint\NgramFingerprint;
use Phpdup\Fingerprint\SubtreeHasher;
use Phpdup\Util\LineRange;

/**
 * Item 7.1: turns a harvested run into a kind-'text' Block.
 *
 * The fold carrier is the T1a-proven raw-text identity channel: a
 * synthetic {@see Node\Stmt\InlineHTML} whose value is the run's blanked
 * tokens joined by single spaces. AstSerializer rides free text verbatim
 * into the frame (stringifyScalar), so the fold is deterministic, carries
 * no original whitespace, and gives every machine its expected behavior
 * for free:
 *
 *  - SubtreeHasher folds equal blanked streams (modulo original layout —
 *    type-2 by construction) to one structuralHash, so true twins fuse in
 *    the Clusterer's phase-1 exact buckets WITHOUT any tier-specific
 *    clustering leg (one-formula law; the Clusterer never learns this
 *    exists);
 *  - NgramFingerprint::fingerprintTokens() bags the run through the very
 *    same roll-window + master-dictionary path the AST bags use — the
 *    near-duplicate tier sees the same alphabet;
 *  - the fold string is the blanked stream, so raw file bytes (names,
 *    literals) NEVER enter the report payload by this door.
 *
 * $size is the token count — the tier's honest stand-in for node count
 * (the doc's 'size = token count proxy'); impact stays line-based via
 * $range (E3 machinery, no Ranker change needed or allowed).
 */
final class TextBlockFactory
{
    public function __construct(
        private readonly NgramFingerprint $fingerprinter,
        private readonly SubtreeHasher $hasher,
    ) {
    }

    public function fromRun(TextRun $run): Block
    {
        $blanked = $run->blankedTokens();
        $fold    = implode(' ', $blanked);
        $node    = new Node\Stmt\InlineHTML($fold);

        $block = new Block(
            file: $run->file(),
            range: new LineRange($run->startLine, $run->endLine),
            kind: BlockExtractor::TEXT_KIND,
            namespace: null,
            class: null,
            name: basename($run->file()),
            ast: $node,
        );
        $block->canonical      = $node;
        $block->structuralHash = $this->hasher->hash($node);
        $block->ngramBag       = $this->fingerprinter->fingerprintTokens($blanked);
        $block->size           = count($blanked);

        return $block;
    }
}
