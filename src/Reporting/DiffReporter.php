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
 *
 * output-038: source bytes are read through the per-run
 * {@see SourceTextStore} (single read path, mtime/size revalidation,
 * symlink confinement), and an unfetchable member is stated as an
 * explicit "# source not read (reason)" metadata row — the old
 * `<source unavailable: …>` / `<unreadable: …>` strings are gone, they
 * used to ride INSIDE the diff payload as fabricated hunk bytes (and
 * leak into the JSON evidence surface that shares this reader).
 */
final class DiffReporter
{
    public function __construct(private readonly ?SourceTextStore $sourceText = null) {}

    public function writeDir(Report $report, string $dir): void
    {
        // output-011: a newline-bearing scanned path would forge standalone
        // `--- /dev/null` / `+++ b/…` / `@@ … @@` lines inside every .diff
        // written below. The whole surface refuses BEFORE anything is
        // created (ScriptKindApplyRefusal posture — a partial artifact set
        // masquerading as the complete one is the misfeed class).
        $this->requireStreamSafe($report);

        $store = $this->sourceText ?? new SourceTextStore($report->config->paths);

        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }
        foreach ($report->clusters as $cluster) {
            if (count($cluster->members) < 2) {
                continue;
            }
            $safeId = Slug::safe($cluster->id);
            $file = $dir . DIRECTORY_SEPARATOR . $safeId . '.diff';
            file_put_contents($file, $this->buildClusterDiff($cluster, $store));
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
        $store = $this->sourceText ?? new SourceTextStore($report->config->paths);
        $parts = [];
        foreach ($report->clusters as $cluster) {
            if (count($cluster->members) < 2) {
                continue;
            }
            $parts[] = $this->buildClusterDiff($cluster, $store);
        }
        file_put_contents($file, implode("\n", $parts));
    }

    private function buildClusterDiff(Cluster $cluster, SourceTextStore $store): string
    {
        // Item 5.4: the anchor is the canonical template — the cluster's
        // medoid center when one was recorded, else members[0]. (The medoid
        // is the cluster's representative; AntiUnifier's own seed may differ
        // — seed identity is deliberately not persisted.) Diffing against
        // the representative is what makes the "suggested abstraction"
        // header honest.
        $base       = HoleCoverage::canonicalTemplate($cluster) ?? $cluster->members[0];
        $baseSource = self::sourceOf($base, $store);
        $differ     = new Differ(new UnifiedDiffOutputBuilder("--- a/{$base->file}\n+++ b/{$base->file}\n", false));

        // output-035 (S6): the comment-header rows below are metadata — they
        // carry cluster name, signature text and member paths. Those values
        // pass through CsvReporter::defuseFormula. The unified-diff payload
        // itself (`--- a/`/`+++ b/` pair fed to the Differ, and every hunk
        // byte it renders) is parser-consumed structure and stays raw —
        // defusing it would corrupt the diff.
        $header = "# phpdup cluster " . CsvReporter::defuseFormula($cluster->id) . "\n"
                . sprintf("#   members: %d, similarity: %.2f, impact: %d\n", count($cluster->members), $cluster->similarity, $cluster->impact)
                . "#\n# Suggested abstraction:\n"
                . $this->indent(CsvReporter::defuseFormula((string)$cluster->signature), '#   ')
                . "\n#\n# Anchor ("
                . ($cluster->medoidId !== null && $base->id === $cluster->medoidId ? 'medoid' : 'members[0] fallback')
                . "): " . CsvReporter::defuseFormula($base->file) . ":{$base->range->start}-{$base->range->end}\n";

        // output-038: an unfetchable anchor is now an explicit posture row
        // rather than fabricated hunk bytes — with the base unreadable there
        // is no "before" side, so every member diff is replaced by a
        // statement of what could not be read, and the stream stays parseable.
        if ($baseSource === null) {
            return $header . "#   " . SourceTextStore::UNFETCHED
                 . ' (' . $this->reasonOf($store, $base) . ")\n";
        }
        $header .= "\n";

        $diffs = [];
        foreach ($cluster->members as $i => $other) {
            if ($other === $base) {
                continue;
            }
            $otherSource = self::sourceOf($other, $store);
            if ($otherSource === null) {
                $diffs[] = sprintf(
                    "## member[%d]: %s:%d-%d\n#   %s (%s)\n",
                    $i,
                    CsvReporter::defuseFormula($other->file),
                    $other->range->start,
                    $other->range->end,
                    SourceTextStore::UNFETCHED,
                    $this->reasonOf($store, $other),
                );
                continue;
            }
            $diff    = $differ->diff($baseSource, $otherSource);
            $diffs[] = sprintf(
                "## member[%d]: %s:%d-%d\n%s",
                $i, CsvReporter::defuseFormula($other->file), $other->range->start, $other->range->end, $diff,
            );
        }

        return $header . implode("\n", $diffs);
    }

    /**
     * Read a block's source lines verbatim, or null when the store refused
     * the read (missing, unreadable, or out-of-root via a planted symlink).
     * The shared per-run store keeps one read path for every report surface
     * (output-038): the JSON reporter's evidence keys render the exact same
     * bytes this diff does — and the same honest "not read" posture.
     */
    public static function sourceOf(Block $block, ?SourceTextStore $store = null): ?string
    {
        $lines = ($store ?? new SourceTextStore())->lines($block->file);
        if ($lines === null) {
            return null;
        }
        $start = max(0, $block->range->start - 1);
        $end   = min(count($lines), $block->range->end);
        return implode("\n", array_slice($lines, $start, $end - $start)) . "\n";
    }

    private function reasonOf(SourceTextStore $store, Block $block): string
    {
        return $store->reason($block->file) ?? 'unavailable';
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
