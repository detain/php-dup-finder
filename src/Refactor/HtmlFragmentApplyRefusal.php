<?php
declare(strict_types=1);

namespace Phpdup\Refactor;

use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;

/**
 * Item 7.2b — the 'html_script' kind apply-refusal (typed, loud, never
 * silent), the template-window sibling of {@see ScriptKindApplyRefusal},
 * {@see WindowApplyRefusal} and {@see FragmentApplyRefusal}.
 *
 * An 'html_script' block is a MIXED-LANGUAGE fold: the content of an
 * inline <script> window of a template, carried as a full fragment
 * sequence — raw-HTML runs as position-marked sentinel members, real PHP
 * statements as real Nodes (the ?&gt;/&lt;?php spanning that makes this
 * species the design's crux). That mix is exactly why apply is refused
 * in v1:
 *
 *   - an extraction would have to move raw markup together with PHP
 *     statements into a function body whose language is neither — the
 *     anti-unifier's holes have no honest PHP type for a markup run, and
 *     call-site rewriting has no AST anchor in HTML;
 *   - detection needs none of that: extraction, clustering, signatures,
 *     holes and reporting all work on the fold (the 1.3/1.2 posture:
 *     the signature is the evidence, the refusal guards AST-addressing
 *     actions);
 *   - unlike the three synthetic kinds above, the loader CAN serve an
 *     html_script fold WITHOUT refusing: Block::$fragmentLineage makes a
 *     positional re-derivation from a fresh parse safe
 *     ({@see BlockAstLoader}'s html_script arm). This typed exception is
 *     therefore the rare path — it fires when bytes moved under a warm
 *     replay and the (range, lineage) window no longer exists, i.e. the
 *     honest answer to "where was that fold?" when the template changed.
 *
 * Under the exit contract (docs/CLI.md), a runtime refusal is exit 1 —
 * Command::dispatch() catches it by type and prints the `phpdup:` line.
 */
final class HtmlFragmentApplyRefusal extends \RuntimeException
{
    private const CORE =
        "'html_script'-kind blocks are mixed-language folds of template <script> windows (item 7.2b): raw-markup "
        . 'sentinel members and real PHP statements share one wrapper, so apply/patch — which must hand back '
        . 'extractable PHP — is refused in v1';

    /** The apply/dry-run patch surfaces: refuse per cluster before any work happens. */
    public static function forCluster(Cluster $cluster, string $surface): self
    {
        $count = 0;
        foreach ($cluster->members as $member) {
            if ($member->kind === BlockExtractor::HTML_SCRIPT_KIND) {
                ++$count;
            }
        }
        return new self(sprintf(
            '%s: %s refused cluster %s, which carries %d html_script-kind member(s).',
            self::CORE,
            $surface,
            $cluster->id,
            $count,
        ));
    }

    /** The BlockAstLoader relocation surface: fire only when re-derivation lost the fold. */
    public static function forBlock(Block $block, string $surface): self
    {
        return new self(sprintf(
            '%s: %s was asked to load the html_script block %s in %s (%s) and the window scanner could not '
            . 're-derive that (position, lineage) fold from the CURRENT file bytes — the template changed under '
            . 'a warm cache. Folds are re-derived from raw text, never guessed; re-scan the file to refresh. '
            . 'Name --kinds html_script to mint them, and read their clusters as evidence.',
            self::CORE,
            $surface,
            $block->qualifiedName(),
            $block->file,
            (string) $block->range,
        ));
    }
}
