<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

/**
 * Centralized severity determination for duplicate clusters.
 *
 * This class is the single source of truth for all severity labeling in phpdup.
 * Every reporter that emits severity information — `GitLabSastReporter`,
 * `GraphvizReporter`, `CliReporter`, and any future consumer — must route through
 * these two methods rather than duplicating threshold logic.
 *
 * Two orthogonal scales are provided:
 *
 * - **Impact scale** (`forImpact`) — integer, calibrated for GitLab SAST / SARIF
 *   compatibility. Used when severity must conform to an industry-standard format.
 *
 * - **Score scale** (`forScore`) — float in [0.0, 1.0], calibrated for the CLI
 *   confidence display. Used for human-oriented output where SARIF alignment is
 *   not required.
 *
 * @see GitLabSastReporter  — SARIF/SAST consumer that calls forImpact()
 * @see GraphvizReporter   — calls forImpact() when rendering node severity
 * @see CliReporter         — calls forScore() for confidence badges
 */
final class Severity
{
    /**
     * Maps an impact value to a SARIF-compatible severity label.
     *
     * The thresholds follow the GitLab SAST / OASIS SARIF severity scale, which
     * defines four levels: Critical > High > Medium > Low > Info.  phpdup does not
     * emit Critical (reserved for security vulnerabilities with CVSS ≥ 9.0), so
     * the top tier is High.
     *
     * Threshold rationale (conservative — prefers lower labels to avoid false
     * positives in CI):
     *
     *   > 100 → High
     *     Duplicate clones that account for more than 100 lines of effectively
     *     identical code.  High-impact clusters are worth immediate attention.
     *
     *   ≥ 50  → Medium
     *     Clusters spanning roughly 50–100 lines.  These represent meaningful
     *     duplication that should be scheduled for refactoring but do not meet
     *     the threshold for CI gate failure at High severity.
     *
     *   ≥ 20  → Low
     *     Clusters of approximately 20–49 lines.  Minor duplication patterns
     *     that are acknowledged but not actioned automatically.
     *
     *   < 20  → Info
     *     Everything below 20 lines is informational — short repetitive idioms
     *     (e.g. identical 3-line error-handling snippets) that do not warrant a
     *     CI warning.
     *
     * SARIF alignment:
     *   SARIF `warning` level corresponds to Medium/Low; `note` corresponds to
     *   Info.  High maps directly to SARIF `error`.  GitLab SAST maps these four
     *   labels onto its own 3-tier pipeline (High / Medium / Low), but the
     *   phpdup Info tier is included for completeness and is emitted as a note
     *   in SARIF output.
     *
     * @param int $impact  Raw impact score (total duplicated line count across
     *                     all members of a cluster, before normalisation).  Must
     *                     be non-negative; no upper bound.
     *
     * @return string      One of: 'High' | 'Medium' | 'Low' | 'Info'
     */
    public static function forImpact(int $impact): string
    {
        return match (true) {
            $impact > 100 => 'High',
            $impact >= 50 => 'Medium',
            $impact >= 20 => 'Low',
            default       => 'Info',
        };
    }

    /**
     * Maps a confidence / safety score (0.0 – 1.0) to a CLI severity label.
     *
     * This scale is used exclusively for human-facing output (primarily
     * `CliReporter`) where SARIF compliance is not required.  It encodes how
     * certain the similarity engine is that a detected cluster represents genuine
     * duplication rather than a false positive.
     *
     * Threshold rationale (conservative — phpdup is a tooling aid, not a security
     * scanner, so it errs toward lower severity to avoid alarm fatigue):
     *
     *   ≥ 0.85 → High
     *     Very high confidence (≥ 85 %).  The cluster is almost certainly a
     *     real duplicate; the CLI renders this with a prominent badge and it
     *     sorts to the top of the ranked output.
     *
     *   ≥ 0.65 → Medium
     *     Moderate confidence (65–84 %).  Likely genuine but with some
     *     structural variance (e.g. renamed variables, reordered statements).
     *     Displayed with a standard warning badge.
     *
     *   < 0.65 → Low
     *     Lower confidence.  May be a false positive or a coincidental structural
     *     similarity.  Shown with an info/low badge; useful for triage.
     *
     * These thresholds intentionally differ from the SARIF impact scale because
     * they measure different things: impact answers "how much code is affected?"
     * while score answers "how sure are we that this is duplication?"
     *
     * @param float $score  Confidence / safety score in the range [0.0, 1.0].
     *                      Values outside this range are clamped: scores > 1.0
     *                      are treated as 1.0; scores < 0.0 are treated as 0.0.
     *
     * @return string       One of: 'High' | 'Medium' | 'Low'
     */
    public static function forScore(float $score): string
    {
        // Clamp to valid range before matching.
        $score = match (true) {
            $score > 1.0 => 1.0,
            $score < 0.0 => 0.0,
            default      => $score,
        };

        return match (true) {
            $score >= 0.85 => 'High',
            $score >= 0.65 => 'Medium',
            default        => 'Low',
        };
    }
}
