<?php
declare(strict_types=1);

namespace Phpdup\Refactor;

use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;

/**
 * Item 1.2 — the 'window' kind apply-refusal (typed, loud, never silent),
 * the scan-window sibling of {@see ScriptKindApplyRefusal}.
 *
 * A 'window' block is a SYNTHETIC cut: a run of statements sliced out of
 * an oversized body and wrapped in a Stmt\Block we minted (WindowPacker).
 * Same law as the 1.3 folds, same consequences:
 *
 *   - BlockAstLoader canNOT relocate a window — no parse-tree position
 *     corresponds to the wrapper (silent-mis-anchor class: forbidden);
 *   - apply/patch extraction and member call-site rewriting therefore
 *     refuse window clusters in v1: the window boundary is not a source-
 *     contiguous refactorable unit, and a hole-free signature over a
 *     fragment cut mid-body is a description, not an extractable
 *     abstraction.
 *
 * Signature building is deliberately NOT refused — exactly the script
 * posture (item 1.3 detection-only): the signature is the evidence the
 * operator reads, the refusal guards the AST-addressing actions.
 *
 * Under the exit contract (docs/CLI.md), a runtime refusal is exit 1 —
 * Command::dispatch() catches it by type and prints the friendly
 * `phpdup:` line, mirroring the ScriptKindApplyRefusal precedent.
 */
final class WindowApplyRefusal extends \RuntimeException
{
    private const CORE =
        "'window'-kind blocks are synthetic statement cuts from oversized bodies (item 1.2): they have no "
        . 'parse-tree position a relocation pass could anchor to and their boundaries are not extractable '
        . 'units, so AST-addressing operations on them are refused in v1';

    /** The apply/dry-run patch surfaces: refuse per cluster before any work happens. */
    public static function forCluster(Cluster $cluster, string $surface): self
    {
        $count = 0;
        foreach ($cluster->members as $member) {
            if ($member->kind === BlockExtractor::WINDOW_KIND) {
                ++$count;
            }
        }
        return new self(sprintf(
            '%s: %s refused cluster %s, which carries %d window-kind member(s).',
            self::CORE,
            $surface,
            $cluster->id,
            $count,
        ));
    }

    /** The BlockAstLoader relocation surface: refuse per block, loudly. */
    public static function forBlock(Block $block, string $surface): self
    {
        return new self(sprintf(
            '%s: %s was asked to load the window block %s in %s (%s). '
            . 'Windows keep their extracted AST instead of being relocated; lower max_block_size (or raise '
            . 'it toward the cost ceiling you accept) to make the parent body itself comparable.',
            self::CORE,
            $surface,
            $block->qualifiedName(),
            $block->file,
            (string) $block->range,
        ));
    }
}
