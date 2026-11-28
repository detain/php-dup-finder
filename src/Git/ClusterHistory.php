<?php
declare(strict_types=1);

namespace Phpdup\Git;

/**
 * Item 4.2 — the git-history correlation sidecar carried by a Report.
 *
 * Purely a value object: the analyzer that produces it already resolved
 * every verdict, so reporters never re-run git. The presence of this
 * object on a Report is what arms the conditional `summary.cluster_history`
 * JSON key; a null carrier keeps every byte of the default output as it
 * was before item 4.2 existed (opt-in law: zero golden/cache churn).
 */
final class ClusterHistory
{
    /**
     * @param list<array{cluster: string, verdict: string, reason: string|null, members: list<array{file: string, last_sha: string|null, last_date: string|null, lone: array{sha: string, date: string, subject: string, fix: bool}|null}>, co_change: list<array{a: string, b: string, times: int}>}> $rows
     * @param list<string> $notes human-readable posture notes (skip notes family)
     */
    public function __construct(
        public readonly array $rows,
        public readonly int $windowMonths,
        public readonly int $omitted,
        public readonly array $notes,
    ) {
    }
}
