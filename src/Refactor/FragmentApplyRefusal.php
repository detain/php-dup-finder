<?php
declare(strict_types=1);

namespace Phpdup\Refactor;

use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;

/**
 * Item 2.1 — the 'fragment' kind apply-refusal (typed, loud, never silent),
 * the statement-run sibling of {@see ScriptKindApplyRefusal} and
 * {@see WindowApplyRefusal}.
 *
 * A 'fragment' block is a SYNTHETIC cut: a contiguous run of statements
 * from a shared-miner statement list (function/method/closure body or a
 * top-level fold), wrapped in a Stmt\Block we minted. It may even span
 * declaration boundaries once stitched, and:
 *
 *   - BlockAstLoader canNOT relocate a fragment — no parse-tree position
 *     corresponds to the wrapper (the same silent-mis-anchor class the
 *     script/window refusals guard);
 *   - apply/patch extraction and member call-site rewriting therefore
 *     refuse fragment clusters in v1: a statement-run cut is a detection
 *     device for cross-boundary clones, not an extractable abstraction.
 *
 * Signature building is deliberately NOT refused — the 1.3/1.2 posture:
 * the signature is the evidence, the refusal guards AST-addressing
 * actions.
 *
 * Under the exit contract (docs/CLI.md), a runtime refusal is exit 1 —
 * Command::dispatch() catches it by type and prints the `phpdup:` line.
 */
final class FragmentApplyRefusal extends \RuntimeException
{
    private const CORE =
        "'fragment'-kind blocks are synthetic statement runs from the shared miner (item 2.1): they have no "
        . 'parse-tree position a relocation pass could anchor to and their boundaries are not extractable '
        . 'units, so AST-addressing operations on them are refused in v1';

    /** The apply/dry-run patch surfaces: refuse per cluster before any work happens. */
    public static function forCluster(Cluster $cluster, string $surface): self
    {
        $count = 0;
        foreach ($cluster->members as $member) {
            if ($member->kind === BlockExtractor::FRAGMENT_KIND) {
                ++$count;
            }
        }
        return new self(sprintf(
            '%s: %s refused cluster %s, which carries %d fragment-kind member(s).',
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
            '%s: %s was asked to load the fragment block %s in %s (%s). '
            . 'Fragments keep their extracted AST instead of being relocated; name --kinds fragment to mint '
            . 'them, and read their clusters as evidence — extraction is refused in v1.',
            self::CORE,
            $surface,
            $block->qualifiedName(),
            $block->file,
            (string) $block->range,
        ));
    }
}
