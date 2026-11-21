<?php
declare(strict_types=1);

namespace Phpdup\Reporting;

/**
 * Wall-clock window of the scan that produced a {@see Report} (once-S59).
 *
 * Carried as pipeline metadata on the Report so timestamp-emitting reporters
 * (GitLab SAST today) never read the clock at build time: build() output for
 * a given Report is then a pure function of the Report payload, and repeated
 * runs over an identical Report are byte-identical. A non-zero duration is
 * preserved at microsecond resolution, so start/end collapse to the same
 * instant only when the window's duration is truly zero.
 *
 * Producers that never measured a run — the library API, server workers,
 * hand-built test reports — pass no window at all; reporters then omit the
 * timestamp fields honestly instead of fabricating wall-clock values.
 */
final class ScanWindow
{
    /**
     * @param float $startedAt Epoch seconds (UTC), {@see microtime()} semantics.
     * @param float $endedAt   Epoch seconds (UTC); never before $startedAt.
     */
    public function __construct(
        public readonly float $startedAt,
        public readonly float $endedAt,
    ) {
        if ($endedAt < $startedAt) {
            throw new \InvalidArgumentException(sprintf(
                'ScanWindow ends before it starts: %.6F < %.6F',
                $endedAt,
                $startedAt,
            ));
        }
    }

    public function durationSeconds(): float
    {
        return $this->endedAt - $this->startedAt;
    }
}
