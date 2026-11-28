<?php
declare(strict_types=1);

namespace Phpdup\Refactor;

use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;

/**
 * Item 7.1 — the 'text' kind apply-refusal (typed, loud, never silent),
 * the token-tier sibling of {@see HtmlFragmentApplyRefusal},
 * {@see ScriptKindApplyRefusal}, {@see WindowApplyRefusal} and
 * {@see FragmentApplyRefusal}.
 *
 * A 'text'-kind block is a lexical fold of a NON-PHP file: its canonical
 * AST is a synthetic InlineHTML wrapper around a blanked token stream, so
 * there is no PHP in it to extract and no PHP call site to rewrite —
 * moving that content into a generated PHP function would manufacture
 * code that speaks neither language. Detection needs none of apply: the
 * run shows where a .js/.yaml/.tpl body was copied, and the fold string
 * is the evidence (the 7.2b posture, same shape).
 *
 * Unlike html_script there is also no loader leg: a text fold is NOT
 * re-derivable from a fresh PHP parse (the file is not PHP), so the
 * loader arm fires on ANY request to load one — the ast/canonical pair
 * is set at harvest time and is never legitimately unloaded.
 *
 * Under the exit contract (docs/CLI.md), a runtime refusal is exit 1 —
 * Command::dispatch() catches it by type and prints the `phpdup:` line.
 */
final class TextKindApplyRefusal extends \RuntimeException
{
    private const CORE =
        "'text'-kind blocks are lexical folds of non-PHP files (item 7.1): the token stream of a .js/.yaml/.tpl "
        . 'body is clone evidence, not PHP, so apply/patch — which must hand back extractable PHP — is refused in v1';

    /** The apply/dry-run patch surfaces: refuse per cluster before any work happens. */
    public static function forCluster(Cluster $cluster, string $surface): self
    {
        $count = 0;
        foreach ($cluster->members as $member) {
            if ($member->kind === BlockExtractor::TEXT_KIND) {
                ++$count;
            }
        }
        return new self(sprintf(
            '%s: %s refused cluster %s, which carries %d text-kind member(s).',
            self::CORE,
            $surface,
            $cluster->id,
            $count,
        ));
    }

    /** The BlockAstLoader surface: a text fold was never unloaded; loading one is a bug ask. */
    public static function forBlock(Block $block, string $surface): self
    {
        return new self(sprintf(
            '%s: %s was asked to load the text block %s in %s (%s). Text folds are minted complete by the token '
            . 'tier harvest and have no PHP parse to re-derive from; their ast/canonical pair is never legitimately '
            . 'unloaded. Name --kinds text to mint them, and read their clusters as evidence.',
            self::CORE,
            $surface,
            $block->qualifiedName(),
            $block->file,
            (string) $block->range,
        ));
    }
}
