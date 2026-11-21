<?php
declare(strict_types=1);

namespace Phpdup\Scanning;

use Phpdup\Extraction\Block;

/**
 * Item 5.1 — run-scoped repo/scope labels for scanned blocks.
 *
 * Multi-repo output used to double-penalize cross-repo clones because
 * nothing in the pipeline knew which repository a block came from. The
 * minimal honest fact is the scan root: ScanningStage already walks
 * one or more configured roots, and every Block::$file is formed as
 * `<root>/<traversal suffix>` by FileScanner — so basename(root) is a
 * deterministic, host-independent repo label (the same property
 * PathCanonical guarantees for canonical paths: a checkout moved
 * anywhere keeps the same label, because only the final path
 * component is ever emitted).
 *
 * Labels are RUN CONTEXT, not content facts, and this class treats
 * them that way: every block is stamped fresh from the current
 * config paths on every run, in both consumption funnels
 * (PreprocessStage for blocks, ReportStage for cluster members — the
 * latter covers cluster-cache replay, whose cached members would
 * otherwise carry the roots of the run that saved them). Nothing
 * label-shaped is ever persisted, which is why no IndexStore
 * PARSER_VERSION bump was needed: stale `.idx` blobs restore with
 * `''` defaults that the stamping pass always overwrites before any
 * consumer reads them.
 *
 * A file that matches no configured root (a path handed as its own
 * root, a synthetic test block) keeps `''` — the honest fallback;
 * scope arithmetic counts only non-empty labels as repos.
 */
final class RepoLabels
{
    /** Scope value when the run had a single scan root. */
    public const SCOPE_SINGLE = 'single';

    private function __construct()
    {
    }

    /**
     * Repo label of a scanned file: basename of the longest matching
     * scan root, '' when no root matches.
     *
     * @param list<string> $roots raw configured scan roots (same form
     *                            PathCanonical strips: literal, as passed)
     */
    public static function labelOf(string $file, array $roots): string
    {
        $bestLen = 0;
        $best    = '';
        foreach ($roots as $root) {
            $trimmed = rtrim($root, '/');
            if ($trimmed === '') {
                continue;
            }
            $prefix = $trimmed . '/';
            $len    = strlen($prefix);
            if ($len > $bestLen && str_starts_with($file, $prefix)) {
                $bestLen = $len;
                $best    = basename($trimmed);
            }
        }
        if ($best !== '') {
            return $best;
        }

        // A root naming a single file: the path IS the root — label it
        // by basename, mirroring PathCanonical's file-root case.
        foreach ($roots as $root) {
            if ($file === $root || $file === rtrim($root, '/')) {
                return basename($root);
            }
        }

        return '';
    }

    /**
     * Stamp $block's repo + scope from the current run's roots. Called
     * on every block of every run — cache-restored and freshly
     * extracted alike — so a label observable anywhere downstream was
     * computed from THIS run's configuration.
     *
     * @param list<string> $roots
     */
    public static function stamp(Block $block, array $roots): void
    {
        $repo = self::labelOf($block->file, $roots);

        $block->repo   = $repo;
        $block->scope  = count($roots) <= 1 ? self::SCOPE_SINGLE : $repo;
    }

    /**
     * @param iterable<Block>       $blocks
     * @param list<string>          $roots
     */
    public static function stampAll(iterable $blocks, array $roots): void
    {
        foreach ($blocks as $block) {
            self::stamp($block, $roots);
        }
    }
}
