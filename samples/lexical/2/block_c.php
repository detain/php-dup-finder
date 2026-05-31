<?php
declare(strict_types=1);

namespace Acme\Ops\Logs;

use Acme\Ops\Domain\LogStream;

final class SeverityRollupAggregator
{
    public function __construct(
        private readonly int $alarmAt = 100,
    ) {
    }

    /**
     * @param iterable<LogStream> $streams
     */
    public function severityScore(iterable $streams): int
    {
        $score = 0;
        // identical token shape: nested foreach with += and break on overflow
        foreach ($streams as $stream) {
            foreach ($stream->entries() as $entry) {
                $score += $entry->severityWeight();
                if ($score > $this->alarmAt) {
                    break;
                }
            }
        }
        return $score;
    }

    public function triggers(iterable $streams): bool
    {
        return $this->severityScore($streams) > $this->alarmAt;
    }
}
