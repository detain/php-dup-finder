<?php
declare(strict_types=1);

namespace Phpdup\Refactor;

use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;

/**
 * Item 1.3 — the 'script' kind apply-refusal (typed, loud, never silent).
 *
 * A 'script' block is a SYNTHETIC fold: its AST is a Stmt\Block wrapper we
 * minted around a run of top-level statements, not a node the parser put
 * into the file's tree. Consequence, codified as law:
 *
 *   - BlockAstLoader canNOT relocate a synthetic block — there is no
 *     parse-tree position that corresponds to the wrapper, so the P4
 *     relocation machinery would be reaching for a node that never
 *     existed (silent-mis-anchor class: forbidden);
 *   - therefore --apply / RefactorPatchReporter extraction + member call
 *     site rewriting CANNOT address script clusters in v1: there are no
 *     call sites, and the fold boundaries are not source-contiguous.
 *
 * Every such surface refuses with this exception, naming the kind and the
 * limitation. Under the exit contract (docs/CLI.md), a runtime refusal is
 * exit 1 — Command::dispatch() catches it by type and prints the friendly
 * `phpdup:` line, mirroring the CandidateBudgetException precedent.
 */
final class ScriptKindApplyRefusal extends \RuntimeException
{
    private const CORE =
        "'script'-kind blocks are synthetic top-level folds (item 1.3): they have no parse-tree "
        . 'position a relocation pass could anchor to and no member call sites to rewrite, so '
        . 'AST-addressing operations on them are refused in v1';

    /** The apply/dry-run patch surfaces: refuse per cluster before any work happens. */
    public static function forCluster(Cluster $cluster, string $surface): self
    {
        $count = 0;
        foreach ($cluster->members as $member) {
            if ($member->kind === 'script') {
                ++$count;
            }
        }
        return new self(sprintf(
            '%s: %s refused cluster %s, which carries %d script-kind member(s).',
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
            '%s: %s was asked to load the script block %s in %s (%s). '
            . 'Run with lazy_ast=false (or --no-lazy-ast) so script blocks keep their extracted AST instead of being relocated.',
            self::CORE,
            $surface,
            $block->qualifiedName(),
            $block->file,
            (string) $block->range,
        ));
    }
}
