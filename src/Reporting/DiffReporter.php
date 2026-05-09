<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

/**
 * Per-cluster unified diffs showing how members differ from cluster member[0].
 *
 * Helps reviewers see the duplication at a glance, then judge whether the
 * suggested abstraction (shown at the top of each diff as a comment header)
 * actually captures the variation.
 */
final class DiffReporter
{
    public function writeDir(Report $report, string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }
        foreach ($report->clusters as $cluster) {
            if (count($cluster->members) < 2) {
                continue;
            }
            $file = $dir . DIRECTORY_SEPARATOR . $cluster->id . '.diff';
            file_put_contents($file, $this->buildClusterDiff($cluster));
        }
    }

    public function writePatch(Report $report, string $file): void
    {
        $parentDir = dirname($file);
        if ($parentDir !== '' && !is_dir($parentDir)) {
            @mkdir($parentDir, 0o775, true);
        }
        $parts = [];
        foreach ($report->clusters as $cluster) {
            if (count($cluster->members) < 2) {
                continue;
            }
            $parts[] = $this->buildClusterDiff($cluster);
        }
        file_put_contents($file, implode("\n", $parts));
    }

    private function buildClusterDiff(Cluster $cluster): string
    {
        $base       = $cluster->members[0];
        $baseSource = $this->blockSource($base);
        $differ     = new Differ(new UnifiedDiffOutputBuilder("--- a/{$base->file}\n+++ b/{$base->file}\n", false));

        $header = "# phpdup cluster {$cluster->id}\n"
                . sprintf("#   members: %d, similarity: %.2f, impact: %d\n", count($cluster->members), $cluster->similarity, $cluster->impact)
                . "#\n# Suggested abstraction:\n"
                . $this->indent((string)$cluster->signature, '#   ')
                . "\n#\n# Anchor (member[0]): {$base->file}:{$base->range->start}-{$base->range->end}\n\n";

        $diffs = [];
        for ($i = 1; $i < count($cluster->members); $i++) {
            $other       = $cluster->members[$i];
            $otherSource = $this->blockSource($other);
            $diff        = $differ->diff($baseSource, $otherSource);
            $diffs[] = sprintf(
                "## member[%d]: %s:%d-%d\n%s",
                $i, $other->file, $other->range->start, $other->range->end, $diff,
            );
        }

        return $header . implode("\n", $diffs);
    }

    private function blockSource(Block $block): string
    {
        if (!is_file($block->file)) {
            return "<source unavailable: {$block->file}>";
        }
        $lines = @file($block->file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return "<unreadable: {$block->file}>";
        }
        $start = max(0, $block->range->start - 1);
        $end   = min(count($lines), $block->range->end);
        return implode("\n", array_slice($lines, $start, $end - $start)) . "\n";
    }

    private function indent(string $text, string $prefix): string
    {
        return implode("\n", array_map(static fn(string $l) => $prefix . $l, explode("\n", trim($text, "\n"))));
    }
}
