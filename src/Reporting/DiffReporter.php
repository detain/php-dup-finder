<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;
use Phpdup\Refactor\HoleCoverage;
use Phpdup\Util\ControlText;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

/**
 * Per-cluster unified diffs showing how members differ from the cluster's
 * canonical template ({@see HoleCoverage::canonicalTemplate()} — the medoid
 * when recorded, else member[0]).
 *
 * Helps reviewers see the duplication at a glance, then judge whether the
 * suggested abstraction (shown at the top of each diff as a comment header)
 * actually captures the variation.
 */
final class DiffReporter
{
    public function writeDir(Report $report, string $dir): void
    {
        // output-011: a newline-bearing scanned path would forge standalone
        // `--- /dev/null` / `+++ b/…` / `@@ … @@` lines inside every .diff
        // written below. The whole surface refuses BEFORE anything is
        // created (ScriptKindApplyRefusal posture — a partial artifact set
        // masquerading as the complete one is the misfeed class).
        $this->requireStreamSafe($report);

        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }
        foreach ($report->clusters as $cluster) {
            if (count($cluster->members) < 2) {
                continue;
            }
            $safeId = Slug::safe($cluster->id);
            $file = $dir . DIRECTORY_SEPARATOR . $safeId . '.diff';
            file_put_contents($file, $this->buildClusterDiff($cluster));
        }
    }

    public function writePatch(Report $report, string $file): void
    {
        // output-011: same boundary law as writeDir — refuse the whole
        // stream before the parent directory is even created.
        $this->requireStreamSafe($report);

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
        // Item 5.4: the anchor is the canonical template — the cluster's
        // medoid center when one was recorded (SCORE-1), not the accidental
        // members[0] of ranker order. (The medoid is the cluster's
        // representative; AntiUnifier's own seed may differ — seed identity
        // is deliberately not persisted.) Diffing against the representative
        // is what makes the "suggested abstraction" header honest.
        $base       = HoleCoverage::canonicalTemplate($cluster) ?? $cluster->members[0];
        $baseSource = self::sourceOf($base);
        $differ     = new Differ(new UnifiedDiffOutputBuilder("--- a/{$base->file}\n+++ b/{$base->file}\n", false));

        $header = "# phpdup cluster {$cluster->id}\n"
                . sprintf("#   members: %d, similarity: %.2f, impact: %d\n", count($cluster->members), $cluster->similarity, $cluster->impact)
                . "#\n# Suggested abstraction:\n"
                . $this->indent((string)$cluster->signature, '#   ')
                . "\n#\n# Anchor ("
                . ($cluster->medoidId !== null && $base->id === $cluster->medoidId ? 'medoid' : 'members[0] fallback')
                . "): {$base->file}:{$base->range->start}-{$base->range->end}\n\n";

        $diffs = [];
        foreach ($cluster->members as $i => $other) {
            if ($other === $base) {
                continue;
            }
            $otherSource = self::sourceOf($other);
            $diff        = $differ->diff($baseSource, $otherSource);
            $diffs[] = sprintf(
                "## member[%d]: %s:%d-%d\n%s",
                $i, $other->file, $other->range->start, $other->range->end, $diff,
            );
        }

        return $header . implode("\n", $diffs);
    }

    /**
     * Read a block's source lines verbatim. Public static so the JSON
     * reporter's evidence keys render the exact same bytes this diff does
     * (one reader, no drift between report surfaces).
     */
    public static function sourceOf(Block $block): string
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

    /**
     * Boundary guard (output-011): every member file path is about to be
     * embedded raw in a diff header (`--- a/{file}` / `+++ b/{file}`) and in
     * the `## member[i]: {file}:{range}` anchors. Control bytes there are not
     * cosmetic — they forge whole stream lines. Reject the report with a
     * named diagnostic instead of emitting a patch a maintainer cannot
     * distinguish from an attacker's.
     */
    private function requireStreamSafe(Report $report): void
    {
        foreach ($report->clusters as $cluster) {
            foreach ($cluster->members as $member) {
                ControlText::reject($member->file, 'DiffReporter', 'member file path');
            }
        }
    }

    private function indent(string $text, string $prefix): string
    {
        return implode("\n", array_map(static fn(string $l) => $prefix . $l, explode("\n", trim($text, "\n"))));
    }
}
