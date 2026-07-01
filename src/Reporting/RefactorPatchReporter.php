<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

use Phpdup\Clustering\Cluster;
use Phpdup\Extraction\Block;

/**
 * Emits one .patch file per cluster — a heuristic, **manual-review-required**
 * unified diff that:
 *
 *   1. Adds a new file `Refactored/<ClusterId>.php` containing the suggested
 *      abstraction signature plus a `// TODO: implement` body.
 *   2. Replaces each member's body with a comment pointing at the new
 *      abstraction (so the diff is reviewable, not blindly applicable).
 *
 * Bails to a "manual review" header when:
 *   - the cluster has no signature (anti-unification didn't yield one), or
 *   - any member uses `$this`/`self::`/`static::` / closure capture / yield
 *     (mechanical replacement is unsafe — the abstraction would lose context).
 *
 * The reporter is intentionally conservative — its goal is to give
 * reviewers a starting point, not to ship working refactors.
 */
final class RefactorPatchReporter
{
    /**
     * Write one `.patch` file per cluster under `$dir`.
     *
     * @param Report $report
     * @param string $dir Output directory
     * @param bool $apply When true, write a single cumulative `apply.diff` containing
     *                    all cluster patches concatenated (F1a scaffold; F1b adds actual rewrite)
     * @param bool $dryRun When true with apply=true: write apply.diff only (F1a behavior).
     *                    When false with apply=true: perform actual file extraction (F1b).
     *                    In F1a, this parameter has no effect since F1b isn't implemented.
     */
    public function writeTo(Report $report, string $dir, bool $apply = false, bool $dryRun = true): void
    {
        if ($dir !== '' && !is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }

        if ($apply) {
            if ($dryRun) {
                // F1a / preview mode: write apply.diff containing scaffold patches
                $parts = [];
                foreach ($report->clusters as $cluster) {
                    if (count($cluster->members) < 2) {
                        continue;
                    }
                    $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $cluster->id);
                    $parts[] = $this->buildPatch($cluster, $safeId);
                }
                file_put_contents($dir . DIRECTORY_SEPARATOR . 'apply.diff', implode("\n", $parts));
            } else {
                // F1b / actual apply mode: extract and write real files
                // TODO: implement actual file extraction when F1b is built
                $parts = [];
                foreach ($report->clusters as $cluster) {
                    if (count($cluster->members) < 2) {
                        continue;
                    }
                    $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $cluster->id);
                    $parts[] = $this->buildPatch($cluster, $safeId);
                }
                file_put_contents($dir . DIRECTORY_SEPARATOR . 'apply.diff', implode("\n", $parts));
            }
            return;
        }

        foreach ($report->clusters as $cluster) {
            if (count($cluster->members) < 2) {
                continue;
            }
            $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $cluster->id);
            $file = $dir . DIRECTORY_SEPARATOR . $safeId . '.patch';
            file_put_contents($file, $this->buildPatch($cluster, $safeId));
        }
    }

    /**
     * Build the textual patch for one cluster.
     *
     * The patch is intentionally non-applicable: it adds an
     * abstraction skeleton plus per-member hint comments so a human
     * reviewer can wire up the call sites themselves.
     */
    public function buildPatch(Cluster $cluster, ?string $safeId = null): string
    {
        $safeId ??= preg_replace('/[^a-zA-Z0-9_-]/', '_', $cluster->id);
        $unsafe = $this->detectUnsafe($cluster);
        $header = "# phpdup refactor patch — cluster {$cluster->id}\n"
                . "# members: " . count($cluster->members)
                . ", impact: {$cluster->impact}, confidence: " . sprintf('%.2f', $cluster->confidence) . "\n";
        if ($unsafe !== null) {
            return $header
                 . "# *** MANUAL REVIEW REQUIRED ***\n"
                 . "# Mechanical replacement is unsafe: {$unsafe}\n"
                 . "# Use the diff/HTML reports to inspect each member, then\n"
                 . "# craft the abstraction by hand.\n";
        }
        if ($cluster->signature === null) {
            return $header . "# No signature synthesised — skip.\n";
        }

        $abstractionFile = "Refactored/{$safeId}.php";
        $patch = $header
               . "diff --git a/{$abstractionFile} b/{$abstractionFile}\n"
               . "new file mode 100644\n"
               . "--- /dev/null\n"
               . "+++ b/{$abstractionFile}\n"
               . "@@ -0,0 +1,12 @@\n"
               . "+<?php\n"
               . "+declare(strict_types=1);\n"
               . "+\n"
               . "+namespace Refactored;\n"
               . "+\n"
               . "+/**\n"
               . "+ * Auto-generated abstraction for phpdup cluster {$cluster->id}.\n"
               . "+ * Signature inferred via anti-unification — REVIEW BEFORE MERGE.\n"
               . "+ */\n"
               . $this->prefixLines($cluster->signature, '+')
               . "\n"
               . "+{\n"
               . "+    // TODO: implement using the bodies of members listed above\n"
               . "+}\n";

        // Per-member edit hints — replace body with a single call comment.
        foreach ($cluster->members as $i => $member) {
            $patch .= $this->memberHint($member, $cluster->id, $i);
        }
        return $patch;
    }

    /**
     * @return string|null A short reason why the cluster cannot be
     *         auto-patched, or null when mechanical replacement is OK.
     */
    private function detectUnsafe(Cluster $cluster): ?string
    {
        foreach ($cluster->members as $m) {
            $src = $this->memberSource($m);
            if ($src === null) {
                continue;
            }
            if (str_contains($src, '$this->')) {
                return 'member uses $this — needs context-aware extraction';
            }
            if (preg_match('/\b(self|static|parent)::/', $src) === 1) {
                return 'member uses self::/static::/parent:: — class-bound';
            }
            if (str_contains($src, 'yield')) {
                return 'member is a generator (yield)';
            }
            if (preg_match('/\bfunction\s*\(/', $src) === 1) {
                return 'member declares a closure (capture analysis required)';
            }
        }
        return null;
    }

    /**
     * Render a unified-diff hint pointing at one cluster member.
     */
    private function memberHint(Block $member, string $clusterId, int $idx): string
    {
        $loc = "{$member->file}:{$member->range->start}-{$member->range->end}";
        return "\n# member[{$idx}] @ {$loc}\n"
             . "#   replace body with: return \\Refactored\\f_{$clusterId}(...);\n";
    }

    /**
     * Read the source bytes for a member's range, returning null when
     * the file is unreadable or no longer exists.
     */
    private function memberSource(Block $member): ?string
    {
        if (!is_file($member->file)) {
            return null;
        }
        $lines = @file($member->file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return null;
        }
        $start = max(0, $member->range->start - 1);
        $end   = min(count($lines), $member->range->end);
        return implode("\n", array_slice($lines, $start, $end - $start));
    }

    /**
     * Prefix every line of `$text` with `$prefix` (used to render `+`
     * lines for the synthetic diff).
     */
    private function prefixLines(string $text, string $prefix): string
    {
        $out = '';
        foreach (explode("\n", $text) as $line) {
            $out .= $prefix . $line . "\n";
        }
        return rtrim($out, "\n");
    }
}
