<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

/**
 * Centralized severity determination for duplicate clusters.
 *
 * This class is the single source of truth for all severity labeling in phpdup.
 * Every reporter that emits severity information must route through these
 * methods rather than duplicating label or threshold logic at the call site
 * (php-dup-finder-output-037).  The consumer roster below is kept in sync with
 * `grep -rn "Severity::" src/Reporting/`:
 *
 * - `SarifReporter` — `sarifLevel()` per result, `sarifRuleDefaultLevel()` for rule metadata
 * - `CheckstyleReporter` — `checkstyleSeverity()` per `<error>` element
 * - `GitLabSastReporter` — `forImpact()` (severity) and `forScore()` (confidence)
 * - `GraphvizReporter` — `forImpact()` when colouring nodes by severity
 * - `CliReporter` — `forScore()` for confidence badges
 *
 * Three orthogonal label scales are provided:
 *
 * - **Impact scale** (`forImpact`) — integer thresholds producing SAST-style
 *   labels (High/Medium/Low/Info). Used when severity must conform to an
 *   industry-standard format consumed via that vocabulary.
 *
 * - **Score scale** (`forScore`) — float in [0.0, 1.0], calibrated for the CLI
 *   confidence display. Used for human-oriented output where format-standard
 *   alignment is not required.
 *
 * - **Wire-label routing** (`sarifLevel`, `sarifRuleDefaultLevel`,
 *   `checkstyleSeverity`) — per-format labels driven by a cluster's
 *   exactness, byte-for-byte preserving the mapping both reporters carried
 *   inline before it was centralized here.
 */
final class Severity
{
    /**
     * Maps an impact value to a SAST-style severity label.
     *
     * The thresholds follow the GitLab SAST severity scale: phpdup does not
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
     * Format alignment:
     *   These labels are consumed by GitLabSastReporter (SAST `severity`
     *   field) and GraphvizReporter (node colours); GitLab SAST folds them
     *   onto its own severity pipeline, and the phpdup Info tier is kept for
     *   completeness.  They are NOT what SARIF results carry: SarifReporter
     *   routes its `level` through sarifLevel() below, which keys on cluster
     *   exactness rather than impact.
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

    /**
     * SARIF `level` for a cluster result, routed from cluster exactness.
     *
     * SARIF defines three result levels — `error`, `warning`, `note` — and
     * phpdup never emits `error` (CI integrations treat it as build-breaking,
     * which duplicate logic is not), so an exact clone lands on `warning` and
     * a near-duplicate on `note`.  This routing is deliberately independent of
     * the impact scale: the SARIF surface has always keyed on match certainty,
     * not on how many lines are affected.
     *
     * The mapping is byte-frozen against the inline expression it replaces
     * (pre-output-037 SarifReporter: `$cluster->exact ? 'warning' : 'note'`);
     * SeverityTest pins the literals independently of this method.
     *
     * @param bool $exact  Whether the cluster is an exact clone.
     *
     * @return string      One of: 'warning' | 'note'
     */
    public static function sarifLevel(bool $exact): string
    {
        return $exact ? 'warning' : 'note';
    }

    /**
     * SARIF rule-metadata `defaultConfiguration.level` — the level a viewer
     * applies to results that omit their own `level`.  phpdup results always
     * carry an explicit sarifLevel(), so this value never routes a real
     * finding; it lives here so even the static default cannot drift from the
     * center's SARIF vocabulary.  It mirrors the label phpdup gives its
     * strongest results (the exact tier).
     *
     * @return string  'warning'
     */
    public static function sarifRuleDefaultLevel(): string
    {
        return 'warning';
    }

    /**
     * Checkstyle `<error severity="...">` label for a cluster.
     *
     * The Checkstyle DTD allows error | warning | info; phpdup never emits
     * `error` for a duplicate finding, mirroring the SARIF posture, so an
     * exact clone lands on `warning` and a near-duplicate on `info`.
     * Byte-frozen against the inline expression it replaces
     * (pre-output-037 CheckstyleReporter: `$cluster->exact ? 'warning' : 'info'`).
     *
     * @param bool $exact  Whether the cluster is an exact clone.
     *
     * @return string      One of: 'warning' | 'info'
     */
    public static function checkstyleSeverity(bool $exact): string
    {
        return $exact ? 'warning' : 'info';
    }
}
