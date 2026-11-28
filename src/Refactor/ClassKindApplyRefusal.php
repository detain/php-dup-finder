<?php
declare(strict_types=1);

namespace Phpdup\Refactor;

use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Extraction\BlockExtractor;

/**
 * Item 2.3 — the 'class' kind apply-refusal (typed, loud, never silent),
 * the class-shape sibling of {@see ScriptKindApplyRefusal},
 * {@see WindowApplyRefusal} and {@see FragmentApplyRefusal}.
 *
 * A 'class' block IS a real parse-tree declaration — unlike the synthetic
 * species this one's loader relocation works and its AST is unloadable
 * normally. What v1 refuses is EXTRACTION: the shape fingerprint is a
 * DETECTION device (declaration twins and skeleton families, the doc's
 * two output classes), and the refactor it implies is structural —
 * "pull the shared method up into a base" is a multi-file hierarchy
 * rewrite that ApplyExtractor's single-function model cannot express
 * (it extracts one function and rewrites member call sites; a class has
 * no call sites to redirect and the honest patch is a new parent type).
 *
 * Signature building is deliberately NOT refused — the 1.3/1.2 posture:
 * the class_shape table (JsonReporter) plus the per-member signature
 * rows are the base-class proposal itself; the refusal guards AST-
 * addressing actions, never evidence rendering.
 *
 * Under the exit contract (docs/CLI.md), a runtime refusal is exit 1 —
 * Command's typed-halt catch and WatchRunner::runGuarded print the
 * `phpdup:` line instead of a throwable dump.
 */
final class ClassKindApplyRefusal extends \RuntimeException
{
    private const CORE =
        "'class'-kind blocks are shape-fingerprinted declarations (item 2.3): their extraction is a structural "
        . 'hierarchy rewrite (pull-up / template-method), not the single-function extraction ApplyExtractor performs, '
        . 'so AST-addressing operations on class clusters are refused in v1';

    /** The apply/dry-run patch surfaces: refuse per cluster before any work happens. */
    public static function forCluster(Cluster $cluster, string $surface): self
    {
        $count = 0;
        foreach ($cluster->members as $member) {
            if ($member->kind === BlockExtractor::CLASS_KIND) {
                ++$count;
            }
        }
        return new self(sprintf(
            '%s: %s refused cluster %s, which carries %d class-kind member(s).',
            self::CORE,
            $surface,
            $cluster->id,
            $count,
        ));
    }

    /**
     * The block-addressing surface (mirrors the synthetic species' backstop shape).
     *
     * Intentionally uncalled: unlike script/window/fragment (synthetic spans the
     * loader must refuse), a class RELOCATES — BlockAstLoader resolves class
     * blocks and the loader-refuse arms do not apply. The anatomy is kept for
     * shape parity with the other refusal classes.
     */
    public static function forBlock(Block $block, string $surface): self
    {
        return new self(sprintf(
            '%s: %s was asked to operate on the class block %s in %s (%s). '
            . 'Class clusters are read as evidence — the class_shape table names the shared members and their '
            . 'verdicts; extraction is refused in v1.',
            self::CORE,
            $surface,
            $block->qualifiedName(),
            $block->file,
            (string) $block->range,
        ));
    }
}
