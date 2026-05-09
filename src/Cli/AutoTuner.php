<?php
declare(strict_types=1);

namespace Phpdup\Cli;

use Phpdup\Scanning\FileScanner;

/**
 * Auto-tunes detection parameters from a quick file-tree probe.
 *
 * Counts files and (rough-) total size before any AST work, then maps
 * the corpus shape to a small decision tree:
 *
 *   - tiny     (<200 files)       — relaxed thresholds, surface fixtures
 *                                    and toy projects don't get drowned
 *                                    by min-impact / max-df defaults.
 *   - small    (<2_000 files)     — defaults (no overrides).
 *   - medium   (<20_000 files)    — tighter max-df (uniqueness scales),
 *                                    bumped min-block-size.
 *   - large    (>=20_000 files)   — exact-only suggestion + much tighter
 *                                    thresholds; refusing to OOM is the
 *                                    right call here.
 *
 * Returns a {@see Suggestion} value object with the override dict and a
 * human-readable rationale string the CLI can print.
 *
 * Pure / side-effect-free aside from filesystem stat() calls — no AST
 * parsing happens during the probe, so it stays cheap on huge corpora.
 */
final class AutoTuner
{
    public function __construct(
        private readonly int $smallMax  = 2_000,
        private readonly int $mediumMax = 20_000,
        private readonly int $tinyMax   = 200,
    ) {
    }

    /**
     * @param list<string> $paths
     * @param list<string> $excludeGlobs
     */
    public function tune(array $paths, array $excludeGlobs): Suggestion
    {
        $totalFiles = 0;
        $totalBytes = 0;
        foreach ($paths as $root) {
            $scanner = new FileScanner($excludeGlobs);
            foreach ($scanner->scan($root) as $file) {
                $totalFiles++;
                $size = @filesize($file);
                if ($size !== false) {
                    $totalBytes += $size;
                }
            }
        }

        return $this->pick($totalFiles, $totalBytes);
    }

    public function pick(int $totalFiles, int $totalBytes): Suggestion
    {
        if ($totalFiles < $this->tinyMax) {
            return new Suggestion(
                profile: 'tiny',
                files:   $totalFiles,
                bytes:   $totalBytes,
                overrides: [
                    'min_block_size'      => 4,
                    'min_cluster_impact'  => 0,
                    'max_df'              => 0.5,
                ],
                rationale: sprintf(
                    'tiny corpus (%d files): relaxing min_block_size=4, min_impact=0, max_df=0.5 so small/fixture trees report findings instead of empty output.',
                    $totalFiles,
                ),
            );
        }
        if ($totalFiles < $this->smallMax) {
            return new Suggestion(
                profile: 'small',
                files:   $totalFiles,
                bytes:   $totalBytes,
                overrides: [],
                rationale: sprintf(
                    'small corpus (%d files): keeping defaults — they target the small/medium real-world band already.',
                    $totalFiles,
                ),
            );
        }
        if ($totalFiles < $this->mediumMax) {
            return new Suggestion(
                profile: 'medium',
                files:   $totalFiles,
                bytes:   $totalBytes,
                overrides: [
                    'min_block_size' => 8,
                    'max_df'         => 0.005,
                ],
                rationale: sprintf(
                    'medium corpus (%d files): tightening max_df=0.005 (n-gram uniqueness scales with corpus size) and min_block_size=8.',
                    $totalFiles,
                ),
            );
        }
        return new Suggestion(
            profile: 'large',
            files:   $totalFiles,
            bytes:   $totalBytes,
            overrides: [
                'min_block_size' => 12,
                'max_df'         => 0.002,
                'exact_only'     => true,
            ],
            rationale: sprintf(
                'large corpus (%d files): exact-only + min_block_size=12 + max_df=0.002 to keep memory in check; rerun without --auto-tune for full near-duplicate analysis when ready.',
                $totalFiles,
            ),
        );
    }
}
